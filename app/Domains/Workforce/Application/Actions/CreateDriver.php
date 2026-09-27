<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\CreateDriverData;
use App\Domains\Workforce\Domain\VehicleAssignmentRules;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Créer un agent — ex-Admin\DriverController::store(). */
final class CreateDriver
{
    public function __construct(private readonly CreateRenewalContract $createRenewalContract) {}

    public function __invoke(CreateDriverData $data): User
    {
        try {
            return $this->createDriver($data->toServicePayload());
        } catch (\Exception $e) {
            // `createDriver()` lève des \Exception génériques (véhicule
            // sans contrat actif, règle « 1 véhicule = 1 agent »...) — le même chemin
            // que le Blade, qui les affiche telles quelles en message flash.
            throw new ApiException(422, 'DRIVER_CREATE_FAILED', $e->getMessage());
        }
    }

    private function createDriver(array $data)
    {
        return DB::transaction(function () use ($data) {

            $contractMode = $data['_contract_mode'] ?? 'new';

            // 1. Créer l'utilisateur agent
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
                'profil' => 'driver',
                'is_active' => true,
                'adresse' => $data['adresse'] ?? null,
            ]);

            $user->assignRole('driver');

            // 2. Créer le profil Driver
            $driver = Driver::create([
                'user_id' => $user->id,
                'license_number' => $data['license_number'],
                'is_available' => true,
                'agent_code' => $data['agent_code'] ?? $data['renewal_agent_code'] ?? null,
                'agent_id' => $data['agent_id'] ?? $data['renewal_agent_id'] ?? null,
            ]);

            // 3. Résoudre le véhicule selon le mode
            if ($contractMode === 'renewal') {
                ($this->createRenewalContract)($driver, $data);
            } else {
                $this->createNewContract($driver, $data);
            }

            return $user->load('driver');
        });
    }

    private function createNewContract(Driver $driver, array $data): void
    {
        // Pas de véhicule sélectionné → pas de contrat
        if (empty($data['vehicle_id']) || empty($data['owner_id'])) {
            return;
        }

        $vehicle = Vehicle::findOrFail($data['vehicle_id']);

        if ($vehicle->owner_id !== $data['owner_id']) {
            throw new \Exception('Ce véhicule n\'appartient pas au propriétaire sélectionné.');
        }

        $vehicleContract = $vehicle->activeVehicleContract;

        if (! $vehicleContract) {
            throw new \Exception('Le véhicule sélectionné n\'a pas de contrat actif.');
        }

        // Validation règles métier (1 véhicule = 1 agent)
        VehicleAssignmentRules::assertAssignable($vehicle);

        // Clôturer la pause active du véhicule si existante
        $vehicle->activePause?->update(['end_date' => $data['start_date'] ?? now()->toDateString()]);

        DriverContract::create([
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_contract_id' => $vehicleContract->id,
            'start_date' => $data['start_date'] ?? now()->toDateString(),
            'contract_months' => $data['contract_months'] ?? $vehicleContract->contract_months,
            'status' => 'active',
        ]);
    }
}
