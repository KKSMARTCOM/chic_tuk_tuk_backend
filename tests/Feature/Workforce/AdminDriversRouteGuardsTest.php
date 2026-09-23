<?php

namespace Tests\Feature\Workforce;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les gardes de permission des routes Blade `/admin/drivers/*`.
 *
 * ⚠️ Le test qui ferme le défaut trouvé le 2026-09-23 :
 * `Route::resource('drivers', DriverController::class)->middleware('permission:view-drivers')`
 * appliquait CETTE SEULE permission aux 7 routes de la ressource — y compris `store`,
 * `update` et `destroy`. Un rôle qui n'a que `view-drivers` (comme `utilisateur`, qui n'a
 * PAS `delete-drivers` dans le catalogue de référence) pouvait donc supprimer un agent.
 */
class AdminDriversRouteGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(array $permissions): User
    {
        $admin = User::create([
            'name' => 'Admin Test',
            'email' => Str::uuid().'@example.test',
            'phone' => '97'.random_int(100000, 999999),
            'profil' => 'admin',
            'password' => bcrypt('secret'),
        ]);

        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin;
    }

    public function test_view_drivers_seul_ne_permet_pas_de_supprimer_un_agent(): void
    {
        $driver = Driver::factory()->create();
        $admin = $this->adminWith(['view-drivers']);

        $this->actingAs($admin)
            ->delete(route('admin.drivers.destroy', $driver->user_id))
            ->assertForbidden();

        $this->assertDatabaseHas('drivers', ['id' => $driver->id]);
    }

    public function test_delete_drivers_permet_de_supprimer_un_agent(): void
    {
        $driver = Driver::factory()->create();
        $admin = $this->adminWith(['view-drivers', 'delete-drivers']);

        $this->actingAs($admin)
            ->delete(route('admin.drivers.destroy', $driver->user_id))
            ->assertRedirect(route('admin.drivers.index'));

        $this->assertDatabaseMissing('drivers', ['id' => $driver->id]);
    }

    public function test_view_drivers_seul_ne_permet_pas_de_creer_un_agent(): void
    {
        $admin = $this->adminWith(['view-drivers']);

        $this->actingAs($admin)
            ->post(route('admin.drivers.store'), [])
            ->assertForbidden();
    }

    public function test_view_drivers_seul_ne_permet_pas_de_modifier_un_agent(): void
    {
        $driver = Driver::factory()->create();
        $admin = $this->adminWith(['view-drivers']);

        $this->actingAs($admin)
            ->put(route('admin.drivers.update', $driver->user_id), [])
            ->assertForbidden();
    }
}
