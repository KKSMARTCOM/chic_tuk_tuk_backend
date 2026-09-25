<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
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
 * Les lectures des propriétaires côté administration — ex-Admin\OwnerController::index(),
 * create() et edit().
 *
 * Le Blade n'a pas d'écran de fiche : `owners.show` pointe vers une méthode qui n'existe
 * pas. `GET /admin/owners/{owner}` sert donc l'écran d'édition, qui fait office de fiche.
 */
class AdminOwnersReadApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function login(array $permissions): array
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function owner(array $attributes = []): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create($attributes);
        $owner->assignRole(Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']));

        return $owner;
    }

    public function test_the_list_returns_owners_their_vehicles_and_counters(): void
    {
        [, $token] = $this->login(['view-owners']);
        $active = $this->owner(['name' => 'Awa Propriétaire', 'is_active' => true]);
        $this->owner(['name' => 'Koffi Inactif', 'is_active' => false]);
        $vehicle = Vehicle::factory()->create(['owner_id' => $active->id, 'vehicle_number' => 'T-0042']);

        // Un compte `owner` sans le rôle `proprietaire` n'apparaît pas, comme dans le Blade.
        User::factory()->profil(Profil::Owner)->create(['name' => 'Sans Rôle']);

        $response = $this->asBearer($token)->getJson('/api/v1/admin/owners')->assertOk();

        $response->assertJsonPath('stats', ['total' => 2, 'active' => 1, 'inactive' => 1]);
        $this->assertCount(2, $response->json('owners'));

        $item = collect($response->json('owners'))->firstWhere('id', $active->id);
        $this->assertSame('Awa Propriétaire', $item['name']);
        $this->assertTrue($item['is_active']);
        $this->assertSame([['id' => $vehicle->id, 'vehicle_number' => 'T-0042']], $item['vehicles']);
        $this->assertArrayHasKey('created_at', $item);
    }

    public function test_the_list_filters_by_search_and_status(): void
    {
        [, $token] = $this->login(['view-owners']);
        $this->owner(['name' => 'Awa Propriétaire', 'phone' => '97000001', 'is_active' => true]);
        $this->owner(['name' => 'Koffi Inactif', 'phone' => '97000002', 'is_active' => false]);

        $this->asBearer($token)->getJson('/api/v1/admin/owners?search=97000002')
            ->assertOk()->assertJsonCount(1, 'owners')->assertJsonPath('owners.0.name', 'Koffi Inactif');

        $this->asBearer($token)->getJson('/api/v1/admin/owners?is_active=1')
            ->assertOk()->assertJsonCount(1, 'owners')->assertJsonPath('owners.0.name', 'Awa Propriétaire');
    }

    public function test_the_detail_separates_vehicles_with_and_without_an_assigned_driver(): void
    {
        [, $token] = $this->login(['view-owners']);
        $owner = $this->owner();

        $free = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-0001', 'notes' => 'Pneus neufs']);
        $freeContract = VehicleContract::factory()->forVehicle($free)->create(['contract_months' => 30]);

        $taken = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-0002']);
        $takenContract = VehicleContract::factory()->forVehicle($taken)->create();
        $driver = Driver::factory()->create();
        $driver->user->update(['name' => 'Agent Titulaire']);
        DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'vehicle_id' => $taken->id,
            'vehicle_contract_id' => $takenContract->id,
            'status' => 'active',
        ]);
        Payment::factory()->create([
            'vehicle_contract_id' => $takenContract->id,
            'driver_id' => $driver->id,
            'net_amount' => 12_000,
            'status' => 'completed',
        ]);

        $bare = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-0003']);

        $response = $this->asBearer($token)->getJson("/api/v1/admin/owners/{$owner->id}")->assertOk();

        $vehicles = collect($response->json('vehicles'))->keyBy('vehicle_number');

        $this->assertFalse($vehicles['T-0001']['has_driver']);
        $this->assertSame('Pneus neufs', $vehicles['T-0001']['notes']);
        $this->assertSame($freeContract->id, $vehicles['T-0001']['contract']['id']);
        $this->assertSame(30, $vehicles['T-0001']['contract']['contract_months']);

        $this->assertTrue($vehicles['T-0002']['has_driver']);
        $this->assertSame('Agent Titulaire', $vehicles['T-0002']['driver_name']);
        $this->assertEquals(12_000, $vehicles['T-0002']['contract']['total_paid']);

        $this->assertFalse($vehicles['T-0003']['has_driver']);
        $this->assertNull($vehicles['T-0003']['contract']);
    }

    public function test_the_detail_of_an_account_that_is_not_an_owner_is_a_404(): void
    {
        [$admin, $token] = $this->login(['view-owners']);

        $this->asBearer($token)->getJson("/api/v1/admin/owners/{$admin->id}")->assertNotFound();
    }

    public function test_available_vehicles_are_active_without_a_running_contract_and_name_their_owner(): void
    {
        [, $token] = $this->login(['edit-owners']);
        $owner = $this->owner();
        $other = $this->owner(['name' => 'Autre Propriétaire']);

        $orphan = Vehicle::factory()->create(['owner_id' => null, 'vehicle_number' => 'T-0100']);
        $ownedElsewhere = Vehicle::factory()->create(['owner_id' => $other->id, 'vehicle_number' => 'T-0101']);
        $underContract = Vehicle::factory()->create(['owner_id' => $other->id]);
        VehicleContract::factory()->forVehicle($underContract)->create();
        Vehicle::factory()->create(['owner_id' => null, 'is_active' => false]);
        // Les véhicules du propriétaire édité figurent déjà sur ses cartes.
        Vehicle::factory()->create(['owner_id' => $owner->id]);

        $response = $this->asBearer($token)
            ->getJson("/api/v1/admin/owners/available-vehicles?owner_id={$owner->id}")
            ->assertOk();

        $byNumber = collect($response->json())->keyBy('vehicle_number');
        $this->assertEqualsCanonicalizing(['T-0100', 'T-0101'], $byNumber->keys()->all());
        $this->assertNull($byNumber['T-0100']['owner_name']);
        $this->assertSame('Autre Propriétaire', $byNumber['T-0101']['owner_name']);
        $this->assertSame($other->id, $byNumber['T-0101']['owner_id']);
        $this->assertSame($orphan->id, $byNumber['T-0100']['id']);
        $this->assertSame($ownedElsewhere->id, $byNumber['T-0101']['id']);
    }
}
