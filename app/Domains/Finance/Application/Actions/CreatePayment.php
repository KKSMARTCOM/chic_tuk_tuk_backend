<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Enregistrer un paiement — ex-`PaymentService::create()`, déplacé sans changement le
 * 2026-09-27.
 */
final class CreatePayment
{
    public function __construct(
        private readonly CheckPaymentData $checkPaymentData,
        private readonly CheckCollectionDate $checkCollectionDate,
    ) {}

    /**
     * Créer un paiement
     */
    public function __invoke(array $data)
    {
        $driver = Driver::with('activeDriverContract')->findOrFail($data['driver_id']);
        $isContract = ($data['payment_type'] ?? null) === 'contract';

        if ($isContract && ! empty($data['driver_contract_id'])) {
            // Un contrat EN COURS OU TERMINÉ de l'agent (2026-10-01) : c'est ce qui rattache
            // le paiement d'un agent parti au contrat sur lequel il a travaillé.
            $contract = DriverContract::query()->whereKey($data['driver_contract_id'])->where('driver_id', $driver->id)->first();
            if ($contract === null) {
                throw ValidationException::withMessages(['driver_contract_id' => 'Ce contrat n\'est pas celui de l\'agent.']);
            }
            $data['vehicle_contract_id'] = $contract->vehicle_contract_id;
        } elseif ($driver->activeDriverContract) {
            $data['driver_contract_id'] = $driver->activeDriverContract->id;
            $data['vehicle_contract_id'] = $driver->activeDriverContract->vehicle_contract_id;
        } else {
            unset($data['driver_contract_id']);
        }

        // Sans mois, un paiement de contrat n'entrait dans aucune fiche (2026-10-01).
        if ($isContract) {
            $data['payment_month'] = Carbon::parse($data['payment_date'])->startOfMonth()->toDateString();
        }

        ($this->checkPaymentData)($data);

        // La date doit tomber dans le CONTRAT VÉHICULE, qui porte les fiches — pas dans le
        // contrat de l'agent : un agent parti règle souvent son arriéré après son départ,
        // pendant que le contrat véhicule continue (2026-10-01).
        if ($isContract && ! empty($data['vehicle_contract_id'])) {
            $vehicleContract = VehicleContract::findOrFail($data['vehicle_contract_id']);
            $date = Carbon::parse($data['payment_date'])->startOfDay();
            if ($date->lt($vehicleContract->start_date) || ($vehicleContract->end_date !== null && $date->gt($vehicleContract->end_date))) {
                $end = $vehicleContract->end_date?->format('d/m/Y');
                throw ValidationException::withMessages(['payment_date' => 'La date est hors du contrat propriétaire (du '
                    .$vehicleContract->start_date->format('d/m/Y').($end ? " au {$end}" : ', en cours').') : aucune fiche ne compterait ce paiement.']);
            }
        }

        // Il naît validé, encaissé à sa date : mêmes garde-fous qu'une validation (spec
        // 2026-10-01, §4.4), sous le champ de la date du formulaire.
        if ($isContract) {
            try {
                ($this->checkCollectionDate)(new Payment([
                    'vehicle_contract_id' => $data['vehicle_contract_id'] ?? null,
                    'payment_month' => $data['payment_month'],
                ]), Carbon::parse($data['payment_date']));
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['payment_date' => $e->errors()['collected_on'] ?? $e->errors()]);
            }
        }

        $payment = Payment::create([
            'driver_id' => $data['driver_id'],
            'payment_type' => $data['payment_type'] ?? 'commission',
            'amount' => $data['amount'],
            'payment_month' => $data['payment_month'] ?? null,
            'payment_method' => $data['payment_method'],
            'payment_date' => $data['payment_date'],
            'notes' => $data['notes'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'status' => 'completed',
            'vehicle_contract_id' => $data['vehicle_contract_id'] ?? null,
            'driver_contract_id' => $data['driver_contract_id'] ?? null,
            'net_amount' => $data['net_amount'] ?? null,
            // Il naît validé : encaissé à sa date (2026-10-01).
            'collected_on' => $data['payment_date'],
        ]);

        return $payment;
    }
}
