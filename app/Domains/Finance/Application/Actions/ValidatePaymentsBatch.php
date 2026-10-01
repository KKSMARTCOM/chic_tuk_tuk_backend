<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Valider plusieurs paiements à une même date d'encaissement (spec 2026-10-01, §4.5).
 *
 * Tout ou rien, dans une transaction. SILENCIEUSE par défaut : reconstituer un mois ne doit
 * pas envoyer vingt notifications à un agent parti depuis. Les notifications demandées
 * partent APRÈS la transaction.
 */
final class ValidatePaymentsBatch
{
    public function __construct(
        private readonly ValidatePayment $validate,
        private readonly Notifier $notifier,
    ) {}

    /** @param  list<string>  $paymentIds */
    public function __invoke(array $paymentIds, Carbon $collectedOn, bool $notifyDrivers): int
    {
        $payments = DB::transaction(function () use ($paymentIds, $collectedOn) {
            // Les contrats véhicule AVANT les paiements, dans l'ordre de la validation d'une
            // fiche : deux verrous pris en sens inverse s'interbloqueraient.
            $vehicleContractIds = Payment::query()->whereIn('id', $paymentIds)->whereNotNull('vehicle_contract_id')
                ->distinct()->orderBy('vehicle_contract_id')->pluck('vehicle_contract_id');
            VehicleContract::query()->whereIn('id', $vehicleContractIds)->orderBy('id')->lockForUpdate()->get();
            $payments = Payment::query()->whereIn('id', $paymentIds)->lockForUpdate()->get();
            $notPending = $payments->where('status', '!=', 'pending')->count() + count(array_unique($paymentIds)) - $payments->count();
            if ($notPending > 0) {
                throw new ApiException(409, 'PAYMENT_NOT_PENDING', "Seuls des paiements en attente se valident : {$notPending} paiement(s) de la sélection ne le sont pas ou plus. Rechargez la liste.");
            }

            return $payments->each(fn (Payment $p) => ($this->validate)($p, $collectedOn, notify: false));
        });

        if ($notifyDrivers) {
            $payments->each(fn (Payment $p) => $this->notifier->paymentValidated($p->fresh()->load('driver.user')));
        }

        return $payments->count();
    }
}
