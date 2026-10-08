<?php

namespace App\Domains\Finance\Domain;

/**
 * Le compte de charges d'un contrat (spec 2026-09-30, §4.3) — classe pure.
 *
 * Remplace le report automatique du déficit (2026-09-29), qui ne laissait aucune latitude :
 * un mois qui ne couvre pas ses charges peut ne rien prélever, prélever en partie, ou
 * étaler. La répartition entre les lignes varie aussi d'un mois à l'autre (2026-10-08) :
 * le plafond porte sur le TOTAL des restes à recouvrer, pas sur chaque ligne. Règles : un
 * prélèvement n'est jamais négatif, le total prélevé ne dépasse pas le total à recouvrer,
 * et le solde dû n'est jamais négatif.
 */
final class ChargeLedger
{
    /** Les lignes, dans l'ordre de la fiche — c'est aussi l'ordre où la proposition les sert. */
    public const LINES = [
        'internet' => 'Connexion internet illimitée',
        'spotify' => 'Spotify Premium partagé',
        'manager' => 'Rémunération manager',
    ];

    /**
     * La valeur proposée : chaque ligne reçoit son reste à recouvrer, dans la limite de ce
     * que le mois rapporte et du total à recouvrer, servie dans l'ordre de la fiche.
     *
     * @param  array<string, float|int>  $outstanding
     * @return array<string, float>
     */
    public static function propose(array $outstanding, float $available): array
    {
        $left = max(0.0, min($available, self::total($outstanding)));
        $proposal = [];

        foreach (array_keys(self::LINES) as $line) {
            // Un reste négatif — prélevé au-delà du contrat — ne propose rien.
            $take = min(max(0.0, (float) ($outstanding[$line] ?? 0)), $left);
            $proposal[$line] = round($take, 2);
            $left -= $take;
        }

        return $proposal;
    }

    /**
     * @param  array<string, float|int>  $deducted
     * @param  array<string, float|int>  $outstanding
     * @return array<string, string> champ => message
     */
    public static function violations(array $deducted, array $outstanding, float $available): array
    {
        $violations = [];

        foreach (self::LINES as $line => $label) {
            if ((float) ($deducted[$line] ?? 0) < 0) {
                $violations["deducted_{$line}"] = "{$label} : un prélèvement ne peut pas être négatif.";
            }
        }

        $total = self::total($deducted);
        $ceiling = self::total($outstanding);

        if ($total > $ceiling + 0.005) {
            $violations['deducted'] = 'Les prélèvements dépassent le total des charges à recouvrer ('
                .number_format(max(0.0, $ceiling), 0, ',', ' ').' F).';
        } elseif ($total > $available + 0.005) {
            $violations['deducted'] = 'Les prélèvements dépassent ce que le mois rapporte : le solde dû serait négatif.';
        }

        return $violations;
    }

    /** @param  array<string, float|int>  $amounts */
    private static function total(array $amounts): float
    {
        return array_sum(array_map('floatval', $amounts));
    }
}
