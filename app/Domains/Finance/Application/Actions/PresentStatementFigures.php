<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Domain\StatementFigures;
use App\Models\Payment;
use App\Models\RemunerationStatement;

/**
 * Les chiffres d'une fiche VALIDÉE, tels qu'on les montre (spec 2026-10-22, §5).
 *
 * Une fiche figée depuis la formule des jours payés se relit telle quelle. Une fiche plus
 * ancienne n'a que les jours du calendrier : on recompose ses jours comptabilisés à partir
 * de ses paiements en instance et de ses paiements recouvrés, sans toucher à son solde dû.
 * Si le total recomposé ne tombe pas sur l'argent qu'elle a reçu — une fiche validée avec un
 * écart, avant que l'écart ne bloque —, elle garde l'ancienne présentation.
 */
final class PresentStatementFigures
{
    /** @param  int|null  $recoveredCount  déjà compté par l'appelant (le récapitulatif, en une requête) */
    public function __invoke(RemunerationStatement $statement, ?int $recoveredCount = null): StatementFigures
    {
        $figures = $statement->figures ?? [];

        if (array_key_exists('total_revenue', $figures)) {
            return StatementFigures::fromArray($figures);
        }

        $recoveredCount ??= Payment::query()
            ->where('remuneration_statement_id', $statement->id)
            ->where('payment_month', '<', $statement->month->toDateString())
            ->count();
        $calendarDays = (int) ($figures['counted_days'] ?? 0);
        $countedDays = max(0, $calendarDays - (int) ($figures['pending_count'] ?? 0) + $recoveredCount);
        $totalRevenue = round($countedDays * (float) ($figures['daily_amount'] ?? 0), 2);
        $receivedAmount = (float) ($figures['revenue'] ?? 0) + (float) ($figures['recovered'] ?? 0);

        if (abs($totalRevenue - $receivedAmount) >= 0.01) {
            return StatementFigures::fromArray([
                ...$figures,
                'calendar_days' => $calendarDays,
                'total_revenue' => (float) ($figures['revenue'] ?? 0),
                'received_amount' => $receivedAmount,
                'legacy_layout' => true,
            ]);
        }

        return StatementFigures::fromArray([
            ...$figures,
            // Le paiement en attente est maintenant retranché des jours : ce n'est plus une anomalie.
            'anomalies' => array_values(array_filter($figures['anomalies'] ?? [], fn ($a) => ! str_ends_with($a, 'paiement(s) du mois encore en attente.'))),
            'calendar_days' => $calendarDays,
            'counted_days' => $countedDays,
            'recovered_count' => $recoveredCount,
            'total_revenue' => $totalRevenue,
            'received_amount' => $receivedAmount,
            'gap' => 0,
        ]);
    }
}
