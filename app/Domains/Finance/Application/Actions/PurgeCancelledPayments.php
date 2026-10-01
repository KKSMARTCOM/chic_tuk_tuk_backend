<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Payment;

/**
 * Vider les paiements de CONTRAT annulés (2026-10-01) : ils s'accumulent au fil des
 * reconstitutions. Les commissions annulées ont leur propre écran et restent.
 *
 * Un paiement annulé n'est compté par aucune fiche validée : l'annulation le refuse, et
 * l'annulation d'une fiche détache ses paiements.
 *
 * @return array{count: int, total: float}
 */
final class PurgeCancelledPayments
{
    public function __invoke(): array
    {
        $query = Payment::query()->where('payment_type', 'contract')->where('status', 'cancelled');
        $total = (float) (clone $query)->sum('net_amount');
        $count = $query->delete();

        return ['count' => $count, 'total' => $total];
    }
}
