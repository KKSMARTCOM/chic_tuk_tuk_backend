<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les quatre actions du dossier agent — ex-Admin\DriverController
 * (toggleAvailability, toggleStatus, updatePassword, destroy).
 */
class AdminDriversWritesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function connecter(Profil $profil, array $permissions): array
    {
        $user = User::factory()->profil($profil)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    /** Voir NotificationsApiTest : le garde mémorise l'utilisateur entre deux requêtes. */
    private function entete(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // ----- Disponibilité --------------------------------------------------------

    public function test_edit_drivers_bascule_la_disponibilite(): void
    {
        $driver = Driver::factory()->create(['is_available' => true]);
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->postJson("/api/v1/admin/drivers/{$driver->id}/toggle-availability", ['is_available' => false])
            ->assertOk();

        $this->assertFalse($driver->refresh()->is_available);
    }

    public function test_sans_edit_drivers_la_disponibilite_nest_pas_modifiable(): void
    {
        $driver = Driver::factory()->create(['is_available' => true]);
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $this->entete($token)
            ->postJson("/api/v1/admin/drivers/{$driver->id}/toggle-availability", ['is_available' => false])
            ->assertForbidden();

        $this->assertTrue($driver->refresh()->is_available);
    }

    // ----- Statut du compte -------------------------------------------------------

    public function test_edit_drivers_bascule_le_statut_du_compte(): void
    {
        $driver = Driver::factory()->create();
        $driver->user->update(['is_active' => true]);
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->postJson("/api/v1/admin/drivers/{$driver->id}/toggle-status", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($driver->user->refresh()->is_active);
    }

    // ----- Mot de passe ------------------------------------------------------------

    public function test_edit_drivers_reinitialise_le_mot_de_passe(): void
    {
        $driver = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->postJson("/api/v1/admin/drivers/{$driver->id}/password", [
                'password' => 'NouveauMdp2026!',
                'password_confirmation' => 'NouveauMdp2026!',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('NouveauMdp2026!', $driver->user->refresh()->password));
    }

    public function test_une_confirmation_differente_est_refusee(): void
    {
        $driver = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->postJson("/api/v1/admin/drivers/{$driver->id}/password", [
                'password' => 'NouveauMdp2026!',
                'password_confirmation' => 'Autre2026!',
            ])
            ->assertUnprocessable();
    }

    public function test_un_mot_de_passe_sans_caractere_special_est_refuse(): void
    {
        $driver = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->postJson("/api/v1/admin/drivers/{$driver->id}/password", [
                'password' => 'NouveauMdp2026',
                'password_confirmation' => 'NouveauMdp2026',
            ])
            ->assertUnprocessable();
    }

    // ----- Suppression --------------------------------------------------------------

    public function test_delete_drivers_supprime_un_agent(): void
    {
        $driver = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['delete-drivers']);

        $this->entete($token)
            ->deleteJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('drivers', ['id' => $driver->id]);
    }

    public function test_sans_delete_drivers_la_suppression_est_refusee(): void
    {
        $driver = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $this->entete($token)
            ->deleteJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('drivers', ['id' => $driver->id]);
    }

    /**
     * ⚠️ Reprend la règle du Blade, jamais transposée dans une Data ou une validation
     * visible ailleurs : un agent avec une course confirmée ou en cours ne se supprime
     * pas. La supprimer laisserait la course orpheline de son agent.
     */
    public function test_un_agent_avec_une_course_en_cours_nest_pas_supprimable(): void
    {
        $driver = Driver::factory()->create();
        Booking::factory()->confirmed($driver)->create();
        [, $token] = $this->connecter(Profil::Admin, ['delete-drivers']);

        $this->entete($token)
            ->deleteJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'DRIVER_NOT_DELETABLE');

        $this->assertDatabaseHas('drivers', ['id' => $driver->id]);
    }
}
