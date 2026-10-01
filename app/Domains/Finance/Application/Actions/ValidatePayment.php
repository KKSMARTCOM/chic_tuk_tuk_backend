<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Valider un paiement — ex-`PaymentService::validatePayment()`, déplacé sans changement
 * le 2026-09-27.
 */
final class ValidatePayment
{
    public function __construct(private readonly CheckCollectionDate $checkCollectionDate) {}

    /**
     * Valider un paiement EN ATTENTE, à sa date d'encaissement — aujourd'hui par défaut —,
     * et le dire à l'agent, sauf validation groupée silencieuse (2026-10-01).
     *
     * Corrigé le 2026-09-26 : rien n'empêchait de valider un paiement annulé — l'écran ne
     * le proposait pas, le serveur l'acceptait, et l'agent était notifié.
     */
    public function __invoke(Payment $payment, ?Carbon $collectedOn = null, bool $notify = true): Payment
    {
        $collectedOn ??= Carbon::today();

        // Le contrat véhicule verrouillé, comme à la validation d'une fiche : sinon un paiement
        // validé pendant qu'une fiche du même contrat se valide passerait le garde-fou et
        // glisserait dans la fiche suivante (revue du 2026-10-01).
        $payment = DB::transaction(function () use ($payment, $collectedOn) {
            if ($payment->vehicle_contract_id !== null) {
                VehicleContract::query()->lockForUpdate()->find($payment->vehicle_contract_id);
            }
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'pending') {
                throw new ApiException(409, 'PAYMENT_NOT_PENDING', 'Seul un paiement en attente se valide.');
            }

            ($this->checkCollectionDate)($payment, $collectedOn);
            $payment->update(['status' => 'completed', 'collected_on' => $collectedOn->toDateString()]);

            return $payment;
        });

        // C'est l'ACTION qui est notifiée, pas la création du paiement : celle-ci est
        // majoritairement automatique et quotidienne.
        if ($notify) {
            app(Notifier::class)->paymentValidated($payment->fresh()->load('driver.user'));
        }

        return $payment->refresh();
    }
}
