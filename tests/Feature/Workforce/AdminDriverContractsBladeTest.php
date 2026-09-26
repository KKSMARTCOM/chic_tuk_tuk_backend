<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Le chemin Blade `/admin/driver-contracts/*`, aligné sur l'API le 2026-09-26 : une
 * permission par route au lieu de `manage-contracts`, et les règles du service.
 */
class AdminDriverContractsBladeTest extends TestCase
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

    private function activeContract(array $attributes = []): DriverContract
    {
        $vehicle = Vehicle::factory()->create();
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create();

        return DriverContract::factory()->forVehicleContract($vehicleContract)->create($attributes);
    }

    public function test_each_route_requires_its_own_permission(): void
    {
        $contract = $this->activeContract();
        $reader = $this->adminWith(['view-contracts']);

        $this->actingAs($reader)->get(route('admin.driver-contracts.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.driver-contracts.show', $contract))->assertOk();
        $this->actingAs($reader)->put(route('admin.driver-contracts.update', $contract), [])->assertForbidden();
        $this->actingAs($reader)->post(route('admin.driver-contracts.end', $contract), [])->assertForbidden();
        $this->actingAs($reader)->delete(route('admin.driver-contracts.destroy', $contract))->assertForbidden();
    }

    public function test_changing_vehicle_follows_the_new_vehicle_contract(): void
    {
        $contract = $this->activeContract();
        $target = Vehicle::factory()->create();
        $targetContract = VehicleContract::factory()->forVehicle($target)->create();

        $this->actingAs($this->adminWith(['edit-contracts']))
            ->put(route('admin.driver-contracts.update', $contract), [
                'vehicle_id' => $target->id,
                'start_date' => $contract->start_date->toDateString(),
                'contract_months' => 24,
            ])
            ->assertSessionHas('success');

        $this->assertSame($targetContract->id, $contract->fresh()->vehicle_contract_id);
    }

    public function test_a_contract_with_a_leave_is_locked_on_the_blade_too(): void
    {
        $contract = $this->activeContract(['contract_months' => 24]);
        LeaveRequest::factory()->create(['driver_id' => $contract->driver_id, 'driver_contract_id' => $contract->id]);

        $this->actingAs($this->adminWith(['edit-contracts']))
            ->put(route('admin.driver-contracts.update', $contract), [
                'vehicle_id' => $contract->vehicle_id,
                'start_date' => '2026-01-01',
                'contract_months' => 36,
            ])
            ->assertSessionHas('error');

        $this->assertSame(24, $contract->fresh()->contract_months);
    }

    public function test_an_ended_contract_is_not_ended_twice(): void
    {
        $contract = $this->activeContract(['status' => 'ended', 'end_date' => '2026-09-01']);

        $this->actingAs($this->adminWith(['edit-contracts']))
            ->post(route('admin.driver-contracts.end', $contract), ['end_date' => '2026-09-20', 'end_reason' => 'autre'])
            ->assertSessionHas('error');

        $this->assertSame(0, VehiclePause::query()->where('driver_contract_id', $contract->id)->count());
    }

    public function test_a_contract_with_a_leave_is_not_deleted(): void
    {
        $contract = $this->activeContract(['status' => 'ended', 'end_date' => '2026-09-01']);
        LeaveRequest::factory()->create(['driver_id' => $contract->driver_id, 'driver_contract_id' => $contract->id]);

        $this->actingAs($this->adminWith(['delete-contracts']))
            ->delete(route('admin.driver-contracts.destroy', $contract))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('driver_contracts', ['id' => $contract->id]);
    }
}
