<?php

namespace Tests\Feature\Workforce;

use App\Domains\Workforce\Application\Actions\DeleteDriverContract;
use App\Domains\Workforce\Application\Actions\EndDriverContract;
use App\Domains\Workforce\Application\Actions\ListOwnersForNewContract;
use App\Domains\Workforce\Application\Data\EndDriverContractData;
use App\Models\DriverContract;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Supprimer un contrat agent terminé défait sa fin (spec 2026-10-21, §3.6) : sans cela, sa
 * pause `agent_change` resterait ouverte et le véhicule ne figurerait plus dans aucune liste.
 */
class DriverContractUndoEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_ended_contract_frees_its_vehicle(): void
    {
        $vehicleContract = VehicleContract::factory()->create();
        $vehicleContract->vehicle->owner->assignRole(Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']));
        $contract = DriverContract::factory()->forVehicleContract($vehicleContract)->create([
            'start_date' => now()->subDays(10)->toDateString(),
        ]);
        app(EndDriverContract::class)($contract, new EndDriverContractData(now()->toDateString(), 'demission'));

        app(DeleteDriverContract::class)($contract->refresh());

        $vehicle = $vehicleContract->vehicle->refresh();
        $this->assertFalse(VehiclePause::where('vehicle_id', $vehicle->id)->exists());
        $this->assertTrue((bool) $vehicle->is_active);

        $offered = collect(app(ListOwnersForNewContract::class)())
            ->flatMap(fn ($owner) => collect($owner->vehicles)->pluck('id'));
        $this->assertContains($vehicle->id, $offered->all());
    }
}
