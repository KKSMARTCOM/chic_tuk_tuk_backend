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
 * Le CONTRAT des réservations de l'administration : qui entre, qui est refusé, et ce que
 * le JSON contient.
 *
 * `AdminBookingWritesTest` couvre le comportement des écritures ; ce fichier-ci couvre
 * les gardes et la forme des réponses.
 */
class AdminBookingsApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function login(Profil $profil, array $permissions): array
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
    private function header(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // ----- Les gardes --------------------------------------------------------

    public function test_view_bookings_ouvre_la_liste(): void
    {
        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)->getJson('/api/v1/admin/bookings')->assertOk();
    }

    public function test_sans_view_bookings_la_liste_est_refusee(): void
    {
        [, $token] = $this->login(Profil::Admin, ['view-dashboard']);

        $this->header($token)->getJson('/api/v1/admin/bookings')->assertForbidden();
    }

    public function test_un_agent_ne_peut_pas_ouvrir_les_reservations_de_l_administration(): void
    {
        // ⚠️ Même avec `view-bookings`, que le rôle `driver` porte : `abilities:admin`
        // écarte un jeton émis pour un compte agent.
        [, $token] = $this->login(Profil::Driver, ['view-bookings']);

        $this->header($token)->getJson('/api/v1/admin/bookings')->assertForbidden();
    }

    public function test_les_ecritures_exigent_edit_bookings(): void
    {
        // ⚠️ Les routes Blade correspondantes ne portent que `profil:admin` : l'API est
        // plus stricte, comme sur les pauses. Le profil recouvre `admin` et
        // `utilisateur`, et c'est la permission qui les distingue.
        $booking = Booking::factory()->create(['status' => 'pending', 'driver_id' => null]);
        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)
            ->postJson("/api/v1/admin/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertForbidden();
    }

    public function test_la_suppression_exige_sa_propre_permission(): void
    {
        $booking = Booking::factory()->create();
        [, $token] = $this->login(Profil::Admin, ['view-bookings', 'edit-bookings']);

        $this->header($token)->deleteJson("/api/v1/admin/bookings/{$booking->id}")->assertForbidden();
    }

    // ----- Le contrat --------------------------------------------------------

    public function test_la_liste_tranche_la_nature_de_chaque_course(): void
    {
        /*
         * ⚠️ Le point de ce champ. La vue Blade enchaîne quatre tests d'accesseurs qui se
         * recoupent, et c'est leur ORDRE qui les départage. Le front n'a pas à reproduire
         * cet ordre : le serveur répond `kind`.
         */
        $parent = Booking::factory()->create(['is_recurring' => true, 'parent_booking_id' => null]);
        Booking::factory()->create(['parent_booking_id' => $parent->id, 'is_recurring' => false]);

        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $kinds = collect($this->header($token)->getJson('/api/v1/admin/bookings')->assertOk()->json())
            ->pluck('kind')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['subscription_child', 'subscription_parent'], $kinds);
    }

    public function test_le_filtre_de_statut_et_la_recherche(): void
    {
        // ⚠️ `booking_number` est ENGENDRÉ par le modèle et écrase toute valeur fournie :
        // la recherche se teste sur une colonne que l'on maîtrise.
        Booking::factory()->create(['status' => 'pending', 'from_location' => 'Cotonou, Ganhi']);
        Booking::factory()->create(['status' => 'completed', 'from_location' => 'Parakou, Centre']);

        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)->getJson('/api/v1/admin/bookings?status=pending')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.from_location', 'Cotonou, Ganhi');

        $this->header($token)->getJson('/api/v1/admin/bookings?search=Parakou')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.from_location', 'Parakou, Centre');
    }

    public function test_le_dossier_distingue_les_montants_reels_de_l_estimation(): void
    {
        /*
         * ⚠️ En base, `commission` et `driver_earning` sont NOT NULL et valent 0 par
         * défaut : une course non terminée y porte un zéro qui ressemble à un montant
         * réglé. L'API les rend `null` tant que la course n'est pas terminée, pour que
         * « 0 FCFA » ne puisse pas se lire comme « rien ne sera versé ».
         */
        $booking = Booking::factory()->create([
            'status' => 'pending',
            'base_price' => 3000,
        ]);

        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)->getJson("/api/v1/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('commission', null)
            ->assertJsonPath('driver_earning', null)
            // 15 % de 3000, arrondi au multiple de 50 supérieur. `assertJsonPath`
            // compare STRICTEMENT, et JSON rend 450.0 comme l'entier 450.
            ->assertJsonPath('commission_preview', 450)
            ->assertJsonPath('can_be_cancelled', true);
    }

    public function test_une_reservation_introuvable_donne_404(): void
    {
        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)
            ->getJson('/api/v1/admin/bookings/01930000-0000-7000-8000-000000000000')
            ->assertNotFound();
    }

    public function test_les_agents_affectables_ne_sont_pas_pris_pour_une_reservation(): void
    {
        /*
         * ⚠️ `bookings/assignable-drivers` est déclarée AVANT `bookings/{booking}` :
         * Laravel confronte les routes dans l'ordre, et le chemin satisferait le
         * paramètre `{booking}` si celui-ci venait en premier. On chercherait alors une
         * réservation dont l'identifiant est « assignable-drivers », et l'écran recevrait
         * un 404 sans cause visible.
         */
        $user = User::factory()->profil(Profil::Driver)->create(['name' => 'Awa Dossou']);
        Driver::factory()->create(['user_id' => $user->id, 'is_available' => true]);

        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)->getJson('/api/v1/admin/bookings/assignable-drivers')
            ->assertOk()
            ->assertJsonPath('0.name', 'Awa Dossou');
    }

    public function test_une_liste_vide_est_un_tableau_vide_et_non_une_erreur(): void
    {
        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)->getJson('/api/v1/admin/bookings')->assertOk()->assertExactJson([]);
    }
}
