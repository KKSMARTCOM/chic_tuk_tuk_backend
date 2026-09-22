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
 * ⚠️ Trois des cinq filtres s'appliquent APRÈS la requête, en PHP. `available`, `pending`
 * et `contract` portent sur des valeurs CALCULÉES — le solde disponible croise
 * l'acquisition mensuelle et trois statuts de pause, et la durée du contrat suit le
 * contrat de RÉFÉRENCE — qu'aucune colonne ne contient. Les pousser en SQL demanderait de
 * réécrire ces calculs en base, donc d'en tenir deux versions. Le contrôleur Blade fait
 * déjà ainsi pour les deux premiers ; on garde ce choix, et il tient tant que la flotte se
 * compte en dizaines.
 */
final class ListDriversForLeaves
{
    /**
     * @param  array{search?: ?string, contract?: ?int, available?: ?string, pending?: ?string, status?: ?string}  $filters
     * @return Collection<int, Driver>
     */
    public function __invoke(array $filters = []): Collection
    {
        $query = Driver::query()
            ->with(['user', 'activeDriverContract'])
            ->whereHas('driverContracts');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        if (! empty($filters['status'])) {
            // Le filtre ajouté avec les anciens agents : sans lui, la liste mélange ceux
            // qu'on gère au quotidien et ceux qu'on ne consulte qu'à l'occasion.
            $query->when(
                $filters['status'] === 'active',
                fn ($q) => $q->whereHas('activeDriverContract'),
                fn ($q) => $q->whereDoesntHave('activeDriverContract'),
            );
        }

        $drivers = $query->get();

        if (! empty($filters['contract'])) {
            // ⚠️ CORRIGÉ le 2026-09-22. Le contrôleur Blade filtrait sur
            // `drivers.contract_type`, une colonne héritée que plus rien n'écrit — elle a
            // quitté `$fillable`, aucun service ni aucune graine ne la renseigne, et elle
            // vaut NULL sur toute la flotte. Le menu déroulant, lui, était bâti sur la
            // valeur AFFICHÉE dans la colonne « Durée contrat », qui vient du CONTRAT.
            // Choisir « 24 mois » ne pouvait donc rendre qu'une liste vide.
            //
            // On filtre désormais sur ce que la colonne montre : les mois du contrat de
            // référence. En PHP comme `available` et `pending`, et pour la même raison —
            // le contrat de référence est l'actif s'il y en a un, sinon le dernier, ce
            // qu'une clause SQL simple ne sait pas dire.
            $months = (int) $filters['contract'];
            $drivers = $drivers->filter(
                fn (Driver $d) => (int) ($d->contratDeReference()?->contract_months ?? 0) === $months
            );
        }

        if (! empty($filters['available'])) {
            $wantsAvailable = $filters['available'] === 'yes';
            $drivers = $drivers->filter(
                fn (Driver $d) => $wantsAvailable ? $d->available_leave_days > 0 : $d->available_leave_days <= 0
            );
        }

        if (! empty($filters['pending'])) {
            $wantsPending = $filters['pending'] === 'yes';
            $drivers = $drivers->filter(function (Driver $d) use ($wantsPending) {
                $pendingCount = $d->leaveRequests()->where('status', 'pending')->count();

                return $wantsPending ? $pendingCount > 0 : $pendingCount === 0;
            });
        }

        return $drivers->values();
    }
}
