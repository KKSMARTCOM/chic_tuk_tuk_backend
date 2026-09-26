<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les contrats agents vus de l'administration — ex-Admin\DriverContractController (F4).
 *
 * ⚠️ Règles du 2026-09-26, alignées sur les contrats véhicule :
 *  - un contrat actif, ou qui a des pauses agent ou des paiements, ne se supprime pas —
 *    les clés passeraient à null et ses pauses sortiraient du solde de l'agent ;
 *  - un contrat qui a des pauses agent ou des paiements ne se modifie pas : la règle
 *    n'était portée que par la vue Blade du dossier ;
 *  - on ne termine qu'un contrat actif.
 */
class AdminDriverContractsApiTest extends TestCase
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

    /** Un contrat agent actif sur un véhicule sous contrat véhicule actif. */
    private function activeContract(array $attributes = []): DriverContract
    {
        $vehicle = Vehicle::factory()->create();
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create();

        return DriverContract::factory()->forVehicleContract($vehicleContract)->create($attributes);
    }

    /** Un véhicule libre : actif, sous contrat véhicule, sans agent. */
    private function freeVehicle(): Vehicle
    {
        $vehicle = Vehicle::factory()->create();
        VehicleContract::factory()->forVehicle($vehicle)->create();

        return $vehicle;
    }

    // ----- Lectures ----------------------------------------------------------------------

    public function test_reads_require_view_contracts(): void
    {
        $contract = $this->activeContract();

        $token = $this->login(['view-drivers']);
        $this->asBearer($token)->getJson('/api/v1/admin/driver-contracts')->assertForbidden();
        $this->asBearer($token)->getJson("/api/v1/admin/driver-contracts/{$contract->id}")->assertForbidden();
    }

    public function test_the_list_uses_the_leave_balance_of_the_leaves_screen(): void
    {
        $contract = $this->activeContract(['start_date' => now()->subMonths(2)->startOfMonth()]);
        LeaveRequest::factory()->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'effective_days' => 8,
        ]);

        $row = collect($this->asBearer($this->login(['view-contracts']))
            ->getJson('/api/v1/admin/driver-contracts')
            ->assertOk()
            ->json('contracts'))->firstWhere('id', $contract->id);

        // Trois mois entamés, deux jours par mois : 6 acquis, 8 pris, 2 d'avance.
        $this->assertSame(8, $row['used_leave_days']);
        $this->assertSame(6, $row['accrued_leave_days']);
        $this->assertSame(-2, $row['available_leave_days']);
        $this->assertSame($contract->driver_id, $row['driver']['id']);
        // Une pause agent liée : ni modifiable, ni supprimable.
        $this->assertFalse($row['is_editable']);
        $this->assertFalse($row['is_deletable']);
    }

    public function test_the_detail_counts_only_completed_payments(): void
    {
        $contract = $this->activeContract();
        Payment::factory()->onDay('2026-08-04')->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'vehicle_contract_id' => $contract->vehicle_contract_id,
            'amount' => 6112,
        ]);
        Payment::factory()->onDay('2026-08-05')->status('cancelled')->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'vehicle_contract_id' => $contract->vehicle_contract_id,
            'amount' => 99_999,
        ]);
        VehiclePause::factory()->create([
            'vehicle_id' => $contract->vehicle_id,
            'vehicle_contract_id' => $contract->vehicle_contract_id,
            'driver_contract_id' => $contract->id,
        ]);

        $this->asBearer($this->login(['view-contracts']))
            ->getJson("/api/v1/admin/driver-contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('contract.id', $contract->id)
            ->assertJsonPath('payments_count', 2)
            ->assertJsonPath('total_paid', 6112)
            ->assertJsonPath('payments_by_month.0.month', '2026-08')
            ->assertJsonPath('payments_by_month.0.total', 6112)
            ->assertJsonCount(1, 'pauses')
            ->assertJsonPath('owner.id', $contract->vehicle->owner_id);
    }

    // ----- Modification ------------------------------------------------------------------

    public function test_a_contract_without_history_is_updated(): void
    {
        $contract = $this->activeContract(['contract_months' => 24]);

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/driver-contracts/{$contract->id}", [
                'start_date' => '2026-02-01',
                'contract_months' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('contract.contract_months', 30);

        $this->assertSame('2026-02-01', $contract->fresh()->start_date->toDateString());
    }

    public function test_update_requires_its_permission(): void
    {
        $contract = $this->activeContract();

        $this->asBearer($this->login(['view-contracts']))
            ->putJson("/api/v1/admin/driver-contracts/{$contract->id}", ['start_date' => '2026-02-01', 'contract_months' => 30])
            ->assertForbidden();
    }

    public function test_a_contract_with_payments_is_not_updated(): void
    {
        $contract = $this->activeContract();
        Payment::factory()->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'vehicle_contract_id' => $contract->vehicle_contract_id,
        ]);

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/driver-contracts/{$contract->id}", ['start_date' => '2026-02-01', 'contract_months' => 30])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DRIVER_CONTRACT_LOCKED');
    }

    public function test_changing_vehicle_follows_the_new_vehicle_contract(): void
    {
        // Défaut corrigé : `vehicle_contract_id` restait celui de l'ancien véhicule, et les
        // paiements suivants partaient sur le mauvais contrat véhicule.
        $contract = $this->activeContract();
        $target = $this->freeVehicle();

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/driver-contracts/{$contract->id}", [
                'start_date' => $contract->start_date->toDateString(),
                'contract_months' => 24,
                'vehicle_id' => $target->id,
            ])
            ->assertOk();

        $contract->refresh();
        $this->assertSame($target->id, $contract->vehicle_id);
        $this->assertSame($target->activeVehicleContract->id, $contract->vehicle_contract_id);
    }

    public function test_a_vehicle_without_active_contract_or_already_driven_is_refused(): void
    {
        $contract = $this->activeContract();
        $token = $this->login(['edit-contracts']);
        $payload = fn (Vehicle $vehicle) => [
            'start_date' => $contract->start_date->toDateString(),
            'contract_months' => 24,
            'vehicle_id' => $vehicle->id,
        ];

        $this->asBearer($token)
            ->putJson("/api/v1/admin/driver-contracts/{$contract->id}", $payload(Vehicle::factory()->create()))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_WITHOUT_CONTRACT');

        $driven = $this->activeContract()->vehicle;
        $this->asBearer($token)
            ->putJson("/api/v1/admin/driver-contracts/{$contract->id}", $payload($driven))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_ALREADY_ASSIGNED');
    }

    public function test_assignable_vehicles_are_free_and_under_contract(): void
    {
        $free = $this->freeVehicle();
        $driven = $this->activeContract()->vehicle;
        $withoutContract = Vehicle::factory()->create();

        $ids = collect($this->asBearer($this->login(['edit-contracts']))
            ->getJson('/api/v1/admin/driver-contracts/assignable-vehicles')
            ->assertOk()
            ->json())->pluck('id');

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($driven->id, $ids);
        $this->assertNotContains($withoutContract->id, $ids);
    }

    // ----- Fin de contrat ----------------------------------------------------------------

    public function test_ending_a_contract_pauses_the_vehicle(): void
    {
        $contract = $this->activeContract();

        $this->asBearer($this->login(['edit-contracts']))
            ->postJson("/api/v1/admin/driver-contracts/{$contract->id}/end", [
                'end_date' => '2026-09-20',
                'end_reason' => 'demission',
            ])
            ->assertOk()
            ->assertJsonPath('contract.status', 'ended')
            ->assertJsonPath('contract.end_reason_label', 'Démission');

        $this->assertFalse($contract->vehicle->fresh()->is_active);
        $this->assertDatabaseHas('vehicle_pauses', [
            'driver_contract_id' => $contract->id,
            'reason_type' => 'agent_change',
            'is_auto' => true,
        ]);
    }

    public function test_an_ended_contract_is_not_ended_twice(): void
    {
        // Défaut corrigé : une seconde clôture créait une seconde pause véhicule.
        $contract = $this->activeContract(['status' => 'ended', 'end_date' => '2026-09-01']);

        $this->asBearer($this->login(['edit-contracts']))
            ->postJson("/api/v1/admin/driver-contracts/{$contract->id}/end", [
                'end_date' => '2026-09-20',
                'end_reason' => 'autre',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DRIVER_CONTRACT_NOT_ACTIVE');

        $this->assertSame(0, VehiclePause::query()->where('driver_contract_id', $contract->id)->count());
    }

    // ----- Suppression -------------------------------------------------------------------

    public function test_deletion_rules(): void
    {
        $token = $this->login(['delete-contracts']);

        $active = $this->activeContract();
        $this->asBearer($token)->deleteJson("/api/v1/admin/driver-contracts/{$active->id}")
            ->assertStatus(409)->assertJsonPath('code', 'DRIVER_CONTRACT_ACTIVE');

        $withLeave = $this->activeContract(['status' => 'ended', 'end_date' => '2026-09-01']);
        LeaveRequest::factory()->create(['driver_id' => $withLeave->driver_id, 'driver_contract_id' => $withLeave->id]);
        $this->asBearer($token)->deleteJson("/api/v1/admin/driver-contracts/{$withLeave->id}")
            ->assertStatus(409)->assertJsonPath('code', 'DRIVER_CONTRACT_NOT_DELETABLE');

        $bare = $this->activeContract(['status' => 'ended', 'end_date' => '2026-09-01']);
        $this->asBearer($token)->deleteJson("/api/v1/admin/driver-contracts/{$bare->id}")->assertNoContent();

        $this->assertDatabaseHas('driver_contracts', ['id' => $withLeave->id]);
        $this->assertDatabaseMissing('driver_contracts', ['id' => $bare->id]);
    }

    public function test_deletion_requires_its_permission(): void
    {
        $contract = $this->activeContract(['status' => 'ended', 'end_date' => '2026-09-01']);

        $this->asBearer($this->login(['view-contracts', 'edit-contracts']))
            ->deleteJson("/api/v1/admin/driver-contracts/{$contract->id}")
            ->assertForbidden();
    }
}
