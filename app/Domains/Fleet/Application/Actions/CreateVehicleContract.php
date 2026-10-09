<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\CreateVehicleContractData;
use App\Domains\Fleet\Domain\ContractTerms;
use App\Domains\Fleet\Domain\VehicleContractRules;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Support\Facades\DB;

/** Créer le contrat d'un véhicule — ex-Admin\VehicleContractController::store(). */
final class CreateVehicleContract
{
    public function __construct(private readonly CheckContractTotal $checkTotal) {}

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
     *
     * Le contrat fige le versement et la taxe journaliers de sa durée (2026-09-29).
     */
    private function createContract(array $data): VehicleContract
    {
        ($this->checkTotal)((int) $data['contract_months'], $data['total_amount']);

        return DB::transaction(function () use ($data) {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($data['vehicle_id']);
            VehicleContractRules::assertCanCarryAnActiveContract($vehicle);

            return VehicleContract::create([
                'vehicle_id' => $vehicle->id,
                'owner_id' => $vehicle->owner_id,
                'total_amount' => $data['total_amount'],
                'monthly_payment' => $data['monthly_payment'] ?? 0,
                'start_date' => ($data['pending'] ?? false) ? null : $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                // En attente de son premier agent : il commencera avec lui (2026-10-09).
                'status' => ($data['pending'] ?? false) ? 'pending' : 'active',
                'notes' => $data['notes'] ?? null,
                'contract_months' => $data['contract_months'],
                ...ContractTerms::dailyAmountsFor((int) $data['contract_months']),
                ...ContractTerms::chargesFrom($data),
            ]);
        });
    }
}
