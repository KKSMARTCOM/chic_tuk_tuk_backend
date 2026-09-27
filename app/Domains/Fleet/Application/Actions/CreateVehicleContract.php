<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Consts\VehicleContractConsts;
use App\Domains\Fleet\Application\Data\CreateVehicleContractData;
use App\Domains\Fleet\Domain\VehicleContractRules;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Support\Facades\DB;

/** Créer le contrat d'un véhicule — ex-Admin\VehicleContractController::store(). */
final class CreateVehicleContract
{
    public function __invoke(CreateVehicleContractData $data): VehicleContract
    {
        return $this->createContract($data->toServicePayload());
    }

    /**
     * Crée le contrat d'un véhicule, au nom de son propriétaire.
     *
     * Corrigé le 2026-09-26 : un véhicule sans propriétaire faisait échouer l'insertion
     * (`owner_id` NOT NULL), et rien n'empêchait un second contrat actif. Les charges
     * laissées vides prennent les valeurs par défaut, comme depuis l'écran propriétaire.
     */
    private function createContract(array $data): VehicleContract
    {
        return DB::transaction(function () use ($data) {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($data['vehicle_id']);
            VehicleContractRules::assertCanCarryAnActiveContract($vehicle);

            return VehicleContract::create([
                'vehicle_id' => $vehicle->id,
                'owner_id' => $vehicle->owner_id,
                'total_amount' => $data['total_amount'],
                'monthly_payment' => $data['monthly_payment'] ?? 0,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'contract_months' => $data['contract_months'] ?? null,
                'unlimited_internet' => $data['unlimited_internet'] ?? VehicleContractConsts::DEFAULT_UNLIMITED_INTERNET,
                'spotify_premium' => $data['spotify_premium'] ?? VehicleContractConsts::DEFAULT_SPOTIFY_PREMIUM,
                'manager_remuneration' => $data['manager_remuneration'] ?? VehicleContractConsts::DEFAULT_MANAGER_REMUNERATION,
            ]);
        });
    }
}
