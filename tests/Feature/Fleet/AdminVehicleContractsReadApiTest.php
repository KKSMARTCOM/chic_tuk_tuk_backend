<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** La liste et la fiche des contrats propriétaire-véhicule — ex-`pages.admin.contracts.owner*`. */
class AdminVehicleContractsReadApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_the_list_requires_view_contracts(): void
    {
        $this->asBearer($this->login(['manage-contracts']))
            ->getJson('/api/v1/admin/vehicle-contracts')
            ->assertForbidden();
    }

    public function test_the_list_counts_completed_net_payments_and_announces_what_is_possible(): void
    {
        $vehicle = Vehicle::factory()->create();
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['total_amount' => 10_000]);
        Payment::factory()->create(['vehicle_contract_id' => $contract->id, 'amount' => 7_000, 'net_amount' => 6_000]);
        Payment::factory()->status('failed')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 9_000]);
        DriverContract::factory()->forVehicleContract($contract)->create();

        $finished = VehicleContract::factory()->completed()->create(['total_amount' => 5_000]);
        Payment::factory()->create(['vehicle_contract_id' => $finished->id, 'net_amount' => 6_000]);

        $untouched = VehicleContract::factory()->completed()->create();

        $response = $this->asBearer($this->login(['view-contracts']))
            ->getJson('/api/v1/admin/vehicle-contracts')
            ->assertOk();

        $rows = collect($response->json('contracts'))->keyBy('id');

        $active = $rows[$contract->id];
        $this->assertEquals(6_000, $active['total_paid']);
        $this->assertEquals(4_000, $active['remaining']);
        $this->assertSame(60, $active['progress_percent']);
        $this->assertSame($vehicle->vehicle_number, $active['vehicle']['vehicle_number']);
        // Un agent actif sur le véhicule : le service refuserait la modification.
        $this->assertFalse($active['is_editable']);
        $this->assertFalse($active['is_deletable']);

        $this->assertEquals(1_000, $rows[$finished->id]['surplus']);
        $this->assertEquals(0, $rows[$finished->id]['remaining']);
        $this->assertFalse($rows[$finished->id]['is_deletable']);

        $this->assertTrue($rows[$untouched->id]['is_editable']);
        $this->assertTrue($rows[$untouched->id]['is_deletable']);
    }

    public function test_available_vehicles_have_an_owner_and_no_active_contract(): void
    {
        $free = Vehicle::factory()->create();
        $underContract = Vehicle::factory()->create();
        VehicleContract::factory()->forVehicle($underContract)->create();
        $ownerless = Vehicle::factory()->create(['owner_id' => null]);
        $inactive = Vehicle::factory()->create(['is_active' => false]);

        $ids = collect($this->asBearer($this->login(['view-contracts']))
            ->getJson('/api/v1/admin/vehicle-contracts')
            ->assertOk()
            ->json('available_vehicles'))->pluck('id');

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($underContract->id, $ids);
        $this->assertNotContains($ownerless->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_the_detail_shows_history_current_driver_and_monthly_totals(): void
    {
        $contract = VehicleContract::factory()->create();

        $driverUser = User::factory()->profil(Profil::Driver)->create(['name' => 'Koffi Agent']);
        $driver = Driver::factory()->create(['user_id' => $driverUser->id]);
        DriverContract::factory()->forVehicleContract($contract)->create([
            'driver_id' => $driver->id,
            'start_date' => '2026-05-01',
        ]);
        DriverContract::factory()->forVehicleContract($contract)->create([
            'status' => 'ended',
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-30',
            'end_reason' => 'Démission',
        ]);

        Payment::factory()->onDay('2026-08-03')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5_000]);
        Payment::factory()->onDay('2026-08-20')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 1_000]);
        Payment::factory()->onDay('2026-07-10')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 2_000]);
        // Défaut corrigé : un paiement annulé entrait dans les totaux mensuels.
        Payment::factory()->onDay('2026-08-21')->status('cancelled')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 50_000]);

        VehiclePause::factory()->create([
            'vehicle_id' => $contract->vehicle_id,
            'vehicle_contract_id' => $contract->id,
        ]);

        $response = $this->asBearer($this->login(['view-contracts']))
            ->getJson("/api/v1/admin/vehicle-contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('contract.id', $contract->id)
            ->assertJsonPath('current_driver.id', $driver->id)
            ->assertJsonPath('current_driver.name', 'Koffi Agent')
            ->assertJsonPath('current_driver_since', '2026-05-01')
            ->assertJsonPath('payments_count', 4)
            ->assertJsonCount(2, 'driver_contracts')
            ->assertJsonPath('driver_contracts.0.status', 'active')
            ->assertJsonPath('driver_contracts.1.end_reason', 'Démission')
            ->assertJsonCount(1, 'pauses');

        $this->assertEquals(
            [['month' => '2026-08', 'total' => 6_000], ['month' => '2026-07', 'total' => 2_000]],
            $response->json('payments_by_month'),
        );
    }

    public function test_an_unknown_contract_is_a_404(): void
    {
        $this->asBearer($this->login(['view-contracts']))
            ->getJson('/api/v1/admin/vehicle-contracts/'.fake()->uuid())
            ->assertNotFound();
    }
}
