<?php

namespace Tests\Feature\Fleet;

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
 * L'édition d'un propriétaire — ex-Admin\OwnerController::update().
 *
 * Trois choses dans la même requête : l'identité, les véhicules qu'il possède déjà (sauf
 * ceux qu'un agent conduit, en lecture seule), et l'ajout éventuel d'un véhicule.
 */
class AdminOwnerUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
    }

    private function login(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function owner(array $attributes = []): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create($attributes);
        $owner->assignRole('proprietaire');

        return $owner;
    }

    private function identity(User $owner, array $overrides = []): array
    {
        return array_merge([
            'name' => $owner->name,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'adresse' => $owner->adresse,
            'is_active' => true,
            'vehicles' => [],
            'add_vehicle' => null,
            'confirm_transfer' => false,
        ], $overrides);
    }

    private function contract(array $overrides = []): array
    {
        return array_merge([
            'contract_months' => 36,
            'total_amount' => 4_049_100,
            'start_date' => '2026-10-01',
            'unlimited_internet' => 5_000,
            'spotify_premium' => 2_500,
            'manager_remuneration' => 20_000,
        ], $overrides);
    }

    private function update(string $token, User $owner, array $payload)
    {
        return $this->asBearer($token)->putJson("/api/v1/admin/owners/{$owner->id}", $payload);
    }

    public function test_without_edit_owners_the_update_is_refused(): void
    {
        $token = $this->login(['view-owners']);
        $owner = $this->owner();

        $this->update($token, $owner, $this->identity($owner))->assertForbidden();
    }

    public function test_the_identity_is_updated_and_the_owner_keeps_his_own_phone(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner(['phone' => '97111111']);

        $this->update($token, $owner, $this->identity($owner, ['name' => 'Nom Corrigé', 'is_active' => false]))
            ->assertOk()
            ->assertJsonPath('name', 'Nom Corrigé')
            ->assertJsonPath('is_active', false);

        $this->assertSame('97111111', $owner->fresh()->phone);
    }

    public function test_a_phone_of_another_account_is_refused(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();
        $agent = Driver::factory()->create()->user;

        $this->update($token, $owner, $this->identity($owner, ['phone' => $agent->phone]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_a_free_vehicle_and_its_contract_are_updated_notes_included(): void
    {
        // Défaut corrigé : le service écrivait `vehicle_notes`, une colonne qui n'existe
        // pas et que `$fillable` ignorait — les notes d'un véhicule étaient perdues.
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'notes' => 'Ancienne note']);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['contract_months' => 24]);

        $this->update($token, $owner, $this->identity($owner, [
            'vehicles' => [[
                'id' => $vehicle->id,
                'vehicle_number' => 'BJ-9999-ZZ',
                'vehicle_type' => 'moto',
                'notes' => 'Nouvelle note',
                'contract' => $this->contract(),
            ]],
        ]))->assertOk();

        $vehicle->refresh();
        $this->assertSame('BJ-9999-ZZ', $vehicle->vehicle_number);
        $this->assertSame('moto', $vehicle->vehicle_type);
        $this->assertSame('Nouvelle note', $vehicle->notes);

        $contract->refresh();
        $this->assertSame(36, $contract->contract_months);
        $this->assertEquals(4_049_100, $contract->total_amount);
        $this->assertSame(1, VehicleContract::query()->count(), 'le contrat actif est modifié, pas doublé');
    }

    public function test_a_free_vehicle_without_contract_gets_one(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);

        $this->update($token, $owner, $this->identity($owner, [
            'vehicles' => [[
                'id' => $vehicle->id,
                'vehicle_number' => $vehicle->vehicle_number,
                'vehicle_type' => 'tricycle',
                'notes' => null,
                'contract' => $this->contract(['notes' => 'Signé en agence']),
            ]],
        ]))->assertOk()->assertJsonPath('vehicles.0.contract.contract_months', 36);

        $contract = $vehicle->fresh()->activeVehicleContract;
        $this->assertSame($owner->id, $contract->owner_id);
        $this->assertSame('Signé en agence', $contract->notes);
    }

    public function test_a_vehicle_driven_by_an_agent_is_left_untouched(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-0007']);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['contract_months' => 24]);
        DriverContract::factory()->create([
            'vehicle_id' => $vehicle->id,
            'vehicle_contract_id' => $contract->id,
            'status' => 'active',
        ]);

        $this->update($token, $owner, $this->identity($owner, [
            'vehicles' => [[
                'id' => $vehicle->id,
                'vehicle_number' => 'T-CHANGE',
                'vehicle_type' => 'moto',
                'notes' => null,
                'contract' => $this->contract(),
            ]],
        ]))->assertOk();

        $this->assertSame('T-0007', $vehicle->fresh()->vehicle_number);
        $this->assertSame(24, $contract->fresh()->contract_months);
    }

    public function test_a_vehicle_of_another_owner_in_the_list_is_ignored(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();
        $foreign = Vehicle::factory()->create(['vehicle_number' => 'T-AUTRUI']);

        $this->update($token, $owner, $this->identity($owner, [
            'vehicles' => [[
                'id' => $foreign->id,
                'vehicle_number' => 'T-VOLE',
                'vehicle_type' => 'tricycle',
                'notes' => null,
                'contract' => null,
            ]],
        ]))->assertOk();

        $this->assertSame('T-AUTRUI', $foreign->fresh()->vehicle_number);
    }

    public function test_a_vehicle_number_already_used_is_refused(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        Vehicle::factory()->create(['vehicle_number' => 'T-PRIS']);

        $this->update($token, $owner, $this->identity($owner, [
            'vehicles' => [[
                'id' => $vehicle->id,
                'vehicle_number' => 'T-PRIS',
                'vehicle_type' => 'tricycle',
                'notes' => null,
                'contract' => null,
            ]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['vehicles.0.vehicle_number']);
    }

    public function test_a_new_vehicle_is_added_with_its_notes_and_optional_contract(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();

        $this->update($token, $owner, $this->identity($owner, [
            'add_vehicle' => [
                'mode' => 'new',
                'vehicle_number' => 'BJ-NEUF-01',
                'vehicle_type' => 'car',
                'notes' => 'Ajouté en édition',
                'contract' => null,
            ],
        ]))->assertOk();

        $vehicle = Vehicle::query()->where('vehicle_number', 'BJ-NEUF-01')->firstOrFail();
        $this->assertSame($owner->id, $vehicle->owner_id);
        $this->assertSame('Ajouté en édition', $vehicle->notes);
        $this->assertNull($vehicle->activeVehicleContract, 'à l\'édition, le contrat est facultatif');
    }

    public function test_adding_a_vehicle_of_another_owner_requires_confirmation(): void
    {
        $token = $this->login(['edit-owners']);
        $owner = $this->owner();
        $previous = $this->owner(['name' => 'Koffi Ancien']);
        $vehicle = Vehicle::factory()->create(['owner_id' => $previous->id]);

        $payload = $this->identity($owner, [
            'name' => 'Nom Qui Ne Doit Pas Passer',
            'add_vehicle' => ['mode' => 'existing', 'vehicle_id' => $vehicle->id, 'contract' => $this->contract()],
        ]);

        $this->update($token, $owner, $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_TRANSFER_UNCONFIRMED')
            ->assertJsonPath('current_owner_name', 'Koffi Ancien');

        // Toute la requête est annulée, identité comprise.
        $this->assertNotSame('Nom Qui Ne Doit Pas Passer', $owner->fresh()->name);
        $this->assertSame($previous->id, $vehicle->fresh()->owner_id);

        $this->update($token, $owner, array_merge($payload, ['confirm_transfer' => true]))->assertOk();

        $this->assertSame($owner->id, $vehicle->fresh()->owner_id);
        $this->assertSame($owner->id, $vehicle->fresh()->activeVehicleContract->owner_id);
    }

    public function test_an_account_that_is_not_an_owner_cannot_be_edited_here(): void
    {
        $token = $this->login(['edit-owners']);
        $agent = Driver::factory()->create()->user;

        $this->update($token, $agent, $this->identity($agent))->assertNotFound();

        $this->assertSame('driver', $agent->fresh()->profil);
    }
}
