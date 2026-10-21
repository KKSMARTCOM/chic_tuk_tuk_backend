<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
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
 * Les écritures sur les véhicules et leurs pauses — ex-Admin\VehicleController.
 *
 * ⚠️ Règles décidées le 2026-09-25 :
 *  - un véhicule qui a un contrat, véhicule ou agent, même terminé, ne se supprime pas :
 *    la cascade effaçait tout son historique ;
 *  - seule une pause MANUELLE s'annule ; une pause automatique suit la pause de l'agent.
 */
class AdminVehicleWritesApiTest extends TestCase
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

    /** Un véhicule sous contrat propriétaire en cours — la condition pour le mettre en pause. */
    private function contractedVehicle(): Vehicle
    {
        $vehicle = Vehicle::factory()->create(['is_active' => true]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        return $vehicle;
    }

    // ----- Création, modification, statut ------------------------------------------

    public function test_a_vehicle_is_created_active_and_without_owner(): void
    {
        $token = $this->login(['create-vehicles']);

        $response = $this->asBearer($token)->postJson('/api/v1/admin/vehicles', [
            'vehicle_number' => 'BJ-0001-AA',
            'vehicle_type' => 'moto',
            'notes' => 'Neuf',
        ])->assertCreated();

        $vehicle = Vehicle::query()->where('vehicle_number', 'BJ-0001-AA')->firstOrFail();
        $response->assertJsonPath('id', $vehicle->id);
        $this->assertTrue($vehicle->is_active);
        $this->assertNull($vehicle->owner_id);
        $this->assertSame('Neuf', $vehicle->notes);
    }

    public function test_creation_refuses_a_duplicate_number_and_requires_its_permission(): void
    {
        Vehicle::factory()->create(['vehicle_number' => 'BJ-PRIS']);

        $this->asBearer($this->login(['view-vehicles']))
            ->postJson('/api/v1/admin/vehicles', ['vehicle_number' => 'BJ-X', 'vehicle_type' => 'moto'])
            ->assertForbidden();

        $this->asBearer($this->login(['create-vehicles']))
            ->postJson('/api/v1/admin/vehicles', ['vehicle_number' => 'BJ-PRIS', 'vehicle_type' => 'moto'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_number']);
    }

    public function test_a_vehicle_is_updated_and_its_notes_can_be_cleared(): void
    {
        // Défaut corrigé : `$data['notes'] ?? $vehicle->notes` gardait l'ancienne note
        // quand le champ était vidé.
        $token = $this->login(['edit-vehicles']);
        $vehicle = Vehicle::factory()->create(['notes' => 'À effacer']);

        $this->asBearer($token)->putJson("/api/v1/admin/vehicles/{$vehicle->id}", [
            'vehicle_number' => 'BJ-NOUVEAU',
            'vehicle_type' => 'car',
            'notes' => null,
        ])->assertOk()->assertJsonPath('vehicle_number', 'BJ-NOUVEAU');

        $vehicle->refresh();
        $this->assertSame('car', $vehicle->vehicle_type);
        $this->assertNull($vehicle->notes);
    }

    // ----- Suppression -----------------------------------------------------------------

    public function test_a_vehicle_without_history_is_deleted(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->asBearer($this->login(['delete-vehicles']))
            ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
    }

    public function test_a_vehicle_with_a_finished_vehicle_contract_is_not_deleted(): void
    {
        $vehicle = Vehicle::factory()->create();
        $contract = VehicleContract::factory()->forVehicle($vehicle)->completed()->create();

        $this->asBearer($this->login(['delete-vehicles']))
            ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'VEHICLE_NOT_DELETABLE');

        $this->assertDatabaseHas('vehicle_contracts', ['id' => $contract->id]);
    }

    public function test_a_vehicle_with_a_finished_driver_contract_is_not_deleted(): void
    {
        $vehicle = Vehicle::factory()->create();
        $contract = VehicleContract::factory()->forVehicle($vehicle)->completed()->create();
        DriverContract::factory()->forVehicleContract($contract)->create(['status' => 'completed']);
        // Le contrat véhicule seul suffirait : on s'assure que le contrat agent n'est pas
        // effacé en même temps.
        $this->asBearer($this->login(['delete-vehicles']))
            ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}")
            ->assertStatus(409);

        $this->assertSame(1, DriverContract::query()->where('vehicle_id', $vehicle->id)->count());
    }

    public function test_deletion_requires_delete_vehicles(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->asBearer($this->login(['view-vehicles', 'edit-vehicles']))
            ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}")
            ->assertForbidden();
    }

    // ----- Plus de geste manuel sur l'état (spec 2026-10-21, §3.1) -----------------

    public function test_the_manual_state_routes_are_gone(): void
    {
        $token = $this->login(['edit-vehicles', 'manage-vehicle-pauses']);
        $vehicle = Vehicle::factory()->create(['is_active' => true]);
        $pause = VehiclePause::factory()->create(['vehicle_id' => $vehicle->id, 'end_date' => null]);

        $this->asBearer($token)->postJson("/api/v1/admin/vehicles/{$vehicle->id}/toggle-status", ['is_active' => false])->assertNotFound();
        $this->asBearer($token)->postJson("/api/v1/admin/vehicles/{$vehicle->id}/pauses", [])->assertNotFound();
        $this->asBearer($token)->patchJson("/api/v1/admin/vehicle-pauses/{$pause->id}/end", [])->assertNotFound();
        $this->asBearer($token)->deleteJson("/api/v1/admin/vehicle-pauses/{$pause->id}")->assertNotFound();

        $this->assertTrue($vehicle->fresh()->is_active);
        $this->assertNull($pause->fresh()->end_date);
    }
}
