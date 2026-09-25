<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Les actions de compte de la liste des propriétaires : statut, mot de passe,
 * suppression. Le Blade les faisait passer par `/admin/users/*`.
 *
 * ⚠️ Règle décidée le 2026-09-25 : un propriétaire qui a un véhicule ou un contrat
 * véhicule, même terminé, ne se supprime pas. Les clés étrangères sont en cascade :
 * la suppression emportait ses véhicules, leurs contrats terminés, les contrats agents
 * et les pauses véhicule. Seul un contrat véhicule ACTIF l'empêchait.
 */
class AdminOwnerAccountWritesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
    }

    private function admin(array $permissions): User
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function login(array $permissions): string
    {
        $user = $this->admin($permissions);

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

    private function owner(): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create(['is_active' => true]);
        $owner->assignRole('proprietaire');

        return $owner;
    }

    public function test_the_status_is_set_explicitly(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();

        $this->asBearer($token)->postJson("/api/v1/admin/owners/{$owner->id}/toggle-status", ['is_active' => false])
            ->assertOk();
        $this->assertFalse($owner->fresh()->is_active);

        // Explicite et non une bascule : rejouer la même requête ne réactive pas le compte.
        $this->asBearer($token)->postJson("/api/v1/admin/owners/{$owner->id}/toggle-status", ['is_active' => false])
            ->assertOk();
        $this->assertFalse($owner->fresh()->is_active);
    }

    public function test_the_password_is_replaced_when_confirmed(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();

        $this->asBearer($token)->postJson("/api/v1/admin/owners/{$owner->id}/password", [
            'password' => 'Nouveau2026!',
            'password_confirmation' => 'Autre2026!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->asBearer($token)->postJson("/api/v1/admin/owners/{$owner->id}/password", [
            'password' => 'Nouveau2026!',
            'password_confirmation' => 'Nouveau2026!',
        ])->assertOk();

        $this->assertTrue(Hash::check('Nouveau2026!', $owner->fresh()->password));
    }

    public function test_status_and_password_require_edit_owners(): void
    {
        $token = $this->login(['view-owners']);
        $owner = $this->owner();

        $this->asBearer($token)->postJson("/api/v1/admin/owners/{$owner->id}/toggle-status", ['is_active' => false])
            ->assertForbidden();
        $this->asBearer($token)->postJson("/api/v1/admin/owners/{$owner->id}/password", [])
            ->assertForbidden();
    }

    public function test_an_owner_without_vehicle_is_deleted(): void
    {
        $token = $this->login(['delete-owners']);
        $owner = $this->owner();

        $this->asBearer($token)->deleteJson("/api/v1/admin/owners/{$owner->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    }

    public function test_an_owner_with_a_vehicle_is_not_deleted(): void
    {
        $token = $this->login(['delete-owners']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);

        $this->asBearer($token)->deleteJson("/api/v1/admin/owners/{$owner->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'OWNER_NOT_DELETABLE');

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }

    public function test_an_owner_with_only_a_finished_contract_is_not_deleted(): void
    {
        // Le véhicule a changé de mains, mais le contrat terminé reste lié au compte :
        // la cascade l'effacerait.
        $token = $this->login(['delete-owners']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create();
        $contract = VehicleContract::factory()->completed()->create([
            'vehicle_id' => $vehicle->id,
            'owner_id' => $owner->id,
        ]);

        $this->asBearer($token)->deleteJson("/api/v1/admin/owners/{$owner->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'OWNER_NOT_DELETABLE');

        $this->assertDatabaseHas('vehicle_contracts', ['id' => $contract->id]);
    }

    public function test_deletion_requires_delete_owners(): void
    {
        $token = $this->login(['view-owners', 'edit-owners']);
        $owner = $this->owner();

        $this->asBearer($token)->deleteJson("/api/v1/admin/owners/{$owner->id}")->assertForbidden();
    }

    public function test_an_account_that_is_not_an_owner_is_not_deleted_here(): void
    {
        $token = $this->login(['delete-owners']);
        $agent = Driver::factory()->create()->user;

        $this->asBearer($token)->deleteJson("/api/v1/admin/owners/{$agent->id}")->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $agent->id]);
    }

    public function test_the_blade_path_refuses_too(): void
    {
        // Le Blade supprime par `/admin/users/{user}` et `UserService::delete()` : la
        // règle y vit, pour que les deux chemins la partagent.
        $admin = $this->admin(['view-users']);
        $owner = $this->owner();
        Vehicle::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($admin)
            ->from(route('admin.owners.index'))
            ->delete(route('admin.users.destroy', $owner))
            ->assertRedirect(route('admin.owners.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }
}
