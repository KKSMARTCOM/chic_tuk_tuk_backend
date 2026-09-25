<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
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
 * La création d'un propriétaire — ex-Admin\OwnerController::store().
 *
 * Trois écritures en cascade dans une transaction : le compte, puis éventuellement un
 * véhicule (existant ou nouveau), puis le contrat propriétaire-véhicule.
 *
 * ⚠️ Règle décidée le 2026-09-25 : rattacher un véhicule qui appartient déjà à un autre
 * propriétaire est un TRANSFERT, que l'administrateur doit confirmer. Le Blade le
 * faisait en silence à la création et le refusait à l'édition.
 */
class AdminOwnerCreateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `OwnerService::create()` fait `$user->assignRole('proprietaire')`.
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

    private function owner(string $name = 'Ancien Propriétaire'): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create(['name' => $name]);
        $owner->assignRole('proprietaire');

        return $owner;
    }

    private function contract(array $overrides = []): array
    {
        return array_merge([
            'contract_months' => 30,
            'total_amount' => 3_604_872,
            'start_date' => '2026-10-01',
            'unlimited_internet' => 5_000,
            'spotify_premium' => 2_500,
            'manager_remuneration' => 20_000,
            'notes' => 'Clause particulière',
        ], $overrides);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nouveau Propriétaire',
            'email' => 'nouveau@example.test',
            'phone' => '96123456',
            'password' => 'MotDePasse2026!',
            'adresse' => 'Cotonou',
            'is_active' => true,
            'vehicle' => null,
            'confirm_transfer' => false,
        ], $overrides);
    }

    private function createdOwner(): User
    {
        return User::query()->where('phone', '96123456')->firstOrFail();
    }

    public function test_without_create_owners_creation_is_refused(): void
    {
        $token = $this->login(['view-owners']);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload())->assertForbidden();
    }

    public function test_an_owner_can_be_created_without_a_vehicle(): void
    {
        $token = $this->login(['create-owners']);

        $response = $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload())
            ->assertCreated();

        $owner = $this->createdOwner();
        $this->assertSame('owner', $owner->profil);
        $this->assertTrue($owner->hasRole('proprietaire'));
        $this->assertTrue(Hash::check('MotDePasse2026!', $owner->password));
        $response->assertJsonPath('id', $owner->id)->assertJsonPath('vehicles', []);
    }

    public function test_a_new_vehicle_is_created_with_its_contract(): void
    {
        $token = $this->login(['create-owners']);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => [
                'mode' => 'new',
                'vehicle_number' => 'BJ-1234-AB',
                'vehicle_type' => 'tricycle',
                'notes' => 'Acheté neuf',
                'contract' => $this->contract(),
            ],
        ]))->assertCreated()->assertJsonPath('vehicles.0.vehicle_number', 'BJ-1234-AB');

        $owner = $this->createdOwner();
        $vehicle = Vehicle::query()->where('vehicle_number', 'BJ-1234-AB')->firstOrFail();
        $this->assertSame($owner->id, $vehicle->owner_id);
        $this->assertSame('Acheté neuf', $vehicle->notes);

        $contract = $vehicle->activeVehicleContract;
        $this->assertSame($owner->id, $contract->owner_id);
        $this->assertSame(30, $contract->contract_months);
        $this->assertEquals(3_604_872, $contract->total_amount);
        $this->assertSame('2026-10-01', $contract->start_date->toDateString());
        $this->assertSame('Clause particulière', $contract->notes);
    }

    public function test_a_vehicle_requires_its_contract_at_creation(): void
    {
        $token = $this->login(['create-owners']);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => ['mode' => 'new', 'vehicle_number' => 'BJ-1234-AB', 'vehicle_type' => 'tricycle'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['vehicle.contract']);

        $this->assertDatabaseMissing('users', ['phone' => '96123456']);
    }

    public function test_each_attachment_mode_requires_its_own_fields(): void
    {
        $token = $this->login(['create-owners']);
        Vehicle::factory()->create(['vehicle_number' => 'BJ-DEJA-PRIS']);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => ['mode' => 'new', 'contract' => $this->contract()],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['vehicle.vehicle_number', 'vehicle.vehicle_type']);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => ['mode' => 'new', 'vehicle_number' => 'BJ-DEJA-PRIS', 'vehicle_type' => 'tricycle', 'contract' => $this->contract()],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['vehicle.vehicle_number']);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => ['mode' => 'existing', 'contract' => $this->contract()],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['vehicle.vehicle_id']);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => ['mode' => 'new', 'vehicle_number' => 'BJ-NEUF', 'vehicle_type' => 'tricycle',
                'contract' => $this->contract(['total_amount' => null])],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['vehicle.contract.total_amount']);
    }

    public function test_a_vehicle_without_owner_is_attached_without_confirmation(): void
    {
        $token = $this->login(['create-owners']);
        $vehicle = Vehicle::factory()->create(['owner_id' => null]);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => ['mode' => 'existing', 'vehicle_id' => $vehicle->id, 'contract' => $this->contract()],
        ]))->assertCreated();

        $this->assertSame($this->createdOwner()->id, $vehicle->fresh()->owner_id);
        $this->assertNotNull($vehicle->fresh()->activeVehicleContract);
    }

    public function test_attaching_a_vehicle_of_another_owner_asks_for_confirmation_and_changes_nothing(): void
    {
        $token = $this->login(['create-owners']);
        $previous = $this->owner('Awa Ancienne');
        $vehicle = Vehicle::factory()->create(['owner_id' => $previous->id, 'vehicle_number' => 'T-0101']);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => ['mode' => 'existing', 'vehicle_id' => $vehicle->id, 'contract' => $this->contract()],
        ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_TRANSFER_UNCONFIRMED')
            ->assertJsonPath('current_owner_name', 'Awa Ancienne')
            ->assertJsonPath('vehicle_number', 'T-0101');

        // La transaction entière est annulée : ni compte, ni transfert, ni contrat.
        $this->assertDatabaseMissing('users', ['phone' => '96123456']);
        $this->assertSame($previous->id, $vehicle->fresh()->owner_id);
        $this->assertSame(0, VehicleContract::query()->count());
    }

    public function test_a_confirmed_transfer_moves_the_vehicle(): void
    {
        $token = $this->login(['create-owners']);
        $previous = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $previous->id]);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => ['mode' => 'existing', 'vehicle_id' => $vehicle->id, 'contract' => $this->contract()],
            'confirm_transfer' => true,
        ]))->assertCreated();

        $this->assertSame($this->createdOwner()->id, $vehicle->fresh()->owner_id);
    }

    public function test_a_vehicle_under_a_running_contract_cannot_be_transferred_even_confirmed(): void
    {
        $token = $this->login(['create-owners']);
        $vehicle = Vehicle::factory()->create(['owner_id' => $this->owner()->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload([
            'vehicle' => ['mode' => 'existing', 'vehicle_id' => $vehicle->id, 'contract' => $this->contract()],
            'confirm_transfer' => true,
        ]))->assertStatus(409)->assertJsonPath('code', 'VEHICLE_UNDER_CONTRACT');

        $this->assertDatabaseMissing('users', ['phone' => '96123456']);
    }

    public function test_a_phone_already_used_by_an_agent_is_refused_by_validation(): void
    {
        // Défaut corrigé : l'unicité n'était vérifiée que parmi les comptes
        // `profil=client`, alors que la contrainte en base porte sur TOUS les comptes.
        // Le numéro d'un agent passait la validation, puis la base refusait l'insertion.
        $token = $this->login(['create-owners']);
        $agent = Driver::factory()->create()->user;

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload(['phone' => $agent->phone]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_the_password_follows_the_blade_rules(): void
    {
        $token = $this->login(['create-owners']);

        $this->asBearer($token)->postJson('/api/v1/admin/owners', $this->payload(['password' => 'faible']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }
}
