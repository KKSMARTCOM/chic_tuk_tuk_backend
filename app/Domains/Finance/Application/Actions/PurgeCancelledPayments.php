<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Payment;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Vider les paiements de CONTRAT annulés (2026-10-01) : ils s'accumulent au fil des
 * reconstitutions. Les commissions annulées ont leur propre écran et restent.
 *
 * Tous, ou une sélection de la liste (2026-11-03) : la sélection est tout ou rien, et ne
 * prend que des paiements de contrat annulés.
 *
 * Un paiement annulé n'est compté par aucune fiche validée : l'annulation le refuse, et
 * l'annulation d'une fiche détache ses paiements.
 */
final class PurgeCancelledPayments
{
    /**
     * @param  list<string>|null  $paymentIds  null : tous les paiements de contrat annulés
     * @return array{count: int, total: float}
     */
    public function __invoke(?array $paymentIds = null): array
    {
        return DB::transaction(function () use ($paymentIds) {
            $query = Payment::query()->where('payment_type', 'contract')->where('status', 'cancelled');

            if ($paymentIds !== null) {
                $ids = array_values(array_unique($paymentIds));
                $found = Payment::query()->whereIn('id', $ids)->lockForUpdate()->get();
                if ($found->count() !== count($ids)) {
                    throw new ApiException(409, 'PAYMENT_NOT_FOUND', 'Des paiements de la sélection n\'existent plus. Rechargez la liste.');
                }
                if ($found->contains(fn (Payment $p) => $p->payment_type !== 'contract' || $p->status !== 'cancelled')) {
                    throw new ApiException(409, 'PAYMENT_NOT_CANCELLED', 'Seuls des paiements de contrat annulés se suppriment : retirez les autres de la sélection.');
                }
                $query->whereIn('id', $ids);
            }

            $total = (float) (clone $query)->sum('net_amount');
            $count = $query->delete();

            return ['count' => $count, 'total' => $total];
        });
    }
}
