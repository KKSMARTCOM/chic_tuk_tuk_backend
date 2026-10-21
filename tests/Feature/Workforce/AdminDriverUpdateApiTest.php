<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * L'édition d'un agent — ex-Admin\DriverController::update().
 *
 * ⚠️ Les champs de contrat ne sont exigés QUE si l'agent n'a pas de contrat actif — une
 * condition que seul le serveur connaît (voir `UpdateDriver`), pas le formulaire.
 */
class AdminDriverUpdateApiTest extends TestCase
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

    private function entete(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function owner(): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create();
        $owner->assignRole(Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']));

        return $owner;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Agent Modifié',
            'email' => null,
            'phone' => '90'.random_int(100000, 999999),
            'is_active' => true,
            'adresse' => null,
            'license_number' => 'B',
            'is_available' => true,
            'agent_code' => null,
            'agent_id' => null,
            'owner_mode' => 'existing',
            'owner_id' => null,
            'vehicle_id' => null,
            'existing_contract_months' => null,
            'existing_start_date' => null,
            'renewal_agent_code' => null,
            'renewal_agent_id' => null,
            'renewal_owner_id' => null,
            'renewal_vehicle_id' => null,
            'renewal_contract_months' => null,
            'renewal_start_date' => null,
        ], $overrides);
    }

    // ----- Les gardes --------------------------------------------------------

    public function test_sans_edit_drivers_la_modification_est_refusee(): void
    {
        $driver = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload())
            ->assertForbidden();
    }

    // ----- Avec un contrat actif : infos personnelles seules --------------------

    public function test_avec_un_contrat_actif_seules_les_infos_personnelles_changent(): void
    {
        $driver = Driver::factory()->create();
        $vehicleContract = VehicleContract::factory()->create();
        DriverContract::factory()->forVehicleContract($vehicleContract)->create([
            'driver_id' => $driver->id,
            'status' => 'active',
        ]);

        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        // Aucun champ de contrat fourni : ne doit PAS être exigé puisque le contrat est
        // déjà actif — c'est exactement la distinction que `UpdateDriver` porte.
        $response = $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload(['name' => 'Nom Corrigé']))
            ->assertOk();

        $response->assertJsonPath('name', 'Nom Corrigé');
        $this->assertSame('active', $driver->activeDriverContract()->first()->status);
    }

    // ----- Sans contrat actif : mode existing ------------------------------------

    public function test_sans_contrat_actif_le_mode_existing_exige_proprietaire_et_vehicule(): void
    {
        $driver = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_id', 'vehicle_id', 'existing_contract_months', 'existing_start_date']);
    }

    public function test_sans_contrat_actif_le_mode_existing_assigne_un_vehicule(): void
    {
        $driver = Driver::factory()->create();
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-ASSIGNE']);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $response = $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload([
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'existing_contract_months' => 24,
                'existing_start_date' => now()->toDateString(),
            ]))
            ->assertOk();

        $response->assertJsonPath('active_contract.vehicle_number', 'T-ASSIGNE');
    }

    /**
     * Affecter un agent existant ne fermait pas la pause du véhicule : la pause « changement
     * d'agent » restait ouverte, et tous ses jours étaient classés immobilisation (constaté
     * en production le 2026-10-06, pause ouverte depuis le 2 septembre).
     */
    public function test_le_mode_existing_ferme_la_pause_du_vehicule_la_veille(): void
    {
        Carbon::setTestNow('2026-09-09 09:00:00');
        $driver = Driver::factory()->create();
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'is_active' => false]);
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create();
        $pause = VehiclePause::factory()->forContract($vehicleContract)->ongoing()->create([
            // Une pause manuelle d'avant le 2026-10-21 : une `agent_change` exige une reconduction.
            'start_date' => '2026-09-02', 'reason_type' => 'technical', 'is_auto' => false,
        ]);

        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload([
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'existing_contract_months' => 24,
                'existing_start_date' => '2026-09-09',
            ]))
            ->assertOk();

        $this->assertSame('2026-09-08', $pause->fresh()->end_date?->toDateString());
        Carbon::setTestNow();
    }

    // ----- Sans contrat actif : mode renewal -------------------------------------

    public function test_sans_contrat_actif_le_mode_renewal_reconduit_un_contrat_termine(): void
    {
        $driver = Driver::factory()->create();
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-RECOND2']);
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'contract_months' => 24,
            // Commencé avec l'agent qui en est parti : 11 mois travaillés, 13 restants (2026-10-12).
            'start_date' => now()->subMonths(10)->startOfMonth(),
        ]);
        DriverContract::factory()->forVehicleContract($vehicleContract)->create([
            'status' => 'ended',
            'start_date' => now()->subMonths(10)->startOfMonth(),
            'end_date' => now()->subMonth()->endOfMonth(),
        ]);

        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $response = $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload([
                'owner_mode' => 'renewal',
                'renewal_owner_id' => $owner->id,
                'renewal_vehicle_id' => $vehicle->id,
                'renewal_contract_months' => 14,
                'renewal_start_date' => now()->toDateString(),
            ]))
            ->assertOk();

        $response->assertJsonPath('active_contract.vehicle_number', 'T-RECOND2');
        $response->assertJsonPath('active_contract.contract_months', 14);
    }

    public function test_le_formulaire_ne_touche_ni_au_compte_ni_a_la_disponibilite(): void
    {
        $driver = Driver::factory()->create(['is_available' => true]);
        DriverContract::factory()->create(['driver_id' => $driver->id]);
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload(['is_active' => false, 'is_available' => false]))
            ->assertOk();

        $this->assertTrue((bool) $driver->user->refresh()->is_active);
        $this->assertTrue($driver->refresh()->is_available);
    }

    // ----- Les gardes du 2026-10-21 (spec état véhicule/agent, §3.5 et §4.4) -------

    /** Un véhicule dont l'agent est parti : pause `agent_change` en cours. */
    private function vehicleAwaitingRenewal(User $owner): Vehicle
    {
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'is_active' => false]);
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'contract_months' => 24,
            'start_date' => now()->subMonths(10)->startOfMonth(),
        ]);
        $ended = DriverContract::factory()->forVehicleContract($vehicleContract)->create([
            'status' => 'ended',
            'start_date' => now()->subMonths(10)->startOfMonth(),
            'end_date' => now()->subDays(3)->toDateString(),
        ]);
        VehiclePause::factory()->forContract($vehicleContract)->ongoing()->create([
            'driver_contract_id' => $ended->id,
            'start_date' => now()->subDays(2)->toDateString(),
            'reason_type' => 'agent_change',
            'is_auto' => true,
        ]);

        return $vehicle;
    }

    private function renewalPayload(User $owner, Vehicle $vehicle): array
    {
        return $this->payload([
            'is_active' => null,
            'is_available' => null,
            'owner_mode' => 'renewal',
            'renewal_owner_id' => $owner->id,
            'renewal_vehicle_id' => $vehicle->id,
            'renewal_contract_months' => 24,
            'renewal_start_date' => now()->toDateString(),
        ]);
    }

    public function test_un_nouveau_contrat_sur_un_vehicule_quitte_est_refuse(): void
    {
        $driver = Driver::factory()->create();
        $owner = $this->owner();
        $vehicle = $this->vehicleAwaitingRenewal($owner);
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload([
                'is_active' => null,
                'is_available' => null,
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'existing_contract_months' => 24,
                'existing_start_date' => now()->toDateString(),
            ]))
            // Le formulaire enveloppe ses refus en 422, message intact (convention d'`UpdateDriver`).
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ce véhicule attend la reconduction du contrat de son ancien agent.');
    }

    public function test_la_reconduction_dun_compte_ferme_est_refusee(): void
    {
        $driver = Driver::factory()->create();
        $driver->user->update(['is_active' => false]);
        $owner = $this->owner();
        $vehicle = $this->vehicleAwaitingRenewal($owner);
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->renewalPayload($owner, $vehicle))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le compte de cet agent est désactivé : seul un administrateur peut le rouvrir.');
    }

    public function test_un_nouveau_contrat_pour_un_compte_ferme_est_refuse(): void
    {
        $driver = Driver::factory()->create();
        $driver->user->update(['is_active' => false]);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create();
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload([
                'is_active' => null,
                'is_available' => null,
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'existing_contract_months' => 24,
                'existing_start_date' => now()->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le compte de cet agent est désactivé : seul un administrateur peut le rouvrir.');
    }

    public function test_la_reconduction_rend_lagent_disponible_et_ferme_la_pause(): void
    {
        $driver = Driver::factory()->create(['is_available' => false]);
        $owner = $this->owner();
        $vehicle = $this->vehicleAwaitingRenewal($owner);
        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->renewalPayload($owner, $vehicle))
            ->assertOk();

        $this->assertTrue($driver->refresh()->is_available);
        $this->assertNull($vehicle->refresh()->activePause);
    }

    // ----- Unicité, en s'excluant soi-même ---------------------------------------

    public function test_lagent_garde_son_propre_email_et_telephone(): void
    {
        $driver = Driver::factory()->create();
        VehicleContract::factory()->create();
        DriverContract::factory()->create(['driver_id' => $driver->id, 'status' => 'active']);

        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        // Le même téléphone que l'agent lui-même : ne doit PAS être refusé.
        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload(['phone' => $driver->user->phone]))
            ->assertOk();
    }

    /**
     * ⚠️ Le Blade ne vérifiait pas cette unicité en mode existing (seulement en renewal),
     * et `drivers.agent_id` n'a pas d'index unique : deux agents pouvaient partager un ID.
     */
    public function test_en_mode_existing_un_id_agent_dun_autre_agent_est_refuse(): void
    {
        $driver = Driver::factory()->create();
        Driver::factory()->create(['agent_id' => 'AG-PRIS']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload([
                'agent_id' => 'AG-PRIS',
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'existing_contract_months' => 24,
                'existing_start_date' => now()->toDateString(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agent_id');
    }

    public function test_en_mode_existing_lagent_garde_son_propre_id_agent(): void
    {
        $driver = Driver::factory()->create(['agent_id' => 'AG-MIEN']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload([
                'agent_id' => 'AG-MIEN',
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'existing_contract_months' => 24,
                'existing_start_date' => now()->toDateString(),
            ]))
            ->assertOk();
    }

    public function test_un_telephone_dun_autre_agent_est_refuse(): void
    {
        $driver = Driver::factory()->create();
        $autre = Driver::factory()->create();
        VehicleContract::factory()->create();
        DriverContract::factory()->create(['driver_id' => $driver->id, 'status' => 'active']);

        [, $token] = $this->connecter(Profil::Admin, ['edit-drivers']);

        $this->entete($token)
            ->putJson("/api/v1/admin/drivers/{$driver->id}", $this->payload(['phone' => $autre->user->phone]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }
}
