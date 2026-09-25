<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Les lectures des véhicules côté administration — ex-Admin\VehicleController::index() et show(). */
class AdminVehiclesReadApiTest extends TestCase
{
    use RefreshDatabase;

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

    private function owner(string $name = 'Awa Propriétaire'): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create(['name' => $name]);
        $owner->assignRole(Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']));

        return $owner;
    }

    public function test_view_vehicles_is_required(): void
    {
        $this->asBearer($this->login(['view-owners']))->getJson('/api/v1/admin/vehicles')->assertForbidden();
    }

    public function test_the_list_returns_vehicles_counters_and_owner_filter_options(): void
    {
        $token = $this->login(['view-vehicles']);
        $owner = $this->owner();

        $running = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-0001']);
        $runningContract = VehicleContract::factory()->forVehicle($running)->create(['total_amount' => 1_000_000]);
        $driver = Driver::factory()->create();
        $driver->user->update(['name' => 'Agent Titulaire', 'phone' => '97000009']);
        DriverContract::factory()->forVehicleContract($runningContract)->create(['driver_id' => $driver->id, 'status' => 'active']);

        $paused = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'T-0002', 'is_active' => false]);
        $pausedContract = VehicleContract::factory()->forVehicle($paused)->create();
        $pause = VehiclePause::create([
            'vehicle_id' => $paused->id,
            'vehicle_contract_id' => $pausedContract->id,
            'start_date' => now()->subDay()->toDateString(),
            'reason_type' => 'technical',
            'is_auto' => false,
        ]);

        Vehicle::factory()->create(['owner_id' => null, 'vehicle_number' => 'T-0003']);

        $response = $this->asBearer($token)->getJson('/api/v1/admin/vehicles')->assertOk();

        $response->assertJsonPath('stats', ['total' => 3, 'active' => 2, 'paused' => 1, 'without_contract' => 1]);
        $response->assertJsonPath('owners.0.name', 'Awa Propriétaire');

        $byNumber = collect($response->json('vehicles'))->keyBy('vehicle_number');
        $this->assertSame('Awa Propriétaire', $byNumber['T-0001']['owner']['name']);
        $this->assertSame('Agent Titulaire', $byNumber['T-0001']['driver']['name']);
        $this->assertEquals(1_000_000, $byNumber['T-0001']['contract']['total_amount']);
        $this->assertTrue($byNumber['T-0002']['is_on_pause']);
        // La ligne porte la pause en cours, pour la terminer sans ouvrir la fiche.
        $this->assertSame($pause->id, $byNumber['T-0002']['active_pause_id']);
        $this->assertNull($byNumber['T-0001']['active_pause_id']);
        $this->assertNull($byNumber['T-0003']['owner']);
        $this->assertNull($byNumber['T-0003']['contract']);
    }

    public function test_the_list_filters_by_number_status_and_owner(): void
    {
        $token = $this->login(['view-vehicles']);
        $owner = $this->owner();
        Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => 'BJ-AAA']);
        Vehicle::factory()->create(['owner_id' => null, 'vehicle_number' => 'BJ-BBB', 'is_active' => false]);

        $this->asBearer($token)->getJson('/api/v1/admin/vehicles?search=BBB')
            ->assertJsonCount(1, 'vehicles')->assertJsonPath('vehicles.0.vehicle_number', 'BJ-BBB');
        $this->asBearer($token)->getJson('/api/v1/admin/vehicles?is_active=1')
            ->assertJsonCount(1, 'vehicles')->assertJsonPath('vehicles.0.vehicle_number', 'BJ-AAA');
        $this->asBearer($token)->getJson("/api/v1/admin/vehicles?owner_id={$owner->id}")
            ->assertJsonCount(1, 'vehicles')->assertJsonPath('vehicles.0.vehicle_number', 'BJ-AAA');
    }

    public function test_the_detail_carries_every_block_of_the_blade_screen(): void
    {
        $token = $this->login(['view-vehicles']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'notes' => 'Pneus neufs']);

        $old = VehicleContract::factory()->forVehicle($vehicle)->completed()->create(['start_date' => '2025-01-01']);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['total_amount' => 100_000]);
        foreach (range(1, 6) as $day) {
            Payment::factory()->create([
                'vehicle_contract_id' => $contract->id,
                'net_amount' => 10_000,
                'amount' => 10_000,
                'payment_date' => now()->subDays($day)->toDateString(),
                'status' => 'completed',
            ]);
        }

        $driver = Driver::factory()->create();
        $driver->user->update(['name' => 'Agent Titulaire']);
        DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_contract_id' => $contract->id,
            'status' => 'active',
            'contract_months' => 24,
        ]);
        DriverContract::factory()->create([
            'vehicle_id' => $vehicle->id,
            'vehicle_contract_id' => $old->id,
            'status' => 'completed',
            'end_date' => '2025-12-31',
        ]);
        VehiclePause::create([
            'vehicle_id' => $vehicle->id,
            'vehicle_contract_id' => $contract->id,
            'start_date' => now()->subDays(3)->toDateString(),
            'reason_type' => 'accident',
            'reason_notes' => 'Choc arrière',
            'is_auto' => false,
        ]);

        $response = $this->asBearer($token)->getJson("/api/v1/admin/vehicles/{$vehicle->id}")->assertOk();

        $response->assertJsonPath('notes', 'Pneus neufs')
            ->assertJsonPath('owner.id', $owner->id)
            ->assertJsonPath('contract.id', $contract->id)
            ->assertJsonPath('contract.progress_percentage', 60)
            ->assertJsonCount(5, 'contract.recent_payments')
            ->assertJsonCount(1, 'past_contracts')
            ->assertJsonPath('past_contracts.0.status', 'completed')
            ->assertJsonCount(2, 'driver_history')
            ->assertJsonPath('current_driver.driver_id', $driver->id)
            ->assertJsonPath('current_driver.name', 'Agent Titulaire')
            ->assertJsonPath('active_pause.reason_type', 'accident')
            ->assertJsonCount(1, 'pauses');

        $this->assertEquals(60_000, $response->json('contract.total_paid'));
        $this->assertEquals(40_000, $response->json('contract.remaining_amount'));
    }

    public function test_an_unknown_vehicle_is_a_404(): void
    {
        $this->asBearer($this->login(['view-vehicles']))
            ->getJson('/api/v1/admin/vehicles/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }
}
