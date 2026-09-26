<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Database\Seeders\ReferenceRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le chemin Blade `/admin/users`, encore en service jusqu'à la bascule : ses écritures
 * n'exigeaient que `view-users`, que porte le rôle `utilisateur` (corrigé le 2026-09-26).
 */
class AdminUsersBladeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_utilisateur_role_can_no_longer_write_through_the_blade_routes(): void
    {
        $this->seed(ReferenceRolesAndPermissionsSeeder::class);
        $actor = User::factory()->profil(Profil::Admin)->create();
        $actor->assignRole('utilisateur');
        $target = User::factory()->profil(Profil::Admin)->create();
        $target->assignRole('utilisateur');

        $this->actingAs($actor)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($actor)->post(route('admin.users.store'), [
            'name' => 'Intrus',
            'phone' => '97000001',
            'password' => 'Secret2026!',
            'role' => 'admin',
            'profil' => 'admin',
        ])->assertForbidden();
        $this->actingAs($actor)->put(route('admin.users.update', $target), [])->assertForbidden();
        $this->actingAs($actor)->delete(route('admin.users.destroy', $target))->assertForbidden();
        // `utilisateur` crée des agents : le générateur, qui sert aussi cet écran, lui reste ouvert.
        $this->actingAs($actor)->get(route('admin.users.generate-password'))->assertOk();

        $this->assertDatabaseMissing('users', ['name' => 'Intrus']);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }
}
