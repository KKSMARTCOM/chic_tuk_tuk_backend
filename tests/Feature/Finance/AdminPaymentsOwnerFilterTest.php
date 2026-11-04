<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * La liste des paiements filtrée sur un propriétaire, depuis son dossier (2026-11-04) : les
 * paiements de contrat qui lui reviennent, la règle de `total_paid` (`Payment::forOwner`).
 */
class AdminPaymentsOwnerFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view-payments', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function contractOf(User $owner, array $attributes = []): VehicleContract
    {
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);

        return VehicleContract::factory()->forVehicle($vehicle)->create($attributes);
    }

    public function test_payments_filter_by_owner_keeps_only_his_owner_payments(): void
    {
        $owner = User::factory()->profil(Profil::Owner)->create();
        $other = User::factory()->profil(Profil::Owner)->create();
        $running = $this->contractOf($owner);
        $settled = $this->contractOf($owner, ['status' => 'completed', 'start_date' => '2026-01-01', 'end_date' => '2026-08-31']);

        $mine = Payment::factory()->onDay('2026-10-01')->create(['vehicle_contract_id' => $running->id]);
        $beforeEnd = Payment::factory()->onDay('2026-08-28')->create(['vehicle_contract_id' => $settled->id]);
        Payment::factory()->onDay('2026-09-02')->create(['vehicle_contract_id' => $settled->id]); // agence
        Payment::factory()->onDay('2026-10-01')->create(['vehicle_contract_id' => $this->contractOf($other)->id]);
        Payment::factory()->onDay('2026-10-02')->create(['vehicle_contract_id' => $running->id, 'payment_type' => 'commission']);

        $ids = collect($this->api()->getJson("/api/v1/admin/payments?filter[owner_id]={$owner->id}")->assertOk()->json('payments'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$mine->id, $beforeEnd->id])->sort()->values()->all(), $ids);
    }

    public function test_an_empty_owner_filter_is_ignored(): void
    {
        Payment::factory()->onDay('2026-10-01')->create();

        $this->api()->getJson('/api/v1/admin/payments?filter[owner_id]=')->assertOk()->assertJsonCount(1, 'payments');
    }
}
