<?php

namespace Tests\Feature\Booking;

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
 * GET /api/v1/admin/dashboard.
 *
 * Premier endpoint de l'espace administration. Les compteurs sont transposés requête par
 * requête depuis `DashboardController::admin()` : ces tests vérifient qu'ils comptent la
 * même chose, et que la garde laisse passer les bons comptes — et eux seuls.
 */
class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function connecter(Profil $profil, array $permissions = ['view-dashboard']): array
    {
        $user = User::factory()->profil($profil)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(
                Permission::firstOrCreate(
                    ['name' => $permission, 'guard_name' => 'web'],
                ),
            );
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

    // ----- La garde ----------------------------------------------------------

    public function test_un_administrateur_avec_la_permission_entre(): void
    {
        [, $token] = $this->connecter(Profil::Admin);

        $this->entete($token)->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_un_administrateur_sans_la_permission_est_refuse(): void
    {
        // ⚠️ Le profil ne suffit pas : `lecteur` est aussi de profil `admin`, et le
        // catalogue de permissions est ce qui distingue les deux rôles.
        [, $token] = $this->connecter(Profil::Admin, permissions: []);

        $this->entete($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_un_agent_ne_peut_pas_ouvrir_le_tableau_de_bord_admin(): void
    {
        // Même avec `view-dashboard`, qu'il porte légitimement pour le SIEN :
        // `abilities:admin` écarte le jeton, émis pour un compte agent.
        [, $token] = $this->connecter(Profil::Driver);

        $this->entete($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_sans_jeton_c_est_401(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    // ----- Les compteurs -----------------------------------------------------

    public function test_les_compteurs_generaux_comptent_ce_que_le_blade_comptait(): void
    {
        Booking::factory()->count(3)->create(['status' => 'pending']);
        Booking::factory()->count(2)->create(['status' => 'completed', 'total_price' => 1500]);
        Driver::factory()->count(2)->create(['is_available' => true]);
        Driver::factory()->create(['is_available' => false]);

        [, $token] = $this->connecter(Profil::Admin);

        $this->entete($token)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('total_bookings', 5)
            ->assertJsonPath('pending_bookings', 3)
            ->assertJsonPath('total_drivers', 3)
            ->assertJsonPath('active_drivers', 2)
            ->assertJsonPath('total_revenue', 3000);
    }

    public function test_chaque_compteur_du_jour_porte_sur_sa_propre_date(): void
    {
        // ⚠️ Le point le plus facile à casser en transposant. Une course TERMINÉE
        // aujourd'hui a pu être démarrée hier : la compter sur `started_at` la ferait
        // disparaître du compteur « complétées ». Chaque compteur date l'événement qu'il
        // nomme, pas la course.
        Booking::factory()->create([
            'status' => 'completed',
            'started_at' => now()->subDay(),
            'completed_at' => now(),
        ]);
        Booking::factory()->create(['status' => 'in_progress', 'started_at' => now()]);
        Booking::factory()->create(['status' => 'cancelled', 'cancelled_at' => now()]);
        // Terminée hier : ne compte dans aucun compteur du jour.
        Booking::factory()->create(['status' => 'completed', 'completed_at' => now()->subDay()]);

        [, $token] = $this->connecter(Profil::Admin);

        $this->entete($token)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('completed_today', 1)
            ->assertJsonPath('in_progress_today', 1)
            ->assertJsonPath('cancelled_today', 1);
    }

    public function test_les_reservations_recentes_sont_les_dix_dernieres_en_attente(): void
    {
        Booking::factory()->count(12)->create(['status' => 'pending']);
        Booking::factory()->count(3)->create(['status' => 'completed']);

        [, $token] = $this->connecter(Profil::Admin);

        $reponse = $this->entete($token)->getJson('/api/v1/admin/dashboard')->assertOk();

        $this->assertCount(10, $reponse->json('recent_pending'));
    }

    public function test_les_reservations_recentes_portent_les_coordonnees_du_client(): void
    {
        // ⚠️ Contrairement à l'espace agent, où aucune coordonnée ne voyage avant
        // acceptation : un administrateur voit tout le dossier, c'est le sens de son rôle.
        Booking::factory()->create([
            'status' => 'pending',
            'client_name' => 'Awa Dossou',
            'phone' => '97000000',
        ]);

        [, $token] = $this->connecter(Profil::Admin);

        $this->entete($token)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_pending.0.client_name', 'Awa Dossou')
            ->assertJsonPath('recent_pending.0.phone', '97000000');
    }

    public function test_l_heure_de_prise_en_charge_part_sans_decalage(): void
    {
        // La règle du projet : `pickup_date` + `pickup_time` décrivent une heure MURALE.
        // L'application tourne en UTC et le Bénin est à UTC+1 ; suffixer un décalage
        // déplacerait l'affichage d'une heure.
        Booking::factory()->create([
            'status' => 'pending',
            'pickup_date' => '2026-10-05',
            'pickup_time' => '08:00:00',
        ]);

        [, $token] = $this->connecter(Profil::Admin);

        $this->entete($token)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_pending.0.pickup_at', '2026-10-05T08:00:00');
    }

    public function test_une_liste_vide_est_un_tableau_vide_et_non_une_erreur(): void
    {
        [, $token] = $this->connecter(Profil::Admin);

        $this->entete($token)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_pending', [])
            ->assertJsonPath('top_drivers', []);
    }
}
