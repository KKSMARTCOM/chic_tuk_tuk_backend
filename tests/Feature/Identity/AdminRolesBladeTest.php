<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ReferenceRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le chemin Blade `/admin/roles`, encore en service : mêmes règles que l'API (U2, 2026-09-26). */
class AdminRolesBladeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceRolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->profil(Profil::Admin)->create();
        $this->admin->assignRole('admin');
    }

    public function test_renaming_a_role_keeps_its_technical_name(): void
    {
        $role = Role::create(['name' => 'comptable', 'label' => 'Comptable', 'guard_name' => 'web']);

        $this->actingAs($this->admin)->put(route('admin.roles.update', $role), ['label' => 'Comptabilité'])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertSame('comptable', $role->fresh()->name);
        $this->assertSame('Comptabilité', $role->fresh()->label);
    }

    public function test_reference_roles_are_neither_updated_nor_deleted(): void
    {
        foreach (['admin', 'proprietaire', 'utilisateur'] as $name) {
            $role = Role::findByName($name, 'web');

            $this->actingAs($this->admin)->put(route('admin.roles.update', $role), ['label' => 'Renommé'])
                ->assertSessionHas('error');
            $this->actingAs($this->admin)->delete(route('admin.roles.destroy', $role))
                ->assertSessionHas('error');

            $this->assertDatabaseHas('roles', ['name' => $name, 'label' => $role->label]);
        }
    }

    public function test_a_role_held_by_accounts_is_not_deleted(): void
    {
        $role = Role::create(['name' => 'comptable', 'label' => 'Comptable', 'guard_name' => 'web']);
        User::factory()->profil(Profil::Admin)->create()->assignRole($role);

        $this->actingAs($this->admin)->delete(route('admin.roles.destroy', $role))->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_writes_require_their_own_permission(): void
    {
        $reader = Role::create(['name' => 'observateur', 'label' => 'Observateur', 'guard_name' => 'web']);
        $reader->givePermissionTo('view-roles');
        $actor = User::factory()->profil(Profil::Admin)->create();
        $actor->assignRole($reader);

        $this->actingAs($actor)->post(route('admin.roles.store'), ['label' => 'Intrus'])->assertForbidden();
        $this->actingAs($actor)->put(route('admin.roles.update', $reader), ['label' => 'X'])->assertForbidden();
        $this->actingAs($actor)->delete(route('admin.roles.destroy', $reader))->assertForbidden();
    }
}
