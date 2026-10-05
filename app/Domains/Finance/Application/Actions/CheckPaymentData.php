<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Commission;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;

/**
 * Les contrôles d'un paiement avant sa création ou sa modification — ex-
 * `PaymentService::validatePaymentData()`, déplacé sans changement le 2026-09-27.
 *
 * ⚠️ `$data` est passé PAR RÉFÉRENCE, comme dans l'original : les contrôles complètent
 * la charge utile de l'appelant.
 */
final class CheckPaymentData
{
    public function __construct(private readonly ComputeDriverSubscriptionRevenue $computeRevenue) {}

    /**
     * Valider les données de paiement selon le type
     */
    public function __invoke(array &$data, ?string $existingPaymentId = null): void
    {
        $data['payment_type'] = $data['payment_type'] ?? 'commission';

        if ($data['payment_type'] === 'commission') {
            // $driver = Driver::findOrFail($data['driver_id']);
            $totalDue = Commission::where('driver_id', $data['driver_id'])->where('status', 'active')->sum('amount');
            // Les paiements ANNULÉS ne comptent plus comme payés (2026-09-26).
            $totalPaid = Payment::where('driver_id', $data['driver_id'])->where('payment_type', 'commission')
                ->where('status', '!=', 'cancelled');

            if ($existingPaymentId) {
                $totalPaid->where('id', '!=', $existingPaymentId);
            }

            $totalPaid = $totalPaid->sum('amount');
            $remaining = $totalDue - $totalPaid;

            if ($data['amount'] > $remaining) {
                throw new ApiException(
                    409,
                    'PAYMENT_EXCEEDS_BALANCE',
                    "Le montant saisi ({$data['amount']}) dépasse la commission restante due ({$remaining})."
                );
            }

            return;
        }

        if ($data['payment_type'] === 'subscription_revenue') {
            $remaining = ($this->computeRevenue)($data['driver_id'])['balance_due'];

            if ($existingPaymentId) {
                $previousAmount = Payment::where('id', $existingPaymentId)->value('amount') ?? 0;
                $remaining += $previousAmount;
            }

            if ($data['amount'] > $remaining) {
                throw new ApiException(
                    409,
                    'PAYMENT_EXCEEDS_BALANCE',
                    "Le montant saisi ({$data['amount']}) dépasse le revenu abonnement restant dû ({$remaining})."
                );
            }

            return;
        }

        if ($data['payment_type'] === 'contract') {
            if (empty($data['vehicle_contract_id']) && empty($data['driver_contract_id'])) {
                throw new ApiException(409, 'PAYMENT_WITHOUT_CONTRACT', 'Un paiement contractuel doit être lié à un contrat agent ou véhicule.');
            }

            if (! empty($data['driver_contract_id'])) {
                $driverContract = DriverContract::findOrFail($data['driver_contract_id']);

                if (empty($data['vehicle_contract_id'])) {
                    $data['vehicle_contract_id'] = $driverContract->vehicle_contract_id;
                }
            }

            if (! empty($data['vehicle_contract_id'])) {
                $vehicleContract = VehicleContract::findOrFail($data['vehicle_contract_id']);
                // Les paiements ANNULÉS ne comptent plus comme payés (2026-09-26).
                $contractPaid = Payment::where('vehicle_contract_id', $vehicleContract->id)->where('payment_type', 'contract')
                    ->where('status', '!=', 'cancelled');

                if ($existingPaymentId) {
                    $contractPaid->where('id', '!=', $existingPaymentId);
                }

                $contractPaid = $contractPaid->sum('amount');

                $remaining = max(0, (float) $vehicleContract->total_amount - $contractPaid);

                if ($data['amount'] > $remaining) {
                    throw new ApiException(
                        409,
                        'PAYMENT_EXCEEDS_BALANCE',
                        "Le montant saisi ({$data['amount']}) dépasse le solde restant du contrat propriétaire ({$remaining})."
                    );
                }

                // La taxe journalière est celle que le contrat a figée à sa création.
                $data['net_amount'] = $data['amount'] - (float) ($vehicleContract->daily_tax ?? 0);
            }
        }
    }
}
