<?php

namespace Tests\Feature\Fleet;

use App\Consts\VehicleContractConsts;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les écritures sur les contrats propriétaire-véhicule — ex-Admin\VehicleContractController.
 *
 * ⚠️ Règles décidées le 2026-09-26 :
 *  - un contrat qui a un contrat agent, un paiement ou une pause véhicule, même terminé,
 *    ne se supprime pas : la cascade effaçait tout son historique ;
 *  - supprimer un contrat ne détache plus le véhicule de son propriétaire ;
 *  - un véhicule n'a jamais deux contrats actifs.
 */
class AdminVehicleContractWritesApiTest extends TestCase
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
            'total_amount' => VehicleContractConsts::TOTAL_AMOUNTS[30],
            'start_date' => '2026-10-01',
        ], $overrides);
    }

    // ----- Création ----------------------------------------------------------------------

    public function test_a_contract_is_created_for_the_vehicle_owner_with_default_charges(): void
    {
        $vehicle = Vehicle::factory()->create();

        $response = $this->asBearer($this->login(['create-contracts']))
            ->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload(['vehicle_id' => $vehicle->id]))
            ->assertCreated();

        $contract = VehicleContract::query()->where('vehicle_id', $vehicle->id)->firstOrFail();
        $response->assertJsonPath('contract.id', $contract->id);
        $this->assertSame($vehicle->owner_id, $contract->owner_id);
        $this->assertSame('active', $contract->status);
        // Défaut corrigé : la modale Blade ne demandait pas la durée, et le contrat
        // naissait sans `contract_months` — donc avec un montant journalier nul.
        $this->assertSame(30, $contract->contract_months);
        $this->assertEquals(VehicleContractConsts::DEFAULT_MANAGER_REMUNERATION, $contract->manager_remuneration);
        $this->assertEquals(0, $contract->monthly_payment);
    }

    public function test_creation_requires_its_permission(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->asBearer($this->login(['view-contracts', 'edit-contracts']))
            ->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload(['vehicle_id' => $vehicle->id]))
            ->assertForbidden();
    }

    public function test_a_vehicle_without_owner_gets_no_contract(): void
    {
        // Défaut corrigé : `owner_id` est NOT NULL, la création finissait en erreur 500.
        $vehicle = Vehicle::factory()->create(['owner_id' => null]);

        $this->asBearer($this->login(['create-contracts']))
            ->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload(['vehicle_id' => $vehicle->id]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_HAS_NO_OWNER');
    }

    public function test_a_vehicle_never_gets_a_second_active_contract(): void
    {
        $vehicle = Vehicle::factory()->create();
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->asBearer($this->login(['create-contracts']))
            ->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload(['vehicle_id' => $vehicle->id]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_HAS_ACTIVE_CONTRACT');

        $this->assertSame(1, VehicleContract::query()->where('vehicle_id', $vehicle->id)->count());
    }

    // ----- Modification ------------------------------------------------------------------

    public function test_an_update_keeps_the_end_date(): void
    {
        // Défaut corrigé : le contrôleur lisait un `end_date` jamais validé, et chaque
        // enregistrement effaçait la date de fin.
        $contract = VehicleContract::factory()->completed()->create(['end_date' => '2026-06-30']);

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->contractPayload([
                'status' => 'completed',
                'notes' => 'Soldé en juin',
            ]))
            ->assertOk()
            ->assertJsonPath('contract.notes', 'Soldé en juin');

        $contract->refresh();
        $this->assertSame('2026-06-30', $contract->end_date->toDateString());
        $this->assertSame(30, $contract->contract_months);
    }

    public function test_update_requires_its_permission(): void
    {
        $contract = VehicleContract::factory()->create();

        $this->asBearer($this->login(['view-contracts', 'create-contracts']))
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->contractPayload(['status' => 'active']))
            ->assertForbidden();
    }

    public function test_a_contract_whose_vehicle_has_an_active_driver_is_not_updated(): void
    {
        $contract = VehicleContract::factory()->create();
        DriverContract::factory()->forVehicleContract($contract)->create();

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->contractPayload(['status' => 'active']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_CONTRACT_HAS_ACTIVE_DRIVER');
    }

    public function test_a_contract_moved_to_another_vehicle_takes_its_owner(): void
    {
        $contract = VehicleContract::factory()->completed()->create();
        $target = Vehicle::factory()->create();

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->contractPayload([
                'status' => 'completed',
                'vehicle_id' => $target->id,
            ]))
            ->assertOk();

        $contract->refresh();
        $this->assertSame($target->id, $contract->vehicle_id);
        $this->assertSame($target->owner_id, $contract->owner_id);
    }

    public function test_reactivating_a_contract_on_a_vehicle_already_under_contract_is_refused(): void
    {
        $vehicle = Vehicle::factory()->create();
        $old = VehicleContract::factory()->forVehicle($vehicle)->completed()->create();
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/vehicle-contracts/{$old->id}", $this->contractPayload(['status' => 'active']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_HAS_ACTIVE_CONTRACT');

        $this->assertSame('completed', $old->fresh()->status);
    }

    public function test_moving_an_active_contract_to_a_vehicle_under_contract_is_refused(): void
    {
        $contract = VehicleContract::factory()->create();
        $target = Vehicle::factory()->create();
        VehicleContract::factory()->forVehicle($target)->create();

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->contractPayload([
                'status' => 'active',
                'vehicle_id' => $target->id,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_HAS_ACTIVE_CONTRACT');
    }

    // ----- Suppression -------------------------------------------------------------------

    public function test_a_finished_contract_without_history_is_deleted_and_the_vehicle_keeps_its_owner(): void
    {
        $vehicle = Vehicle::factory()->create();
        $ownerId = $vehicle->owner_id;
        $contract = VehicleContract::factory()->forVehicle($vehicle)->completed()->create();

        $this->asBearer($this->login(['delete-contracts']))
            ->deleteJson("/api/v1/admin/vehicle-contracts/{$contract->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('vehicle_contracts', ['id' => $contract->id]);
        // Décidé le 2026-09-26 : le Blade remettait `owner_id` à null, même si le
        // véhicule avait changé de propriétaire depuis.
        $this->assertSame($ownerId, $vehicle->fresh()->owner_id);
    }

    public function test_deletion_requires_its_permission(): void
    {
        $contract = VehicleContract::factory()->completed()->create();

        $this->asBearer($this->login(['view-contracts', 'create-contracts', 'edit-contracts']))
            ->deleteJson("/api/v1/admin/vehicle-contracts/{$contract->id}")
            ->assertForbidden();
    }

    public function test_an_active_contract_is_not_deleted(): void
    {
        $contract = VehicleContract::factory()->create();

        $this->asBearer($this->login(['delete-contracts']))
            ->deleteJson("/api/v1/admin/vehicle-contracts/{$contract->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_CONTRACT_ACTIVE');
    }

    public function test_a_contract_with_history_is_not_deleted(): void
    {
        $token = $this->login(['delete-contracts']);

        $withDriver = VehicleContract::factory()->completed()->create();
        DriverContract::factory()->forVehicleContract($withDriver)->create(['status' => 'ended']);

        $withPayment = VehicleContract::factory()->completed()->create();
        Payment::factory()->create(['vehicle_contract_id' => $withPayment->id]);

        foreach ([$withDriver, $withPayment] as $contract) {
            $this->asBearer($token)
                ->deleteJson("/api/v1/admin/vehicle-contracts/{$contract->id}")
                ->assertStatus(409)
                ->assertJsonPath('code', 'VEHICLE_CONTRACT_NOT_DELETABLE');

            $this->assertDatabaseHas('vehicle_contracts', ['id' => $contract->id]);
        }
    }
}
