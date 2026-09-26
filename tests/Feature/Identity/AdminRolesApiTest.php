<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ReferenceRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Les rôles et le catalogue des permissions — ex-Admin\RoleController et
 * Admin\PermissionController, décisions du 2026-09-26 (U2).
 *
 * - Les cinq rôles de référence sont en LECTURE SEULE : le seeder, rejoué à chaque
 *   déploiement, effacerait toute modification faite à l'écran.
 * - Le catalogue des permissions se lit, il ne s'écrit plus : une permission n'a de sens
 *   que si le code la vérifie, elle naît donc dans le seeder.
 *
 * Défauts du Blade corrigés : modifier le libellé renommait le nom technique ; supprimer
 * un rôle porté retirait leurs droits à ses titulaires ; `proprietaire` et `utilisateur`
 * se supprimaient par l'URL ; un libellé au slug déjà pris finissait en 500.
 */
class AdminRolesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceRolesAndPermissionsSeeder::class);
    }

    /** @return array{0: User, 1: string} */
    private function login(string $role = 'admin'): array
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $user->assignRole($role);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function customRole(array $permissions = ['view-bookings'], string $label = 'Comptable'): Role
    {
        $role = Role::create(['name' => str($label)->slug()->toString(), 'label' => $label, 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        return $role;
    }

    // ----- Lecture -------------------------------------------------------------------

    public function test_the_list_marks_reference_roles_and_counts_permissions_and_accounts(): void
    {
        [, $token] = $this->login();
        $custom = $this->customRole(['view-bookings', 'view-drivers']);

        $roles = collect($this->asBearer($token)->getJson('/api/v1/admin/roles')->assertOk()->json('roles'))
            ->keyBy('name');

        $this->assertEqualsCanonicalizing(
            ['admin', 'utilisateur', 'driver', 'proprietaire', 'client', 'comptable'],
            $roles->keys()->all(),
        );
        $this->assertTrue($roles['admin']['is_reference']);
        $this->assertSame(1, $roles['admin']['users_count']);
        $this->assertFalse($roles['comptable']['is_reference']);
        $this->assertSame(2, $roles['comptable']['permissions_count']);
        $this->assertSame($custom->id, $roles['comptable']['id']);
    }

    public function test_the_detail_lists_the_permissions_with_their_family_and_the_accounts(): void
    {
        [$me, $token] = $this->login();
        $role = $this->customRole(['view-bookings', 'view-drivers']);
        $me->assignRole($role);

        $this->asBearer($token)->getJson("/api/v1/admin/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('label', 'Comptable')
            ->assertJsonPath('is_reference', false)
            ->assertJsonPath('users.0.name', $me->name)
            ->assertJsonCount(2, 'permissions')
            ->assertJsonFragment(['name' => 'view-bookings', 'label' => 'Voir les réservations', 'family' => 'Réservations']);
    }

    public function test_permissions_read_in_menu_order_then_view_create_edit_delete(): void
    {
        [, $token] = $this->login();
        $role = $this->customRole(['delete-payments', 'edit-payments', 'view-bookings', 'view-payments']);

        $names = collect($this->asBearer($token)->getJson("/api/v1/admin/roles/{$role->id}")->json('permissions'))
            ->pluck('name')->all();

        $this->assertSame(['view-bookings', 'view-payments', 'edit-payments', 'delete-payments'], $names);
    }

    public function test_the_catalog_groups_reference_permissions_and_names_their_roles(): void
    {
        [, $token] = $this->login();
        // Une ligne hors référence — un reliquat comme `manage-payments` — n'y paraît pas.
        Permission::create(['name' => 'manage-legacy', 'guard_name' => 'web']);

        $groups = collect($this->asBearer($token)->getJson('/api/v1/admin/permissions')->assertOk()->json('groups'));

        $names = $groups->flatMap(fn ($g) => collect($g['permissions'])->pluck('name'));
        $this->assertNotContains('manage-legacy', $names);
        $this->assertNotContains('create-permissions', $names);

        $bookings = $groups->firstWhere('label', 'Réservations');
        $view = collect($bookings['permissions'])->firstWhere('name', 'view-bookings');
        $this->assertContains('admin', collect($view['roles'])->pluck('name'));
        $this->assertContains('driver', collect($view['roles'])->pluck('name'));
    }

    public function test_reading_requires_view_roles_and_the_catalog_view_permissions_or_a_role_writer(): void
    {
        [, $token] = $this->login('utilisateur');

        $this->asBearer($token)->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->asBearer($token)->getJson('/api/v1/admin/permissions')->assertForbidden();
    }

    // ----- Création --------------------------------------------------------------------

    public function test_a_role_is_created_with_a_slug_name_and_its_permissions(): void
    {
        [, $token] = $this->login();

        $response = $this->asBearer($token)->postJson('/api/v1/admin/roles', [
            'label' => 'Chef d’équipe',
            'description' => 'Suit les agents',
            'permissions' => ['view-drivers', 'view-bookings'],
        ])->assertCreated()->assertJsonPath('name', 'chef-dequipe');

        $role = Role::findById($response->json('id'), 'web');
        $this->assertSame('Suit les agents', $role->description);
        $this->assertEqualsCanonicalizing(['view-drivers', 'view-bookings'], $role->permissions->pluck('name')->all());
    }

    public function test_a_label_whose_slug_is_taken_is_a_validation_error(): void
    {
        [, $token] = $this->login();

        foreach (['Admin', 'ADMIN !', '!!!'] as $label) {
            $this->asBearer($token)->postJson('/api/v1/admin/roles', ['label' => $label, 'permissions' => []])
                ->assertUnprocessable()->assertJsonValidationErrors(['label']);
        }
    }

    public function test_only_reference_permissions_are_accepted(): void
    {
        [, $token] = $this->login();
        Permission::create(['name' => 'manage-legacy', 'guard_name' => 'web']);

        $this->asBearer($token)->postJson('/api/v1/admin/roles', [
            'label' => 'Test',
            'permissions' => ['manage-legacy'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_one_cannot_grant_permissions_one_does_not_hold(): void
    {
        // Un rôle qui gère les rôles sans tout posséder ne fabrique pas un super-rôle.
        $manager = $this->customRole(['view-roles', 'create-roles', 'edit-roles', 'view-bookings'], 'Gestionnaire');
        [, $token] = $this->login($manager->name);

        $this->asBearer($token)->postJson('/api/v1/admin/roles', [
            'label' => 'Super',
            'permissions' => ['view-bookings', 'delete-users'],
        ])->assertForbidden()->assertJsonPath('code', 'ROLE_BEYOND_ACTOR');

        $this->asBearer($token)->postJson('/api/v1/admin/roles', [
            'label' => 'Lecteur des courses',
            'permissions' => ['view-bookings'],
        ])->assertCreated();
    }

    // ----- Modification ----------------------------------------------------------------

    public function test_a_custom_role_is_updated_but_keeps_its_technical_name(): void
    {
        [, $token] = $this->login();
        $role = $this->customRole(['view-bookings']);

        $this->asBearer($token)->putJson("/api/v1/admin/roles/{$role->id}", [
            'label' => 'Comptabilité',
            'description' => 'Nouvelle description',
            'permissions' => ['view-payments'],
        ])->assertOk()->assertJsonPath('name', 'comptable')->assertJsonPath('label', 'Comptabilité');

        $role->refresh();
        $this->assertSame('comptable', $role->name);
        $this->assertSame(['view-payments'], $role->permissions->pluck('name')->all());
    }

    public function test_reference_roles_are_read_only(): void
    {
        [, $token] = $this->login();

        foreach (['admin', 'utilisateur', 'driver', 'proprietaire', 'client'] as $name) {
            $role = Role::findByName($name, 'web');
            $before = $role->permissions()->count();

            $this->asBearer($token)->putJson("/api/v1/admin/roles/{$role->id}", [
                'label' => 'Renommé',
                'permissions' => [],
            ])->assertStatus(409)->assertJsonPath('code', 'ROLE_REFERENCE_LOCKED');

            $this->asBearer($token)->deleteJson("/api/v1/admin/roles/{$role->id}")
                ->assertStatus(409)->assertJsonPath('code', 'ROLE_REFERENCE_LOCKED');

            $this->assertSame($before, $role->permissions()->count(), $name);
        }
    }

    // ----- Suppression -----------------------------------------------------------------

    public function test_an_unused_custom_role_is_deleted(): void
    {
        [, $token] = $this->login();
        $role = $this->customRole();

        $this->asBearer($token)->deleteJson("/api/v1/admin/roles/{$role->id}")->assertNoContent();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_a_role_held_by_accounts_is_not_deleted(): void
    {
        [, $token] = $this->login();
        $role = $this->customRole();
        User::factory()->profil(Profil::Admin)->create()->assignRole($role);

        $this->asBearer($token)->deleteJson("/api/v1/admin/roles/{$role->id}")
            ->assertStatus(409)->assertJsonPath('code', 'ROLE_IN_USE');

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_writes_require_their_own_permission(): void
    {
        $reader = $this->customRole(['view-roles'], 'Observateur');
        [, $token] = $this->login($reader->name);
        $role = $this->customRole();

        $this->asBearer($token)->getJson('/api/v1/admin/roles')->assertOk();
        $this->asBearer($token)->postJson('/api/v1/admin/roles', ['label' => 'X', 'permissions' => []])
            ->assertForbidden();
        $this->asBearer($token)->putJson("/api/v1/admin/roles/{$role->id}", ['label' => 'X', 'permissions' => []])
            ->assertForbidden();
        $this->asBearer($token)->deleteJson("/api/v1/admin/roles/{$role->id}")->assertForbidden();
    }

    public function test_an_unknown_role_is_not_found(): void
    {
        [, $token] = $this->login();

        $this->asBearer($token)->getJson('/api/v1/admin/roles/999999')->assertNotFound();
        $this->asBearer($token)->getJson('/api/v1/admin/roles/abc')->assertNotFound();
    }
}
