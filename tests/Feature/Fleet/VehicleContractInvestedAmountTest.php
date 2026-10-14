<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehicleContractTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Le montant investi, porté par le contrat (2026-10-14).
 *
 * Il n'est plus le même pour tous les propriétaires : il se saisit à la création du
 * contrat — laissé vide, il prend celui des réglages pour la durée, COPIÉ et figé — et
 * se corrige ensuite, même avec un agent actif, puisqu'il n'entre dans aucun calcul.
 */
class VehicleContractInvestedAmountTest extends TestCase
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

    // ----- Création ----------------------------------------------------------------------

    public function test_the_invested_amount_entered_at_creation_is_kept(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->asBearer($this->login(['create-contracts']))
            ->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload([
                'vehicle_id' => $vehicle->id, 'invested_amount' => 1_650_000,
            ]))
            ->assertCreated()
            ->assertJsonPath('contract.invested_amount', 1_650_000);

        $this->assertEquals(1_650_000, VehicleContract::query()->where('vehicle_id', $vehicle->id)->value('invested_amount'));
    }

    public function test_left_empty_it_copies_the_settings_of_its_duration(): void
    {
        VehicleContractTerm::query()->where('months', 30)->update(['invested_amount' => 1_900_000]);
        $vehicle = Vehicle::factory()->create();

        $this->asBearer($this->login(['create-contracts']))
            ->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload(['vehicle_id' => $vehicle->id]))
            ->assertCreated();

        $contract = VehicleContract::query()->where('vehicle_id', $vehicle->id)->firstOrFail();
        $this->assertEquals(1_900_000, $contract->invested_amount);

        // Copié, pas relu : changer le réglage ne touche plus le contrat.
        VehicleContractTerm::query()->where('months', 30)->update(['invested_amount' => 2_000_000]);
        $this->assertEquals(1_900_000, $contract->refresh()->invested_amount);
    }

    public function test_a_negative_invested_amount_is_refused(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->asBearer($this->login(['create-contracts']))
            ->postJson('/api/v1/admin/vehicle-contracts', $this->contractPayload([
                'vehicle_id' => $vehicle->id, 'invested_amount' => -1,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invested_amount']);
    }

    public function test_the_full_edit_keeps_the_amount_when_left_empty_and_takes_a_new_one(): void
    {
        $contract = VehicleContract::factory()->create(['contract_months' => 30, 'total_amount' => 3_604_872, 'invested_amount' => 1_420_000]);
        $token = $this->login(['view-contracts', 'edit-contracts']);

        $this->asBearer($token)
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->contractPayload(['status' => 'active']))
            ->assertOk();
        $this->assertEquals(1_420_000, $contract->refresh()->invested_amount);

        $this->asBearer($token)
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->contractPayload(['status' => 'active', 'invested_amount' => 1_500_000]))
            ->assertOk();
        $this->assertEquals(1_500_000, $contract->refresh()->invested_amount);
    }

    // ----- Correction --------------------------------------------------------------------

    public function test_it_is_corrected_even_with_an_active_driver_and_journaled(): void
    {
        $contract = VehicleContract::factory()->create(['invested_amount' => 1_799_500]);
        DriverContract::factory()->forVehicleContract($contract)->create();

        $this->asBearer($this->login(['view-contracts', 'edit-contracts']))
            ->patchJson("/api/v1/admin/vehicle-contracts/{$contract->id}/invested-amount", ['invested_amount' => 1_550_000])
            ->assertOk()
            ->assertJsonPath('contract.invested_amount', 1_550_000);

        $this->assertEquals(1_550_000, $contract->refresh()->invested_amount);
        $this->assertDatabaseHas('activity_log', ['event' => 'vehicle.contract_invested_amount_updated', 'subject_id' => $contract->id]);
    }

    public function test_the_correction_requires_edit_contracts(): void
    {
        $contract = VehicleContract::factory()->create();

        $this->asBearer($this->login(['view-contracts', 'create-contracts']))
            ->patchJson("/api/v1/admin/vehicle-contracts/{$contract->id}/invested-amount", ['invested_amount' => 1_550_000])
            ->assertForbidden();
    }

    public function test_the_correction_needs_a_positive_amount(): void
    {
        $contract = VehicleContract::factory()->create();
        $token = $this->login(['view-contracts', 'edit-contracts']);

        $this->asBearer($token)
            ->patchJson("/api/v1/admin/vehicle-contracts/{$contract->id}/invested-amount", ['invested_amount' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invested_amount']);
        $this->asBearer($token)
            ->patchJson("/api/v1/admin/vehicle-contracts/{$contract->id}/invested-amount", ['invested_amount' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invested_amount']);
    }

    public function test_a_cancelled_contract_is_not_corrected(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'cancelled']);

        $this->asBearer($this->login(['view-contracts', 'edit-contracts']))
            ->patchJson("/api/v1/admin/vehicle-contracts/{$contract->id}/invested-amount", ['invested_amount' => 1_550_000])
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_CONTRACT_CANCELLED');
    }

    // ----- Propriétaire ------------------------------------------------------------------

    public function test_the_owner_sees_the_amount_of_the_contract_not_of_the_settings(): void
    {
        $owner = User::factory()->profil(Profil::Owner)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $owner->givePermissionTo(Permission::findOrCreate('view-own-contracts', 'web'));
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create(['invested_amount' => 1_420_000]);

        $token = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'bon-mot-de-passe'])->json('token');

        $this->asBearer($token)
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('contract.invested_amount', 1_420_000);
    }
}
