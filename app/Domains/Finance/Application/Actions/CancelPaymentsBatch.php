<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Annuler plusieurs paiements d'un coup, avec un motif (2026-10-01) — le nettoyage d'avant
 * reconstitution : montants globaux remplacés par des paiements journaliers, doublons.
 *
 * Tout ou rien, et les mêmes refus qu'une annulation à l'unité. SILENCIEUSE par défaut,
 * comme la validation groupée. Verrous dans l'ordre du projet : contrats véhicule, puis
 * paiements.
 */
final class CancelPaymentsBatch
{
    public function __construct(private readonly Notifier $notifier) {}

    /** @param  list<string>  $paymentIds */
    public function __invoke(array $paymentIds, string $reason, bool $notifyDrivers): int
    {
        $payments = DB::transaction(function () use ($paymentIds, $reason) {
            $vehicleContractIds = Payment::query()->whereIn('id', $paymentIds)->whereNotNull('vehicle_contract_id')
                ->distinct()->orderBy('vehicle_contract_id')->pluck('vehicle_contract_id');
            VehicleContract::query()->whereIn('id', $vehicleContractIds)->orderBy('id')->lockForUpdate()->get();
            $payments = Payment::query()->with('remunerationStatement')->whereIn('id', $paymentIds)->lockForUpdate()->get();

            if ($payments->count() !== count(array_unique($paymentIds))) {
                throw new ApiException(409, 'PAYMENT_NOT_FOUND', 'Des paiements de la sélection n\'existent plus. Rechargez la liste.');
            }
            if ($payments->contains(fn (Payment $p) => $p->remunerationStatement?->status === 'validated')) {
                throw new ApiException(409, 'PAYMENT_IN_VALIDATED_STATEMENT', 'Un paiement de la sélection est compté dans une fiche de rémunération validée : annulez d\'abord la fiche.');
            }
            if ($payments->contains('status', 'cancelled')) {
                throw new ApiException(409, 'PAYMENT_ALREADY_CANCELLED', 'Un paiement de la sélection est déjà annulé.');
            }

            foreach ($payments as $payment) {
                $payment->update([
                    'status' => 'cancelled',
                    'notes' => trim(($payment->notes ? $payment->notes."\n" : '').'Annulé : '.$reason),
                ]);
            }

            return $payments;
        });

        if ($notifyDrivers) {
            $payments->each(fn (Payment $p) => $this->notifier->paymentCancelled($p->fresh()->load('driver.user')));
        }

        return $payments->count();
    }
}
