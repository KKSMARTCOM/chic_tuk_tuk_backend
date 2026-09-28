<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ReferenceRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Les comptes administrateurs — ex-Admin\UserController, décisions du 2026-09-26 (U1).
 *
 * Défauts du Blade corrigés, chacun verrouillé ici :
 *
 * - les écritures n'exigeaient que `view-users`, que porte le rôle `utilisateur` : il
 *   pouvait créer un compte `admin` et s'en servir ;
 * - n'importe quel rôle s'attribuait, `driver` et `proprietaire` compris ;
 * - `/users/{id}` atteignait n'importe quel compte, agent ou propriétaire ;
 * - on pouvait se désactiver ou changer son propre rôle ;
 * - désactiver un compte ne coupait pas ses sessions ouvertes.
 */
class AdminUsersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceRolesAndPermissionsSeeder::class);
    }

    private function account(string $role, array $attributes = []): User
    {
        $user = User::factory()->profil(Profil::Admin)->create(
            $attributes + ['password' => Hash::make('bon-mot-de-passe'), 'is_active' => true]
        );
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: User, 1: string} */
    private function login(string $role = 'admin'): array
    {
        $user = $this->account($role);

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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nouvel Admin',
            'email' => 'nouvel.admin@example.test',
            'phone' => '97112233',
            'password' => 'Secret2026!',
            'role' => 'utilisateur',
            'adresse' => 'Cotonou',
            'is_active' => true,
        ], $overrides);
    }

    // ----- Lecture -------------------------------------------------------------------

    public function test_the_list_shows_only_admin_accounts_with_their_roles_and_counters(): void
    {
        [, $token] = $this->login();
        $this->account('utilisateur', ['name' => 'Inès Inactive', 'is_active' => false]);
        $driver = User::factory()->profil(Profil::Driver)->create();
        $driver->assignRole('driver');

        $response = $this->asBearer($token)->getJson('/api/v1/admin/users')->assertOk();

        $response->assertJsonCount(2, 'users')
            ->assertJsonPath('stats', ['total' => 2, 'active' => 1, 'inactive' => 1]);

        $ines = collect($response->json('users'))->firstWhere('name', 'Inès Inactive');
        $this->assertSame([['name' => 'utilisateur', 'label' => 'Utilisateur']], $ines['roles']);
        $this->assertFalse($ines['is_active']);
    }

    public function test_the_list_offers_only_administration_roles(): void
    {
        [, $token] = $this->login();

        $names = collect($this->asBearer($token)->getJson('/api/v1/admin/users')->json('assignable_roles'))
            ->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['admin', 'utilisateur'], $names);
    }

    /** Paginée et triée côté serveur depuis le 2026-09-28. */
    public function test_the_list_is_paginated_and_sorted_by_the_server(): void
    {
        [, $token] = $this->login();
        foreach (range(1, 25) as $n) {
            $this->account('utilisateur', ['name' => sprintf('Compte %02d', $n)]);
        }

        // 26 comptes avec celui qui se connecte.
        $this->asBearer($token)->getJson('/api/v1/admin/users?sort=name')
            ->assertOk()
            ->assertJsonCount(25, 'users')
            ->assertJsonPath('users.0.name', 'Compte 01')
            ->assertJsonPath('pagination.total', 26)
            ->assertJsonPath('stats.total', 26);

        $this->asBearer($token)->getJson('/api/v1/admin/users?sort=password')->assertStatus(400);
    }

    public function test_the_list_filters_by_search_and_status(): void
    {
        [, $token] = $this->login();
        $this->account('utilisateur', ['name' => 'Inès Inactive', 'is_active' => false]);

        $this->asBearer($token)->getJson('/api/v1/admin/users?filter[search]=Inès')
            ->assertJsonCount(1, 'users');
        $this->asBearer($token)->getJson('/api/v1/admin/users?filter[is_active]=0')
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.name', 'Inès Inactive');
    }

    // ----- Permissions -----------------------------------------------------------------

    public function test_the_utilisateur_role_reads_but_does_not_write(): void
    {
        // Le cœur du défaut : `utilisateur` porte `view-users`, et le Blade n'exigeait
        // rien de plus pour créer, modifier ou supprimer.
        [, $token] = $this->login('utilisateur');
        $target = $this->account('utilisateur');

        $this->asBearer($token)->getJson('/api/v1/admin/users')->assertOk();
        $this->asBearer($token)->postJson('/api/v1/admin/users', $this->validPayload(['role' => 'admin']))
            ->assertForbidden();
        $this->asBearer($token)->putJson("/api/v1/admin/users/{$target->id}", $this->validPayload())
            ->assertForbidden();
        $this->asBearer($token)->postJson("/api/v1/admin/users/{$target->id}/status", ['is_active' => false])
            ->assertForbidden();
        $this->asBearer($token)->postJson("/api/v1/admin/users/{$target->id}/password", [])
            ->assertForbidden();
        $this->asBearer($token)->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nouvel.admin@example.test']);
    }

    public function test_a_role_granting_more_than_the_actor_holds_is_refused(): void
    {
        // Un rôle personnalisé qui reçoit `create-users` ne doit pas ouvrir la porte au
        // rôle `admin` : on n'attribue que ce qu'on possède déjà.
        $role = Role::create(['name' => 'recruteur', 'label' => 'Recruteur', 'guard_name' => 'web']);
        $role->givePermissionTo(['view-users', 'create-users', 'edit-users', 'delete-users']);
        [, $token] = $this->login('recruteur');
        $admin = $this->account('admin');

        $this->asBearer($token)->postJson('/api/v1/admin/users', $this->validPayload(['role' => 'admin']))
            ->assertForbidden()->assertJsonPath('code', 'ROLE_BEYOND_ACTOR');
        $this->asBearer($token)->postJson("/api/v1/admin/users/{$admin->id}/status", ['is_active' => false])
            ->assertForbidden()->assertJsonPath('code', 'ROLE_BEYOND_ACTOR');
        $this->asBearer($token)->deleteJson("/api/v1/admin/users/{$admin->id}")
            ->assertForbidden()->assertJsonPath('code', 'ROLE_BEYOND_ACTOR');

        // Son propre rôle, en revanche, il peut le donner.
        $this->asBearer($token)->postJson('/api/v1/admin/users', $this->validPayload(['role' => 'recruteur']))
            ->assertCreated();
    }

    // ----- Création --------------------------------------------------------------------

    public function test_an_admin_account_is_created_with_its_role(): void
    {
        [, $token] = $this->login();

        $this->asBearer($token)->postJson('/api/v1/admin/users', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('name', 'Nouvel Admin')
            ->assertJsonPath('roles.0.name', 'utilisateur');

        $created = User::where('email', 'nouvel.admin@example.test')->firstOrFail();
        $this->assertSame('admin', $created->profil);
        $this->assertTrue($created->hasRole('utilisateur'));
        $this->assertTrue(Hash::check('Secret2026!', $created->password));
    }

    public function test_the_role_is_required_and_limited_to_administration_roles(): void
    {
        [, $token] = $this->login();

        $this->asBearer($token)->postJson('/api/v1/admin/users', $this->validPayload(['role' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['role']);

        foreach (['driver', 'proprietaire', 'client', 'inexistant'] as $role) {
            $this->asBearer($token)->postJson('/api/v1/admin/users', $this->validPayload(['role' => $role]))
                ->assertUnprocessable()->assertJsonValidationErrors(['role']);
        }
    }

    public function test_email_and_phone_are_unique_among_admin_accounts_only(): void
    {
        // Comme la contrainte en base, qui porte sur (email, profil) : une même personne
        // peut être agent et administrateur, la connexion lui demande alors lequel.
        [, $token] = $this->login();
        User::factory()->profil(Profil::Driver)->create(['email' => 'agent@example.test', 'phone' => '96000000']);
        $this->account('utilisateur', ['email' => 'pris@example.test', 'phone' => '96111111']);

        $this->asBearer($token)->postJson('/api/v1/admin/users', $this->validPayload([
            'email' => 'pris@example.test',
            'phone' => '96111111',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);

        $this->asBearer($token)->postJson('/api/v1/admin/users', $this->validPayload([
            'email' => 'agent@example.test',
            'phone' => '96000000',
        ]))->assertCreated();
    }

    public function test_the_password_rules_apply(): void
    {
        [, $token] = $this->login();

        $this->asBearer($token)->postJson('/api/v1/admin/users', $this->validPayload(['password' => 'faible']))
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    // ----- Modification ----------------------------------------------------------------

    public function test_an_admin_account_is_updated_and_its_role_replaced(): void
    {
        [, $token] = $this->login();
        $target = $this->account('utilisateur', ['email' => 'avant@example.test', 'phone' => '95000000']);

        $this->asBearer($token)->putJson("/api/v1/admin/users/{$target->id}", $this->validPayload([
            'name' => 'Nom Changé',
            // Garder son propre e-mail et son téléphone ne doit pas buter sur l'unicité.
            'email' => 'avant@example.test',
            'phone' => '95000000',
            'role' => 'admin',
            'adresse' => null,
        ]))->assertOk()->assertJsonPath('name', 'Nom Changé');

        $target->refresh();
        $this->assertSame('Nom Changé', $target->name);
        $this->assertNull($target->adresse);
        $this->assertSame(['admin'], $target->getRoleNames()->all());
    }

    public function test_a_non_admin_account_is_not_reachable(): void
    {
        [, $token] = $this->login();
        $driver = User::factory()->profil(Profil::Driver)->create();
        $owner = User::factory()->profil(Profil::Owner)->create();

        foreach ([$driver, $owner] as $user) {
            $this->asBearer($token)->putJson("/api/v1/admin/users/{$user->id}", $this->validPayload())
                ->assertNotFound();
            $this->asBearer($token)->postJson("/api/v1/admin/users/{$user->id}/status", ['is_active' => false])
                ->assertNotFound();
            $this->asBearer($token)->deleteJson("/api/v1/admin/users/{$user->id}")
                ->assertNotFound();
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }
    }

    // ----- Son propre compte -----------------------------------------------------------

    public function test_one_cannot_deactivate_demote_or_delete_oneself(): void
    {
        [$me, $token] = $this->login();

        $this->asBearer($token)->postJson("/api/v1/admin/users/{$me->id}/status", ['is_active' => false])
            ->assertStatus(409)->assertJsonPath('code', 'USER_SELF_DEACTIVATION');
        $this->asBearer($token)->putJson("/api/v1/admin/users/{$me->id}", $this->validPayload([
            'role' => 'admin',
            'is_active' => false,
        ]))->assertStatus(409)->assertJsonPath('code', 'USER_SELF_DEACTIVATION');
        $this->asBearer($token)->putJson("/api/v1/admin/users/{$me->id}", $this->validPayload(['role' => 'utilisateur']))
            ->assertStatus(409)->assertJsonPath('code', 'USER_SELF_ROLE_CHANGE');
        $this->asBearer($token)->deleteJson("/api/v1/admin/users/{$me->id}")
            ->assertStatus(409)->assertJsonPath('code', 'USER_SELF_DELETION');

        $me->refresh();
        $this->assertTrue($me->is_active);
        $this->assertSame(['admin'], $me->getRoleNames()->all());
    }

    public function test_one_can_update_one_own_details_while_keeping_role_and_status(): void
    {
        [$me, $token] = $this->login();

        $this->asBearer($token)->putJson("/api/v1/admin/users/{$me->id}", $this->validPayload([
            'name' => 'Moi Renommé',
            'role' => 'admin',
        ]))->assertOk();

        $this->assertSame('Moi Renommé', $me->fresh()->name);
    }

    // ----- Statut, mot de passe, sessions -------------------------------------------

    public function test_deactivating_an_account_ends_its_open_sessions(): void
    {
        [, $token] = $this->login();
        [$target, $targetToken] = $this->login('utilisateur');

        $this->asBearer($targetToken)->getJson('/api/v1/admin/users')->assertOk();

        $this->asBearer($token)->postJson("/api/v1/admin/users/{$target->id}/status", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame(0, $target->tokens()->count());
        $this->asBearer($targetToken)->getJson('/api/v1/admin/users')->assertUnauthorized();
    }

    public function test_deactivating_through_the_edit_form_also_ends_sessions(): void
    {
        [, $token] = $this->login();
        [$target] = $this->login('utilisateur');

        $this->asBearer($token)->putJson("/api/v1/admin/users/{$target->id}", $this->validPayload([
            'email' => $target->email,
            'phone' => $target->phone,
            'is_active' => false,
        ]))->assertOk();

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_a_new_password_ends_the_sessions_of_that_account(): void
    {
        [, $token] = $this->login();
        [$target] = $this->login('utilisateur');

        $this->asBearer($token)->postJson("/api/v1/admin/users/{$target->id}/password", [
            'password' => 'Nouveau2026!',
            'password_confirmation' => 'Autre2026!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->asBearer($token)->postJson("/api/v1/admin/users/{$target->id}/password", [
            'password' => 'Nouveau2026!',
            'password_confirmation' => 'Nouveau2026!',
        ])->assertOk();

        $this->assertTrue(Hash::check('Nouveau2026!', $target->fresh()->password));
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_changing_ones_own_password_keeps_the_current_session(): void
    {
        [$me, $token] = $this->login();

        $this->asBearer($token)->postJson("/api/v1/admin/users/{$me->id}/password", [
            'password' => 'Nouveau2026!',
            'password_confirmation' => 'Nouveau2026!',
        ])->assertOk();

        $this->asBearer($token)->getJson('/api/v1/admin/users')->assertOk();
    }

    // ----- Suppression -----------------------------------------------------------------

    public function test_an_admin_account_is_deleted(): void
    {
        [, $token] = $this->login();
        $target = $this->account('utilisateur');

        $this->asBearer($token)->deleteJson("/api/v1/admin/users/{$target->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_an_account_that_recorded_bookings_is_not_deleted(): void
    {
        // Le Blade inscrit l'administrateur dans `bookings.user_id` quand il crée une
        // réservation, et la clé est en cascade : supprimer le compte effaçait ses courses.
        [, $token] = $this->login();
        $target = $this->account('utilisateur');
        $booking = Booking::factory()->create(['user_id' => $target->id]);

        $this->asBearer($token)->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertStatus(409)->assertJsonPath('code', 'USER_NOT_DELETABLE');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }
}
