<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Un mois du récapitulatif que reçoit un propriétaire, fusionné avec ses fiches de
 * rémunération (spec 2026-09-30, §6.1).
 *
 * | Mois                                  | `status`            | Chiffres                                   |
 * | ------------------------------------- | ------------------- | ------------------------------------------ |
 * | fiche validée                         | `validated`         | les chiffres FIGÉS de la fiche, `hasPdf`   |
 * | mois en cours                         | `current`           | une estimation, `isEstimate`               |
 * | mois clos ≥ `first_month`, sans fiche | `review_pending`    | les jours seulement ; montants à `null`    |
 * | mois clos < `first_month`             | `before_statements` | les jours et les recettes validées ; solde à `null` |
 *
 * ⚠️ Un mois clos sans fiche validée ne montre AUCUN solde : le propriétaire ne doit pas
 * lire un montant que la relecture peut encore changer. Le report du déficit
 * (2026-09-29) a disparu : le compte de charges des fiches le remplace.
 */
final class MonthlyPayoutData extends BaseData
{
    public function __construct(
        public string $month,
        public bool $isCurrent,
        public bool $isWorked,
        #[LiteralTypeScriptType('"current" | "review_pending" | "validated" | "before_statements"')]
        public string $status,
        public int $businessDays,
        public int $countedDays,
        public int $agentLeaveDays,
        public int $immobilizationDays,
        public int $pendingCount,
        public float $pendingAmount,
        public ?float $revenue,
        public ?float $recovered,
        public ?float $chargesDeducted,
        public ?float $balanceDue,
        public bool $isEstimate,
        public ?string $statementId,
        public ?string $statementNumber,
        public bool $hasPdf,
        /** Les mois travaillés jusqu'à ce mois inclus : le « 04 » de « Mois 04 | 24 ». */
        public int $workedMonthsToDate,
    ) {}
}
