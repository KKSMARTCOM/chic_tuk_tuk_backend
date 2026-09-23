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
 * La création d'un agent — ex-Admin\DriverController::create()/store().
 *
 * ⚠️ Le mode `renewal` reconduit un contrat déjà TERMINÉ sur un véhicule dont le contrat
 * propriétaire court encore ; le mode `new` peut créer un agent SANS aucune affectation.
 */
class AdminDriverCreateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `DriverService::createDriver()` fait `$user->assignRole('driver')` : le rôle
        // doit exister, ce qu'une base de test fraîche ne fait pas sans le seeder de
        // référence.
        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    }

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
            'name' => 'Nouvel Agent',
            'email' => null,
            'phone' => '90'.random_int(100000, 999999),
            'password' => 'MotDePasse2026!',
            'adresse' => null,
            'license_number' => 'B',
            'agent_code' => null,
            'agent_id' => null,
            'contract_mode' => 'new',
            'owner_id' => null,
            'vehicle_id' => null,
            'contract_months' => null,
            'start_date' => null,
            'renewal_agent_code' => null,
            'renewal_agent_id' => null,
            'renewal_owner_id' => null,
            'renewal_vehicle_id' => null,
            'renewal_contract_months' => null,
            'renewal_start_date' => null,
        ], $overrides);
    }

    // ----- Les gardes --------------------------------------------------------

    public function test_create_drivers_ouvre_la_creation(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['create-drivers']);

        $this->entete($token)
            ->postJson('/api/v1/admin/drivers', $this->payload())
            ->assertCreated();
    }

    public function test_sans_create_drivers_la_creation_est_refusee(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['view-drivers']);

        $this->entete($token)
            ->postJson('/api/v1/admin/drivers', $this->payload())
            ->assertForbidden();
    }

    // ----- Le sélecteur --------------------------------------------------------

    public function test_le_selecteur_nouveau_contrat_ne_propose_que_des_vehicules_disponibles(): void
    {
        $owner = $this->owner();
        $libre = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-LIBRE']);
        $occupe = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-OCCUPE']);
        DriverContract::factory()->create(['vehicle_id' => $occupe->id, 'status' => 'active']);

        [, $token] = $this->connecter(Profil::Admin, ['create-drivers']);

        $response = $this->entete($token)
            ->getJson('/api/v1/admin/drivers/owners-for-new-contract')
            ->assertOk();

        $vehicles = collect($response->json())->firstWhere('id', $owner->id)['vehicles'];
        $this->assertCount(1, $vehicles);
        $this->assertSame('T-LIBRE', $vehicles[0]['vehicle_number']);
    }

    // ----- La création -----------------------------------------------------------

    public function test_cree_un_agent_sans_aucune_affectation(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['create-drivers']);

        $response = $this->entete($token)
            ->postJson('/api/v1/admin/drivers', $this->payload(['name' => 'Awa Sans Véhicule']))
            ->assertCreated();

        $response->assertJsonPath('name', 'Awa Sans Véhicule');
        $response->assertJsonPath('is_active', true);
        $response->assertJsonPath('is_available', true);
        $response->assertJsonPath('active_contract', null);
    }

    public function test_cree_un_agent_avec_un_nouveau_contrat(): void
    {
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-9001']);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        [, $token] = $this->connecter(Profil::Admin, ['create-drivers']);

        $response = $this->entete($token)
            ->postJson('/api/v1/admin/drivers', $this->payload([
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'contract_months' => 24,
                'start_date' => now()->toDateString(),
            ]))
            ->assertCreated();

        $response->assertJsonPath('active_contract.vehicle_number', 'T-9001');
        $response->assertJsonPath('active_contract.contract_months', 24);
    }

    public function test_cree_un_agent_en_reconduisant_un_contrat_termine(): void
    {
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-RECOND']);
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create(['contract_months' => 24]);
        DriverContract::factory()->forVehicleContract($vehicleContract)->create([
            'status' => 'ended',
            'start_date' => now()->subMonths(10)->startOfMonth(),
            'end_date' => now()->subMonth()->endOfMonth(),
        ]);

        [, $token] = $this->connecter(Profil::Admin, ['create-drivers']);

        $response = $this->entete($token)
            ->postJson('/api/v1/admin/drivers', $this->payload([
                'contract_mode' => 'renewal',
                'renewal_owner_id' => $owner->id,
                'renewal_vehicle_id' => $vehicle->id,
                'renewal_contract_months' => 14,
                'renewal_start_date' => now()->toDateString(),
            ]))
            ->assertCreated();

        $response->assertJsonPath('active_contract.vehicle_number', 'T-RECOND');
        $response->assertJsonPath('active_contract.contract_months', 14);
    }

    public function test_un_telephone_deja_utilise_est_refuse(): void
    {
        $existant = Driver::factory()->create();
        [, $token] = $this->connecter(Profil::Admin, ['create-drivers']);

        $this->entete($token)
            ->postJson('/api/v1/admin/drivers', $this->payload(['phone' => $existant->user->phone]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_un_mot_de_passe_sans_caractere_special_est_refuse(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['create-drivers']);

        $this->entete($token)
            ->postJson('/api/v1/admin/drivers', $this->payload(['password' => 'MotDePasse2026']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_la_reconduction_exige_un_proprietaire_et_un_vehicule(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['create-drivers']);

        $this->entete($token)
            ->postJson('/api/v1/admin/drivers', $this->payload(['contract_mode' => 'renewal']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['renewal_owner_id', 'renewal_vehicle_id', 'renewal_contract_months', 'renewal_start_date']);
    }
}
