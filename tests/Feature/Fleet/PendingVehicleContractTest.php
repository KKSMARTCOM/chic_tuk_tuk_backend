<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\InternalAssignment;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Le contrat propriétaire en attente, à la création et à la modification (spec 2026-10-09, §3.1, §5.1, §7). */
class PendingVehicleContractTest extends TestCase
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

    /** @return array<string, mixed> */
    private function contractPayload(array $overrides = []): array
    {
        return array_merge([
            'contract_months' => 30,
            'total_amount' => 3_604_872,
            'start_date' => '2026-10-01',
        ], $overrides);
    }

    public function test_a_contract_is_created_pending_without_start_date(): void
    {
        $vehicle = Vehicle::factory()->create();
        $token = $this->login(['create-contracts']);

        $this->asBearer($token)->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload([
            'vehicle_id' => $vehicle->id, 'pending' => true, 'start_date' => null,
        ]))->assertCreated();

        $contract = VehicleContract::where('vehicle_id', $vehicle->id)->sole();
        $this->assertSame('pending', $contract->status);
        $this->assertNull($contract->start_date);
    }

    public function test_without_pending_the_start_date_stays_required(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->asBearer($this->login(['create-contracts']))->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload([
            'vehicle_id' => $vehicle->id, 'start_date' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_a_pending_contract_is_refused_on_a_vehicle_with_an_active_one(): void
    {
        $active = VehicleContract::factory()->create();

        $this->asBearer($this->login(['create-contracts']))->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload([
            'vehicle_id' => $active->vehicle_id, 'pending' => true,
        ]))->assertStatus(409)->assertJsonPath('code', 'VEHICLE_HAS_ACTIVE_CONTRACT');
    }

    public function test_an_active_contract_without_history_goes_back_to_pending(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-09-01']);

        $this->asBearer($this->login(['edit-contracts']))->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->contractPayload([
            'status' => 'pending', 'start_date' => null,
        ]))->assertOk();

        $this->assertSame('pending', $contract->fresh()->status);
        $this->assertNull($contract->fresh()->start_date);
    }

    public function test_a_contract_with_history_never_goes_back_to_pending(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-09-01']);
        Payment::factory()->onDay('2026-09-02')->create(['vehicle_contract_id' => $contract->id]);

        $this->asBearer($this->login(['edit-contracts']))->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->contractPayload([
            'status' => 'pending', 'start_date' => null,
        ]))->assertStatus(409)->assertJsonPath('code', 'VEHICLE_CONTRACT_ALREADY_STARTED');
    }

    public function test_a_contract_with_internal_assignments_is_not_deletable(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        InternalAssignment::factory()->for($contract, 'vehicleContract')->ended('2026-10-03')->create();

        $this->asBearer($this->login(['view-contracts']))->getJson("/api/v1/admin/vehicle-contracts/{$contract->id}")
            ->assertOk()->assertJsonPath('contract.is_deletable', false);
    }

    public function test_deleting_a_contract_with_internal_assignments_is_refused(): void
    {
        // Relecture du 2026-10-09 : seul le drapeau était corrigé, pas la suppression.
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        InternalAssignment::factory()->for($contract, 'vehicleContract')->create();

        $this->asBearer($this->login(['delete-contracts']))->deleteJson("/api/v1/admin/vehicle-contracts/{$contract->id}")
            ->assertStatus(409)->assertJsonPath('code', 'VEHICLE_CONTRACT_NOT_DELETABLE');

        $this->assertDatabaseCount('internal_assignments', 1);
    }

    public function test_a_pending_contract_without_history_can_be_deleted(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);

        $this->asBearer($this->login(['delete-contracts']))->deleteJson("/api/v1/admin/vehicle-contracts/{$contract->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('vehicle_contracts', ['id' => $contract->id]);
    }
}
