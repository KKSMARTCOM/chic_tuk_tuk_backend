<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\OwnerDisbursement;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Spec 2026-10-22-versement-proprietaire, §4.5 et §5.2. */
class OwnerDisbursementsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Vehicle $vehicle;

    private VehicleContract $contract;

    private int $month = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 09:00:00');
        Storage::fake('local');
        $this->owner = User::factory()->profil(Profil::Owner)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $this->vehicle = Vehicle::factory()->create(['owner_id' => $this->owner->id]);
        $this->contract = $this->contractFor($this->owner, 100_000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contractFor(User $owner, float $earned): VehicleContract
    {
        $contract = VehicleContract::factory()->forVehicle($this->vehicle)->create(['owner_id' => $owner->id, 'start_date' => '2025-01-01']);
        RemunerationStatement::factory()->for($contract, 'contract')->create([
            'month' => sprintf('2026-%02d-01', $this->month++), 'status' => 'validated', 'balance_due' => $earned, 'number' => 'FR-'.uniqid(), 'figures' => [],
        ]);

        return $contract;
    }

    private function disbursement(array $attributes = [], ?VehicleContract $contract = null): OwnerDisbursement
    {
        return OwnerDisbursement::create($attributes + [
            'vehicle_contract_id' => ($contract ?? $this->contract)->id, 'paid_on' => '2026-10-20', 'amount' => 30_000,
            'method' => 'bank_transfer', 'reference' => 'VIR-42', 'note' => 'Interne', 'status' => 'recorded',
        ]);
    }

    private function asOwner(?User $owner = null): self
    {
        $owner ??= $this->owner;
        $owner->givePermissionTo(Permission::firstOrCreate(['name' => 'view-own-payments', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function read()
    {
        return $this->asOwner()->getJson("/api/v1/owner/vehicles/{$this->vehicle->id}/disbursements")->assertOk();
    }

    public function test_the_owner_sees_date_amount_and_proof_only(): void
    {
        $this->disbursement(['proof_path' => 'disbursements/x/recu.pdf']);

        $item = $this->read()->json('disbursements.0');

        $this->assertSame(['id', 'paid_on', 'amount', 'has_proof'], array_keys($item));
        $this->assertTrue($item['has_proof']);
    }

    public function test_cancelled_disbursements_are_hidden_from_the_owner(): void
    {
        $this->disbursement(['status' => 'cancelled', 'cancellation_reason' => 'Doublon', 'cancelled_at' => now()]);

        $this->read()->assertJsonCount(0, 'disbursements')->assertJsonPath('summary.disbursed', 0);
    }

    public function test_two_contracts_of_the_same_owner_add_up_and_a_former_owner_is_left_out(): void
    {
        $second = $this->contractFor($this->owner, 50_000);
        $this->disbursement([], $second);
        $former = User::factory()->profil(Profil::Owner)->create();
        $this->disbursement(['amount' => 9_000], $this->contractFor($former, 70_000));

        $this->read()
            ->assertJsonPath('summary.net_earned', 150_000)
            ->assertJsonPath('summary.disbursed', 30_000)
            ->assertJsonPath('summary.outstanding', 120_000)
            ->assertJsonCount(1, 'disbursements');
    }

    public function test_a_cancelled_statement_after_disbursements_shows_zero_to_the_owner_and_negative_to_admin(): void
    {
        $this->disbursement(['amount' => 80_000]);
        RemunerationStatement::query()->update(['status' => 'cancelled']);

        $this->read()->assertJsonPath('summary.outstanding', 0);

        $admin = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach (['view-disbursements', 'create-disbursements'] as $permission) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/vehicle-contracts/{$this->contract->id}/disbursements")
            ->assertJsonPath('summary.outstanding', -80_000);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/vehicle-contracts/{$this->contract->id}/disbursements", ['paid_on' => '2026-10-21', 'amount' => 1, 'method' => 'mobile_money'])
            ->assertStatus(422)->assertJsonPath('code', 'DISBURSEMENT_EXCEEDS_OUTSTANDING');
    }

    public function test_hidden_statements_hide_the_summary(): void
    {
        config(['remuneration.owner_visible' => false]);
        $this->disbursement();

        $this->read()->assertJsonPath('summary', null)->assertJsonPath('disbursements', null);
    }

    public function test_scope(): void
    {
        $other = User::factory()->profil(Profil::Owner)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $proof = $this->disbursement(['proof_path' => 'disbursements/x/recu.pdf']);
        Storage::disk('local')->put('disbursements/x/recu.pdf', 'pdf');
        $cancelled = $this->disbursement(['proof_path' => 'disbursements/x/recu.pdf', 'status' => 'cancelled', 'cancelled_at' => now()]);

        $this->asOwner($other)->getJson("/api/v1/owner/vehicles/{$this->vehicle->id}/disbursements")->assertNotFound();
        $this->asOwner($other)->get("/api/v1/owner/disbursements/{$proof->id}/proof")->assertNotFound();
        $this->asOwner()->get("/api/v1/owner/disbursements/{$cancelled->id}/proof")->assertNotFound();
        $this->asOwner()->get("/api/v1/owner/disbursements/{$proof->id}/proof")->assertOk();
    }

    public function test_the_admin_contract_detail_carries_the_summary(): void
    {
        $this->disbursement();
        $admin = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'view-contracts', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/vehicle-contracts/{$this->contract->id}")
            ->assertOk()
            ->assertJsonPath('disbursement_summary.outstanding', 70_000);
    }
}
