<?php

namespace App\Domains\Workforce\Domain;

use App\Models\Driver;
use App\Models\DriverContract;
use Carbon\Carbon;

/**
 * Le solde de pauses d'un agent, rapporté à UN contrat donné.
 *
 * ## Pourquoi le contrat est un paramètre
 *
 * Un solde de pauses n'existe pas dans l'absolu : le droit vaut `2 × contract_months`, et
 * `DriverContractService` remet le compteur à zéro à la clôture d'un contrat. Deux écrans
 * ont donc besoin de la même formule sur deux contrats différents :
 *
 *  - l'écran de l'AGENT montre ce à quoi il a droit MAINTENANT, donc son contrat actif —
 *    et zéro s'il n'en a pas, parce qu'il n'a alors aucun droit en cours ;
 *  - la liste de l'ADMINISTRATION montre aussi les anciens agents, ceux qui n'ont pas
 *    été au bout de leur contrat. Leur afficher des zéros ne dirait rien de leur dossier :
 *    on prend leur DERNIER contrat, et le solde raconte alors ce qu'ils ont pris.
 *
 * Une seule implémentation, deux appelants, chacun passant le contrat qu'il veut dire.
 * Deux implémentations divergeraient, et c'est exactement ce qui avait produit l'écran
 * contradictoire du 2026-09-21.
 */
final class LeaveBalance
{
    /** Le projet accorde deux jours de pause par mois de contrat. */
    public const JOURS_PAR_MOIS = 2;

    private function __construct(
        public readonly int $contractMonths,
        public readonly int $totalDays,
        public readonly int $usedDays,
        public readonly int $availableDays,
        public readonly int $remainingDays,
        // Les jours acquis à date : deux par mois entamé, bornés par la fin du contrat.
        public readonly int $accruedDays = 0,
    ) {}

    /** Un solde vide — aucun contrat, donc aucun droit et rien de pris. */
    public static function aucun(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    public static function pour(Driver $driver, ?DriverContract $contrat): self
    {
        if (! $contrat) {
            return self::aucun();
        }

        $mois = (int) ($contrat->contract_months ?? 24);
        $total = self::JOURS_PAR_MOIS * $mois;

        $pris = self::joursParStatut($driver, $contrat, 'completed');
        $acquis = self::JOURS_PAR_MOIS * self::moisEcoules($contrat, $mois);

        return new self(
            contractMonths: $mois,
            totalDays: $total,
            usedDays: $pris,
            // ⚠️ Peut être NÉGATIF, et ce n'est pas un défaut : le dépassement est permis.
            // Le négatif dit « cet agent a pris d'avance sur son acquisition ».
            availableDays: $acquis
                - $pris
                - self::joursParStatut($driver, $contrat, 'ongoing')
                - self::joursParStatut($driver, $contrat, 'pending'),
            remainingDays: $total - $pris,
            accruedDays: $acquis,
        );
    }

    /**
     * Les jours d'un statut donné, sur CE contrat.
     *
     * Les jours EFFECTIFS priment sur les jours demandés : une pause écourtée n'a pas
     * consommé ce qui avait été demandé.
     */
    private static function joursParStatut(Driver $driver, DriverContract $contrat, string $statut): int
    {
        return (int) $driver->leaveRequests()
            ->where('status', $statut)
            ->where('driver_contract_id', $contrat->id)
            ->get()
            ->sum(fn ($pause) => $pause->effective_days ?? $pause->requested_days ?? 0);
    }

    /**
     * Les mois écoulés du contrat, mois en cours compris.
     *
     * ⚠️ Le `+ 1` crédite le mois COURANT : un agent acquiert ses deux jours à l'entrée
     * dans le mois, pas à sa sortie. C'est la règle du projet, reprise telle quelle.
     *
     * ⚠️ Un contrat TERMINÉ s'arrête à sa date de fin, pas à aujourd'hui. Sans cela, un
     * ancien agent continuerait d'acquérir des jours des années après son départ.
     */
    private static function moisEcoules(DriverContract $contrat, int $plafondMois): int
    {
        if (! $contrat->start_date) {
            return 0;
        }

        $debut = Carbon::parse($contrat->start_date)->startOfDay();
        $borne = $contrat->end_date
            ? Carbon::parse($contrat->end_date)->startOfDay()
            : now()->startOfDay();

        if ($borne->lt($debut)) {
            return 0;
        }

        // `diffInMonths` rend un flottant dans les versions récentes de Carbon : la
        // troncature est explicite, sans quoi PHP émet un avertissement de dépréciation.
        $revolus = (int) $debut->diffInMonths($borne);

        return min($revolus + 1, $plafondMois);
    }
}
