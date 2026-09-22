<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;
use Illuminate\Support\Collection;

/**
 * Les agents et leur solde de congés — ex-Admin\LeaveController::index().
 *
 * ⚠️ TOUS les agents ayant eu un contrat sont listés, y compris les anciens — ceux qui
 * ne sont pas allés au bout du leur. Le contrôleur Blade ne montrait que les agents sous
 * contrat ACTIF, et leur dossier de congés devenait donc inconsultable dès leur départ.
 * Corrigé sur demande le 2026-09-22.
 *
 * Le solde de ces agents est rapporté à leur DERNIER contrat — voir
 * `Driver::contratDeReference()`. Les rapporter au contrat actif, qui n'existe plus,
 * n'afficherait qu'une ligne de zéros, ce qui ne dit rien de ce qu'ils ont pris.
 *
 * Un agent n'ayant JAMAIS eu de contrat reste écarté : il n'a pas de dossier de congés,
 * seulement une fiche d'agent.
 *
 * ⚠️ Deux des quatre filtres s'appliquent APRÈS la requête, en PHP. `available` et
 * `pending` portent sur des valeurs CALCULÉES — le solde disponible croise l'acquisition
 * mensuelle et trois statuts de pause — qu'aucune colonne ne contient. Les pousser en SQL
 * demanderait de réécrire le calcul en base, donc d'en tenir deux versions. Le contrôleur
 * Blade fait déjà ainsi ; on garde ce choix, et il tient tant que la flotte se compte en
 * dizaines.
 */
final class ListDriversForLeaves
{
    /**
     * @param  array{search?: ?string, contract?: ?int, available?: ?string, pending?: ?string, status?: ?string}  $filtres
     * @return Collection<int, Driver>
     */
    public function __invoke(array $filtres = []): Collection
    {
        $requete = Driver::query()
            ->with(['user', 'activeDriverContract'])
            ->whereHas('driverContracts');

        if (! empty($filtres['search'])) {
            $recherche = $filtres['search'];
            $requete->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$recherche}%"));
        }

        if (! empty($filtres['status'])) {
            // Le filtre ajouté avec les anciens agents : sans lui, la liste mélange ceux
            // qu'on gère au quotidien et ceux qu'on ne consulte qu'à l'occasion.
            $requete->when(
                $filtres['status'] === 'active',
                fn ($q) => $q->whereHas('activeDriverContract'),
                fn ($q) => $q->whereDoesntHave('activeDriverContract'),
            );
        }

        if (! empty($filtres['contract'])) {
            // ⚠️ `contract_type` sur `drivers`, et non `contract_months` sur le contrat :
            // c'est la colonne que filtre le contrôleur Blade. Les deux se ressemblent et
            // ne disent pas la même chose.
            $requete->where('contract_type', (int) $filtres['contract']);
        }

        $agents = $requete->get();

        if (! empty($filtres['available'])) {
            $veutDisponible = $filtres['available'] === 'yes';
            $agents = $agents->filter(
                fn (Driver $d) => $veutDisponible ? $d->available_leave_days > 0 : $d->available_leave_days <= 0
            );
        }

        if (! empty($filtres['pending'])) {
            $veutEnAttente = $filtres['pending'] === 'yes';
            $agents = $agents->filter(function (Driver $d) use ($veutEnAttente) {
                $enAttente = $d->leaveRequests()->where('status', 'pending')->count();

                return $veutEnAttente ? $enAttente > 0 : $enAttente === 0;
            });
        }

        return $agents->values();
    }
}
