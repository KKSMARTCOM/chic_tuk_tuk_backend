<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
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

    // ----- Sans contrat actif : mode renewal -------------------------------------

    public function test_sans_contrat_actif_le_mode_renewal_reconduit_un_contrat_termine(): void
    {
        $driver = Driver::factory()->create();
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-RECOND2']);
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create(['contract_months' => 24]);
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
