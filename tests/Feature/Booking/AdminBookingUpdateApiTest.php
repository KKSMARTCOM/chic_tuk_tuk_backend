<?php

namespace Tests\Feature\Booking;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Modifier le trajet et le prix d'une réservation EN ATTENTE.
 *
 * ⚠️ Le point central de ce fichier : le formulaire Blade validait `status` dans le
 * MÊME corps que l'édition du trajet, donc un administrateur pouvait confirmer ou
 * annuler une course sans passer par `ChangeBookingStatus` — sans matrice de
 * transitions, sans notification. `UpdateAdminBookingData` n'accepte aucun champ de
 * statut ; ces tests le vérifient à l'appel HTTP, pas seulement dans la Data class.
 */
class AdminBookingUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.openrouteservice.org/*' => Http::response([
                'routes' => [['summary' => ['distance' => 12_000]]],
            ], 200),
        ]);
    }

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

    private function header(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function pending(array $extra = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'status' => 'pending',
            'from_location' => 'Cadjehoun', 'to_location' => 'Fidjrosse',
            'from_lat' => 6.36, 'from_lng' => 2.38,
            'to_lat' => 6.37, 'to_lng' => 2.35,
            'base_price' => 2000,
        ], $extra));
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'client_name' => 'Awa Dossou',
            'phone' => '+22997000000',
            'from_location' => 'Cadjehoun',
            'to_location' => 'Fidjrosse',
            'from_lat' => 6.36, 'from_lng' => 2.38,
            'to_lat' => 6.37, 'to_lng' => 2.35,
            'pickup_date' => now()->toDateString(),
            'pickup_time' => '08:00',
            'base_price' => 2500,
        ], $extra);
    }

    // ----- Les gardes --------------------------------------------------------

    public function test_edit_bookings_ouvre_la_modification(): void
    {
        $booking = $this->pending();
        [, $token] = $this->login(Profil::Admin, ['edit-bookings']);

        $this->header($token)->putJson("/api/v1/admin/bookings/{$booking->id}", $this->payload())
            ->assertOk();
    }

    public function test_sans_edit_bookings_la_modification_est_refusee(): void
    {
        $booking = $this->pending();
        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)->putJson("/api/v1/admin/bookings/{$booking->id}", $this->payload())
            ->assertForbidden();
    }

    // ----- Le cycle de vie -----------------------------------------------------

    public function test_seule_une_reservation_en_attente_se_modifie(): void
    {
        /*
         * ⚠️ Le contrôleur Blade acceptait n'importe quel statut de départ : seule la
         * VUE ne montrait pas le lien « Modifier » ailleurs qu'en attente. Un appel
         * direct contournait donc la règle.
         */
        $booking = $this->pending(['status' => 'confirmed']);
        [, $token] = $this->login(Profil::Admin, ['edit-bookings']);

        $this->header($token)->putJson("/api/v1/admin/bookings/{$booking->id}", $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'BOOKING_NOT_EDITABLE');
    }

    public function test_une_course_fille_d_abonnement_en_attente_reste_modifiable(): void
    {
        // Parité avec le Blade : sa garde ne porte que sur le statut, pas sur la nature
        // de la course.
        $parent = $this->pending(['is_recurring' => true, 'parent_booking_id' => null]);
        $fille = $this->pending(['parent_booking_id' => $parent->id, 'is_recurring' => false]);
        [, $token] = $this->login(Profil::Admin, ['edit-bookings']);

        $this->header($token)->putJson("/api/v1/admin/bookings/{$fille->id}", $this->payload())
            ->assertOk();
    }

    // ----- Le contrat --------------------------------------------------------

    public function test_le_statut_ne_peut_pas_etre_change_par_ce_chemin(): void
    {
        /*
         * ⚠️ LE défaut que ce fichier ferme. `UpdateAdminBookingData` n'a pas de champ
         * `status` : même envoyé dans le corps, il est ignoré silencieusement — comme
         * n'importe quel champ non déclaré d'une classe Data. Le statut reste `pending`
         * après l'appel.
         */
        $booking = $this->pending();
        [, $token] = $this->login(Profil::Admin, ['edit-bookings']);

        $this->header($token)->putJson("/api/v1/admin/bookings/{$booking->id}", $this->payload([
            'status' => 'cancelled',
        ]))->assertOk()->assertJsonPath('status', 'pending');

        $this->assertSame('pending', $booking->refresh()->status);
    }

    public function test_le_trajet_et_le_prix_sont_mis_a_jour(): void
    {
        $booking = $this->pending();
        [, $token] = $this->login(Profil::Admin, ['edit-bookings']);

        $this->header($token)->putJson("/api/v1/admin/bookings/{$booking->id}", $this->payload([
            'from_location' => 'Akpakpa',
            'base_price' => 3000,
        ]))->assertOk();

        $booking->refresh();
        $this->assertSame('Akpakpa', $booking->from_location);
        $this->assertSame(3000.0, (float) $booking->base_price);
    }

    public function test_une_reservation_introuvable_donne_404(): void
    {
        [, $token] = $this->login(Profil::Admin, ['edit-bookings']);

        $this->header($token)
            ->putJson('/api/v1/admin/bookings/01930000-0000-7000-8000-000000000000', $this->payload())
            ->assertNotFound();
    }
}
