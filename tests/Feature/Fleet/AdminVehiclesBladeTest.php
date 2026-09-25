<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Le chemin Blade `/admin/vehicles/*`, aligné sur l'API le 2026-09-25 : mêmes règles de
 * suppression et d'annulation de pause, et une permission par écriture.
 *
 * ⚠️ `Route::resource('vehicles')->middleware('permission:view-vehicles')` gardait les
 * 7 routes de la ressource par cette SEULE permission — création, modification et
 * suppression comprises. Même défaut que les agents, fermé le 2026-09-23.
 */
class AdminVehiclesBladeTest extends TestCase
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

    public function test_view_vehicles_alone_cannot_create_update_or_delete(): void
    {
        $vehicle = Vehicle::factory()->create();
        $admin = $this->adminWith(['view-vehicles']);

        $this->actingAs($admin)->post(route('admin.vehicles.store'), [])->assertForbidden();
        $this->actingAs($admin)->put(route('admin.vehicles.update', $vehicle), [])->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.vehicles.destroy', $vehicle))->assertForbidden();

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }

    public function test_the_blade_refuses_to_delete_a_vehicle_with_history(): void
    {
        $vehicle = Vehicle::factory()->create();
        VehicleContract::factory()->forVehicle($vehicle)->completed()->create();

        $this->actingAs($this->adminWith(['view-vehicles', 'delete-vehicles']))
            ->from(route('admin.vehicles.index'))
            ->delete(route('admin.vehicles.destroy', $vehicle))
            ->assertRedirect(route('admin.vehicles.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }

    public function test_the_blade_pause_deletion_reactivates_the_vehicle(): void
    {
        // Défaut corrigé : `destroyPause` effaçait la pause sans réactiver le véhicule.
        $vehicle = Vehicle::factory()->create(['is_active' => false]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();
        $pause = VehiclePause::create([
            'vehicle_id' => $vehicle->id,
            'vehicle_contract_id' => $contract->id,
            'start_date' => now()->toDateString(),
            'reason_type' => 'technical',
            'is_auto' => false,
        ]);

        $this->actingAs($this->adminWith(['manage-vehicle-pauses']))
            ->from(route('admin.vehicles.index'))
            ->post(route('admin.vehicles.destroy-pause', $pause))
            ->assertRedirect(route('admin.vehicles.index'));

        $this->assertDatabaseMissing('vehicle_pauses', ['id' => $pause->id]);
        $this->assertTrue($vehicle->fresh()->is_active);
    }
}
