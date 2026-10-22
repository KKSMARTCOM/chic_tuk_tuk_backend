<?php

namespace App\Domains\Finance\Domain;

use Illuminate\Support\Str;

/**
 * L'instantané d'une fiche : ce qu'une fiche validée garde dans `figures`, et ce que le
 * brouillon recalcule à chaque lecture.
 */
final class StatementFigures
{
    /** Les listes de paiements vivent dans `payments.remuneration_statement_id`, pas dans l'instantané. */
    private const NOT_SNAPSHOTTED = ['revenuePaymentIds', 'recoveredPaymentIds'];

    public function __construct(
        public readonly string $month,
        public readonly string $ownerName,
        public readonly string $vehicleNumber,
        public readonly int $contractMonths,
        public readonly string $startDate,
        public readonly int $businessDays,
        public readonly int $pauseDays,
        public readonly int $immobilizationDays,
        public readonly int $countedDays,
        public readonly float $dailyAmount,
        public readonly float $revenue,
        public readonly float $recovered,
        public readonly int $pendingCount,
        public readonly float $pendingAmount,
        /** @var list<array{key: string, label: string, due: float, outstanding: float, ceiling: float, proposed: float, deducted: float}> `ceiling` : le plus que la ligne peut prélever ce mois-ci */
        public readonly array $charges,
        public readonly float $deductedTotal,
        public readonly float $balanceDue,
        /** Les recettes du mois sous le revenu total, puis au-delà (spec 2026-10-12, §3.2). */
        public readonly float $revenueWithinTarget,
        public readonly float $revenueBeyondTarget,
        /** Ce qui revient à l'agence ; zéro pour une fiche validée avant le 2026-10-12. */
        public readonly float $agencyShare,
        public readonly float $cumulativeRevenue,
        public readonly float $cumulativeCharges,
        public readonly float $cumulativeNet,
        public readonly int $workedMonths,
        public readonly int $pauseDaysTaken,
        public readonly int $pauseAllowance,
        public readonly bool $isFirstStatement,
        /** @var list<string> */
        public readonly array $anomalies,
        /** Jours ouvrés − pause − immobilisation : le calendrier seul (spec 2026-10-22, §2). */
        public readonly int $calendarDays = 0,
        public readonly int $recoveredCount = 0,
        /** Jours comptabilisés × montant journalier : ce sur quoi se calcule le solde dû. */
        public readonly float $totalRevenue = 0,
        /** Encaissés du mois + recouvrés : l'argent réellement reçu. */
        public readonly float $receivedAmount = 0,
        /** `totalRevenue − receivedAmount` : non nul, il bloque la validation. */
        public readonly float $gap = 0,
        /** @var list<string> ce qui explique l'écart */
        public readonly array $gapCauses = [],
        /** Fiche ancienne dont le total ne tombe pas juste : l'ancienne présentation (§5). */
        public readonly bool $legacyLayout = false,
        /** @var list<string> */
        public readonly array $revenuePaymentIds = [],
        /** @var list<string> */
        public readonly array $recoveredPaymentIds = [],
    ) {}

    /** @return array<string, mixed> en snake_case, sans les identifiants de paiements */
    public function toArray(): array
    {
        $array = [];

        foreach (get_object_vars($this) as $property => $value) {
            if (! in_array($property, self::NOT_SNAPSHOTTED, true)) {
                $array[Str::snake($property)] = $value;
            }
        }

        return $array;
    }

    /** @param  array<string, mixed>  $figures */
    public static function fromArray(array $figures): self
    {
        $arguments = [];

        foreach ((new \ReflectionClass(self::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (in_array($name, self::NOT_SNAPSHOTTED, true)) {
                $arguments[$name] = [];

                continue;
            }

            $value = $figures[Str::snake($name)] ?? null;
            $arguments[$name] = match ($parameter->getType()?->getName()) {
                'string' => (string) ($value ?? ''),
                'int' => (int) ($value ?? 0),
                'float' => (float) ($value ?? 0),
                'bool' => (bool) ($value ?? false),
                'array' => (array) ($value ?? []),
            };
        }

        return new self(...$arguments);
    }
}
