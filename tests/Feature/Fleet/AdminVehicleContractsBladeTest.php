<?php

namespace Tests\Feature\Fleet;

use App\Consts\VehicleContractConsts;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Le chemin Blade `/admin/vehicle-contracts/*`, aligné sur l'API le 2026-09-26 : une
 * permission par route au lieu de la seule `manage-contracts`, et les règles du service.
 */
class AdminVehicleContractsBladeTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(array $permissions): User
    {
        $admin = User::factory()->profil(Profil::Admin)->create(['email' => Str::uuid().'@example.test']);

        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin;
    }

    public function test_each_route_requires_its_own_permission(): void
    {
        $contract = VehicleContract::factory()->completed()->create();
        $reader = $this->adminWith(['view-contracts']);

        $this->actingAs($reader)->get(route('admin.vehicle-contracts.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.vehicle-contracts.show', $contract))->assertOk();
        $this->actingAs($reader)->post(route('admin.vehicle-contracts.store'), [])->assertForbidden();
        $this->actingAs($reader)->put(route('admin.vehicle-contracts.update', $contract), [])->assertForbidden();
        $this->actingAs($reader)->delete(route('admin.vehicle-contracts.destroy', $contract))->assertForbidden();

        $this->assertDatabaseHas('vehicle_contracts', ['id' => $contract->id]);
    }

    public function test_the_vehicle_modal_creates_a_contract_with_its_duration(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($this->adminWith(['create-contracts']))
            ->post(route('admin.vehicle-contracts.store'), [
                'vehicle_id' => $vehicle->id,
                'contract_months' => 36,
                'total_amount' => VehicleContractConsts::TOTAL_AMOUNTS[36],
                'start_date' => '2026-10-01',
            ])
            ->assertRedirect();

        $contract = VehicleContract::query()->where('vehicle_id', $vehicle->id)->firstOrFail();
        $this->assertSame(36, $contract->contract_months);
        $this->assertSame($vehicle->owner_id, $contract->owner_id);
    }

    public function test_a_second_active_contract_is_refused_with_a_flash(): void
    {
        $vehicle = Vehicle::factory()->create();
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->actingAs($this->adminWith(['create-contracts']))
            ->from(route('admin.vehicles.show', $vehicle))
            ->post(route('admin.vehicle-contracts.store'), [
                'vehicle_id' => $vehicle->id,
                'contract_months' => 24,
                'total_amount' => 3_100_000,
                'start_date' => '2026-10-01',
            ])
            ->assertRedirect(route('admin.vehicles.show', $vehicle))
            ->assertSessionHas('error');

        $this->assertSame(1, VehicleContract::query()->where('vehicle_id', $vehicle->id)->count());
    }

    public function test_the_edit_modal_keeps_the_end_date(): void
    {
        $contract = VehicleContract::factory()->completed()->create(['end_date' => '2026-06-30']);

        $this->actingAs($this->adminWith(['edit-contracts']))
            ->put(route('admin.vehicle-contracts.update', $contract), [
                'contract_months' => 24,
                'contract_total_amount' => 3_100_000,
                'contract_start_date' => '2025-01-01',
                'status' => 'completed',
            ])
            ->assertSessionHas('success');

        $this->assertSame('2026-06-30', $contract->fresh()->end_date->toDateString());
    }

    public function test_the_blade_refuses_to_delete_a_contract_with_history_and_keeps_the_owner(): void
    {
        $vehicle = Vehicle::factory()->create();
        $withHistory = VehicleContract::factory()->forVehicle($vehicle)->completed()->create();
        DriverContract::factory()->forVehicleContract($withHistory)->create(['status' => 'ended']);
        $bare = VehicleContract::factory()->forVehicle($vehicle)->completed()->create();
        $admin = $this->adminWith(['delete-contracts']);

        $this->actingAs($admin)->delete(route('admin.vehicle-contracts.destroy', $withHistory))
            ->assertSessionHas('error');
        $this->actingAs($admin)->delete(route('admin.vehicle-contracts.destroy', $bare))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('vehicle_contracts', ['id' => $withHistory->id]);
        $this->assertDatabaseMissing('vehicle_contracts', ['id' => $bare->id]);
        $this->assertNotNull($vehicle->fresh()->owner_id);
    }
}
