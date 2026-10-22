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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Spec 2026-10-22-versement-proprietaire, §4 et §5.1. */
class AdminDisbursementsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['view-disbursements', 'create-disbursements', 'edit-disbursements'];

    private VehicleContract $contract;

    private int $month = 7;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 09:00:00');
        Storage::fake('local');
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
        $owner = User::factory()->profil(Profil::Owner)->create();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => '2EV2338 RB']);
        $this->contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-07-01']);
        $this->statement(100_000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function statement(float $balance, string $status = 'validated', array $extra = []): RemunerationStatement
    {
        return RemunerationStatement::factory()->for($this->contract, 'contract')->create([
            'month' => sprintf('2026-%02d-01', $this->month++), 'status' => $status, 'balance_due' => $balance,
            'number' => $status === 'draft' ? null : 'FR-'.uniqid(), 'figures' => [],
        ] + $extra);
    }

    private function as(array $permissions = self::ALL): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['name' => 'Awa Dossou', 'password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function record(array $fields = [], array $permissions = self::ALL)
    {
        return $this->as($permissions)->post("/api/v1/admin/vehicle-contracts/{$this->contract->id}/disbursements",
            $fields + ['paid_on' => '2026-10-20', 'amount' => 50_000, 'method' => 'mobile_money'], ['Accept' => 'application/json']);
    }

    public function test_the_summary_counts_validated_statements_and_recorded_disbursements(): void
    {
        $this->statement(40_000, 'draft');
        $this->statement(30_000, 'cancelled');
        $this->statement(20_000, 'validated', ['delivery' => 'none']);
        OwnerDisbursement::create(['vehicle_contract_id' => $this->contract->id, 'paid_on' => '2026-10-01', 'amount' => 5_000, 'method' => 'mobile_money', 'status' => 'cancelled']);
        OwnerDisbursement::create(['vehicle_contract_id' => $this->contract->id, 'paid_on' => '2026-10-02', 'amount' => 15_000, 'method' => 'bank_transfer', 'status' => 'recorded']);

        $this->as()->getJson("/api/v1/admin/vehicle-contracts/{$this->contract->id}/disbursements")
            ->assertOk()
            ->assertJsonPath('summary.net_earned', 120_000)
            ->assertJsonPath('summary.disbursed', 15_000)
            ->assertJsonPath('summary.outstanding', 105_000)
            ->assertJsonCount(2, 'disbursements');
    }

    public function test_recording_up_to_the_outstanding_passes_and_beyond_is_refused(): void
    {
        $this->record(['amount' => 100_000])->assertOk()->assertJsonPath('summary.outstanding', 0);

        $this->record(['amount' => 0.01])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DISBURSEMENT_EXCEEDS_OUTSTANDING')
            ->assertJsonPath('outstanding', 0);
    }

    public function test_field_rules(): void
    {
        foreach ([
            'paid_on' => ['paid_on' => '2026-10-23'],
            'paid_on ' => ['paid_on' => '2026-06-30'],
            'amount' => ['amount' => 0],
            'amount ' => ['amount' => 10.555],
            'method' => ['method' => 'cash'],
            'proof' => ['proof' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ] as $field => $values) {
            $this->record($values)->assertStatus(422)->assertJsonValidationErrors(trim($field));
        }
        $this->assertSame(0, OwnerDisbursement::count());
    }

    public function test_recording_locks_the_vehicle_contract(): void
    {
        $locked = false;
        DB::listen(function ($query) use (&$locked) {
            if (str_contains($query->sql, '"vehicle_contracts"') && str_contains(strtolower($query->sql), 'for update')) {
                $locked = true;
            }
        });

        $this->record()->assertOk();

        $this->assertTrue($locked);
    }

    public function test_the_proof_is_stored_and_downloaded(): void
    {
        $response = $this->record(['proof' => UploadedFile::fake()->create('recu.pdf', 20, 'application/pdf')])->assertOk();
        $disbursement = OwnerDisbursement::sole();

        $this->assertTrue($response->json('disbursements.0.has_proof'));
        Storage::disk('local')->assertExists($disbursement->proof_path);
        $this->assertStringStartsWith("disbursements/{$this->contract->id}/", $disbursement->proof_path);
        $this->as()->get("/api/v1/admin/disbursements/{$disbursement->id}/proof")->assertOk();
    }

    public function test_permissions(): void
    {
        $this->record([], ['view-disbursements'])->assertForbidden();
        $this->as(['create-disbursements'])->getJson("/api/v1/admin/vehicle-contracts/{$this->contract->id}/disbursements")->assertForbidden();
    }

    public function test_the_journal_traces_the_recording(): void
    {
        $this->record(['amount' => 50_000])->assertOk();

        $entry = Activity::query()->where('event', 'payment.disbursement_recorded')->sole();
        $this->assertStringContainsString('2EV2338 RB', $entry->description);
        $this->assertStringContainsString('50 000', $entry->description);
    }
}
