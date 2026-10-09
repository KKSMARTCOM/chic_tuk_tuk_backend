<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\InternalAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Un contrat en attente, côté propriétaire et côté génération (spec 2026-10-09, §5.2, §5.3). */
class OwnerPendingVehicleTest extends TestCase
{
    use RefreshDatabase;

    private function loginOwner(): array
    {
        $user = User::factory()->profil(Profil::Owner)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach (['view-own-vehicles', 'view-own-contracts', 'view-own-payments'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');

        return [$user, $token];
    }

    public function test_a_pending_vehicle_reads_pending_without_contract(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->create(['vehicle_id' => $vehicle->id, 'owner_id' => $owner->id, 'status' => 'pending', 'start_date' => null]);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/owner/vehicles')
            ->assertOk()->assertJsonPath('0.state', 'pending')->assertJsonPath('0.contract', null);
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/owner/vehicles/{$vehicle->id}")
            ->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('contract', null);
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()->assertExactJson([]);
    }

    public function test_the_internal_driver_never_shows_to_the_owner(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->create(['vehicle_id' => $vehicle->id, 'owner_id' => $owner->id, 'status' => 'pending', 'start_date' => null]);
        $assignment = InternalAssignment::factory()->for($contract, 'vehicleContract')->create();

        $body = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/owner/vehicles/{$vehicle->id}")->getContent();

        $this->assertStringNotContainsString($assignment->driver->user->name, $body);
    }

    public function test_no_statement_is_generated_for_a_pending_contract(): void
    {
        VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);

        $this->artisan('app:generate-remuneration-statements', ['--month' => '2026-09'])->assertSuccessful();

        $this->assertDatabaseCount('remuneration_statements', 0);
    }

    public function test_the_nightly_generation_ignores_a_pending_vehicle_with_an_internal_driver(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        InternalAssignment::factory()->for($contract, 'vehicleContract')->create(['start_date' => '2026-10-01']);

        $this->artisan('app:generate-daily')->assertSuccessful();

        $this->assertDatabaseCount('payments', 0);
    }
}
