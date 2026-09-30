<?php

namespace App\Domains\Finance\Domain;

/**
 * Le compte de charges d'un contrat (spec 2026-09-30, §4.3) — classe pure.
 *
 * Remplace le report automatique du déficit (2026-09-29), qui ne laissait aucune latitude :
 * un mois qui ne couvre pas ses charges peut ne rien prélever, prélever en partie, ou
 * étaler. Deux règles ne se discutent pas : on ne prélève jamais plus que le reste à
 * recouvrer d'une ligne, et le solde dû n'est jamais négatif.
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
     * que le mois rapporte, servie dans l'ordre de la fiche.
     *
     * @param  array<string, float|int>  $outstanding
     * @return array<string, float>
     */
    public static function propose(array $outstanding, float $available): array
    {
        $left = max(0.0, $available);
        $proposal = [];

        foreach (array_keys(self::LINES) as $line) {
            $take = min((float) ($outstanding[$line] ?? 0), $left);
            $proposal[$line] = round($take, 2);
            $left -= $take;
        }

        return $proposal;
    }

    /**
     * @param  array<string, float|int>  $deducted
     * @param  array<string, float|int>  $outstanding
     * @return array<string, string>  champ => message
     */
    public static function violations(array $deducted, array $outstanding, float $available): array
    {
        $violations = [];

        foreach (self::LINES as $line => $label) {
            $amount = (float) ($deducted[$line] ?? 0);

            if ($amount < 0) {
                $violations["deducted_{$line}"] = "{$label} : un prélèvement ne peut pas être négatif.";
            } elseif ($amount > (float) ($outstanding[$line] ?? 0) + 0.005) {
                $violations["deducted_{$line}"] = "{$label} : on ne prélève pas plus que le reste à recouvrer.";
            }
        }

        if (array_sum(array_map('floatval', $deducted)) > $available + 0.005) {
            $violations['deducted'] = 'Les prélèvements dépassent ce que le mois rapporte : le solde dû serait négatif.';
        }

        return $violations;
    }
}
