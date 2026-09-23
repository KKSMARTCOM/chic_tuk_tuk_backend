<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * La liste des agents et leur dossier, en LECTURE SEULE — ex-Admin\DriverController
 * (index, show). Le sous-lot suivant couvrira création, édition et actions.
 */
class AdminDriversApiTest extends TestCase
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

    // ----- Les gardes --------------------------------------------------------

    public function test_view_drivers_ouvre_la_liste(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $this->entete($token)->getJson('/api/v1/admin/drivers')->assertOk();
    }

    public function test_sans_view_drivers_la_liste_est_refusee(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['view-dashboard']);

        $this->entete($token)->getJson('/api/v1/admin/drivers')->assertForbidden();
    }

    public function test_sans_view_drivers_le_dossier_est_refuse(): void
    {
        $driver = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['view-dashboard']);

        $this->entete($token)->getJson("/api/v1/admin/drivers/{$driver->id}")->assertForbidden();
    }

    public function test_un_agent_introuvable_donne_404(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $this->entete($token)->getJson('/api/v1/admin/drivers/'.fake()->uuid())->assertNotFound();
    }

    // ----- La liste ------------------------------------------------------------

    public function test_la_liste_porte_les_deux_identifiants_et_les_stats(): void
    {
        $driver = Driver::factory()->create(['total_trips' => 3]);
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $response = $this->entete($token)->getJson('/api/v1/admin/drivers')->assertOk();

        $response->assertJsonPath('drivers.0.id', $driver->id);
        $response->assertJsonPath('drivers.0.user_id', $driver->user_id);
        $response->assertJsonPath('drivers.0.total_trips', 3);
        $response->assertJsonPath('stats.total', 1);
        $response->assertJsonPath('stats.active', 1);
        $response->assertJsonPath('stats.available', 1);
    }

    public function test_la_recherche_porte_sur_le_nom_lemail_le_telephone_et_le_permis(): void
    {
        $cherche = User::factory()->profil(Profil::Driver)->create(['name' => 'Kofi Mensah']);
        Driver::factory()->create(['user_id' => $cherche->id]);
        Driver::factory()->create(); // un autre agent, non cherché

        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $response = $this->entete($token)
            ->getJson('/api/v1/admin/drivers?search=Kofi')
            ->assertOk();

        $response->assertJsonCount(1, 'drivers');
        $response->assertJsonPath('drivers.0.name', 'Kofi Mensah');
    }

    /**
     * ⚠️ Le test qui ferme le défaut trouvé sur staging : la recherche interrogeait
     * `drivers.vehicle_number`, une colonne que les migrations du dépôt déclarent mais que
     * la base de staging n'a plus (`column "vehicle_number" does not exist`, constaté en
     * production le 2026-09-23). Cette clause faisait partie de TOUTE recherche, donc
     * TOUTE recherche y échouait. La plaque cherchée doit venir du véhicule ACTUEL de
     * l'agent, via `currentVehicle` (table `vehicles`), jamais de `drivers` directement.
     */
    public function test_la_recherche_par_plaque_porte_sur_le_vehicule_actuel(): void
    {
        $driver = Driver::factory()->create();
        $vehicle = Vehicle::factory()->create(['vehicle_number' => 'T-9001']);
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create();
        DriverContract::factory()->forVehicleContract($vehicleContract)->create(['driver_id' => $driver->id]);

        Driver::factory()->create(); // un autre agent, sans ce véhicule

        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $response = $this->entete($token)
            ->getJson('/api/v1/admin/drivers?search=T-9001')
            ->assertOk();

        $response->assertJsonCount(1, 'drivers');
        $response->assertJsonPath('drivers.0.id', $driver->id);
    }

    /**
     * ⚠️ Le test qui ferme le défaut trouvé en comparant au Blade : choisir explicitement
     * « Tous les statuts » soumet `is_active=` (chaîne vide), que `DriverService`
     * traitait comme une vraie valeur de filtre — une comparaison Postgres invalide sur
     * une colonne booléenne, capturée en 500 par le contrôleur. Corrigé aussi côté Blade.
     */
    public function test_un_filtre_vide_ne_casse_pas_la_liste(): void
    {
        Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $this->entete($token)
            ->getJson('/api/v1/admin/drivers?is_active=&is_available=')
            ->assertOk()
            ->assertJsonCount(1, 'drivers');
    }

    public function test_le_filtre_de_disponibilite_porte_sur_la_valeur_reelle(): void
    {
        Driver::factory()->create(['is_available' => true]);
        Driver::factory()->create(['is_available' => false]);
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $this->entete($token)
            ->getJson('/api/v1/admin/drivers?is_available=1')
            ->assertOk()
            ->assertJsonCount(1, 'drivers');
    }

    // ----- Le dossier ------------------------------------------------------------

    public function test_le_dossier_porte_les_stats_de_courses_et_de_commissions(): void
    {
        $driver = Driver::factory()->create();
        Booking::factory()->completed($driver)->create();
        Booking::factory()->completed($driver)->create();
        Booking::factory()->confirmed($driver)->create();

        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $response = $this->entete($token)
            ->getJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertOk();

        $response->assertJsonPath('booking_stats.total', 3);
        $response->assertJsonPath('booking_stats.completed', 2);
        $response->assertJsonPath('booking_stats.confirmed', 1);
        // json_encode() rend un float sans décimales comme un entier — 8500, pas 8500.0.
        $response->assertJsonPath('commission_stats.driver_earning', 8500);
    }

    public function test_le_dossier_naffiche_aucun_contrat_sans_contrat_actif(): void
    {
        $driver = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $this->entete($token)
            ->getJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertOk()
            ->assertJsonPath('active_contract', null);
    }

    public function test_le_dossier_porte_le_contrat_vehicule_actif(): void
    {
        $driver = Driver::factory()->create();
        $vehicle = Vehicle::factory()->create(['vehicle_number' => 'T-9001']);
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create();
        DriverContract::factory()->forVehicleContract($vehicleContract)->create([
            'driver_id' => $driver->id,
            'contract_months' => 24,
        ]);

        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $response = $this->entete($token)
            ->getJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertOk();

        $response->assertJsonPath('active_contract.vehicle_number', 'T-9001');
        $response->assertJsonPath('active_contract.contract_months', 24);
        $response->assertJsonPath('active_contract.editable', true);
    }

    public function test_le_dossier_montre_les_5_dernieres_courses_les_plus_recentes_dabord(): void
    {
        $driver = Driver::factory()->create();
        for ($i = 0; $i < 7; $i++) {
            Booking::factory()->completed($driver)->create([
                'pickup_date' => now()->addDays($i)->toDateString(),
                'from_location' => "Course {$i}",
            ]);
        }

        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $response = $this->entete($token)
            ->getJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertOk();

        $response->assertJsonCount(5, 'recent_bookings');
        $response->assertJsonPath('recent_bookings.0.from_location', 'Course 6');
    }

    public function test_le_dossier_porte_le_revenu_abonnement_deja_construit(): void
    {
        $driver = Driver::factory()->create();
        $parent = Booking::factory()->completed($driver)->create([
            'is_recurring' => true,
            'driver_earning' => 1000,
        ]);
        Booking::factory()->completed($driver)->create([
            'parent_booking_id' => $parent->id,
            'driver_earning' => 1000,
        ]);

        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $response = $this->entete($token)
            ->getJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertOk();

        $response->assertJsonPath('subscription_revenue.total_due', 2000);
        $response->assertJsonCount(1, 'subscription_revenue.subscriptions');
    }
}
