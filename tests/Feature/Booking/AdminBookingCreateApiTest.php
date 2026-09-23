<?php

namespace Tests\Feature\Booking;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Créer une réservation depuis l'administration, et obtenir un devis.
 *
 * ⚠️ `PricingService::getDistance()` appelle réellement OpenRouteService : chaque test
 * qui atteint `BookingService::create()` fausse la réponse avec `Http::fake()`, sinon il
 * partirait sur le réseau et échouerait en CI comme en local sans connexion.
 */
class AdminBookingCreateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 12 km, comme le rend l'API OpenRouteService pour un trajet urbain courant.
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

    /** Voir NotificationsApiTest : le garde mémorise l'utilisateur entre deux requêtes. */
    private function header(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
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
            'pickup_time' => '14:00',
            'base_price' => 2000,
        ], $extra);
    }

    // ----- Les gardes --------------------------------------------------------

    public function test_create_bookings_ouvre_la_creation(): void
    {
        [, $token] = $this->login(Profil::Admin, ['create-bookings']);

        $this->header($token)->postJson('/api/v1/admin/bookings', $this->payload())
            ->assertCreated();
    }

    public function test_sans_create_bookings_la_creation_est_refusee(): void
    {
        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)->postJson('/api/v1/admin/bookings', $this->payload())
            ->assertForbidden();
    }

    public function test_le_devis_exige_create_ou_edit_bookings(): void
    {
        [, $token] = $this->login(Profil::Admin, ['view-bookings']);

        $this->header($token)->postJson('/api/v1/admin/bookings/quote', [
            'from_lat' => 6.36, 'from_lng' => 2.38, 'to_lat' => 6.37, 'to_lng' => 2.35,
        ])->assertForbidden();

        [, $autre] = $this->login(Profil::Admin, ['edit-bookings']);

        $this->header($autre)->postJson('/api/v1/admin/bookings/quote', [
            'from_lat' => 6.36, 'from_lng' => 2.38, 'to_lat' => 6.37, 'to_lng' => 2.35,
        ])->assertOk();
    }

    // ----- Le devis ------------------------------------------------------------

    public function test_le_devis_rend_le_detail_du_calcul(): void
    {
        [, $token] = $this->login(Profil::Admin, ['create-bookings']);

        $this->header($token)->postJson('/api/v1/admin/bookings/quote', [
            'from_lat' => 6.36, 'from_lng' => 2.38, 'to_lat' => 6.37, 'to_lng' => 2.35,
            'pickup_time' => '14:00',
        ])->assertOk()
            ->assertJsonStructure([
                'distance_km', 'base_price', 'go_price', 'return_price',
                'trip_price', 'days', 'total_price', 'surcharge_amount', 'surcharge_free_window',
            ]);
    }

    // ----- La création -----------------------------------------------------------

    public function test_aucune_anticipation_de_24_heures_n_est_exigee(): void
    {
        /*
         * ⚠️ Différence assumée avec le tunnel public : le formulaire Blade borne
         * `pickup_date` à AUJOURD'HUI, pas à demain. Un administrateur saisit souvent
         * une course qu'un client vient de demander par téléphone.
         */
        [, $token] = $this->login(Profil::Admin, ['create-bookings']);

        $this->header($token)->postJson('/api/v1/admin/bookings', $this->payload([
            'pickup_date' => now()->toDateString(),
            'pickup_time' => now()->addMinutes(5)->format('H:i'),
        ]))->assertCreated();
    }

    public function test_le_prix_saisi_est_accepte_tel_quel(): void
    {
        // ⚠️ Seule route de création qui accepte un prix : un administrateur peut
        // négocier un tarif, ce que le tunnel public ne permet jamais.
        //
        // ⚠️ L'heure de départ choisie (08:00) tombe dans la tranche 6h-10h SANS
        // majoration : hors de cette tranche, `applyTimeSurcharge()` ajoute 1 000 FCFA
        // au-dessus du prix saisi, et le montant enregistré ne serait plus celui envoyé.
        [, $token] = $this->login(Profil::Admin, ['create-bookings']);

        $id = $this->header($token)->postJson('/api/v1/admin/bookings', $this->payload([
            'base_price' => 1500,
            'pickup_time' => '08:00',
        ]))->assertCreated()->json('id');

        $this->assertSame(1500.0, (float) Booking::find($id)->base_price);
    }

    public function test_sans_nom_de_client_le_nom_par_defaut_est_pose(): void
    {
        [, $token] = $this->login(Profil::Admin, ['create-bookings']);

        $payload = $this->payload();
        unset($payload['client_name']);

        $id = $this->header($token)->postJson('/api/v1/admin/bookings', $payload)
            ->assertCreated()->json('id');

        $this->assertSame('Client', Booking::find($id)->client_name);
    }

    public function test_le_premier_jour_doit_tomber_dans_les_jours_de_circulation(): void
    {
        /*
         * ⚠️ Même défaut que celui fermé côté public le 2026-09-18 : sans cette
         * validation, `BookingService::create()` le découvre en levant une \Exception
         * générique, et l'administrateur ne saurait pas pourquoi sa réservation a
         * échoué.
         */
        [, $token] = $this->login(Profil::Admin, ['create-bookings']);

        $prochainSamedi = now()->addDays(2)->next(CarbonInterface::SATURDAY)->toDateString();

        $this->header($token)->postJson('/api/v1/admin/bookings', $this->payload([
            'days' => 20,
            'week_days' => 'lun_ven',
            'pickup_date' => $prochainSamedi,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['pickup_date']);
    }

    public function test_une_nouvelle_reservation_notifie_les_agents_et_les_administrateurs(): void
    {
        // Passe par BookingService::create(), qui appelle déjà le Notifier — aucune
        // nouvelle logique de routage à tester ici, seulement qu'elle est bien atteinte.
        User::factory()->profil(Profil::Driver)->create();
        [$admin, $token] = $this->login(Profil::Admin, ['create-bookings']);

        $this->header($token)->postJson('/api/v1/admin/bookings', $this->payload())
            ->assertCreated();

        $this->assertGreaterThan(0, Notification::count());
    }
}
