<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Services\VehicleService;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Supprimer une pause posée par erreur — historique ou EN COURS.
 *
 * Remplace `DeleteHistoricalLeave`, qui ne savait effacer que les pauses passées. Une
 * pause en cours posée par erreur n'avait alors aucune issue : on ne pouvait que la
 * « clôturer », ce qui enregistre des jours effectifs et les consomme dans le solde de
 * l'agent — on gardait donc la trace d'une absence qui n'a pas eu lieu. Demandé le
 * 2026-09-22.
 *
 * ## Ce que la suppression doit défaire
 *
 * Une pause en cours a laissé trois marques, et les oublier laisse le parc incohérent :
 *
 *  1. une pause VÉHICULE, qui immobilise le tricycle. Elle est ANNULÉE, pas clôturée :
 *     clôturer laisserait dans l'historique du propriétaire une immobilisation fictive ;
 *  2. l'agent rendu indisponible, qu'il faut relibérer ;
 *  3. le compteur indicatif `leave_days_used`, pour les pauses terminées.
 *
 * ## Ce qu'elle refuse
 *
 * ⚠️ Une pause TERMINÉE n'est supprimable que si elle est d'origine ADMINISTRATIVE
 * (`admin_historical`, `legacy`). Une pause terminée issue d'une demande d'agent a été
 * vécue : l'effacer supprimerait un fait, pas une faute de frappe.
 *
 * ⚠️ Une pause en cours, elle, se supprime quelle que soit son origine — rien n'a encore
 * été consommé. Pour une pause née d'une demande d'agent, cela efface AUSSI la demande :
 * l'agent devra la redéposer. C'est le prix de l'effacement, et la raison pour laquelle
 * une pause déjà vécue, elle, ne s'efface pas.
 */
final class DeleteLeave
{
    /** Les origines qu'un administrateur a saisies lui-même, et peut donc défaire. */
    private const SOURCES_ADMINISTRATIVES = ['admin_historical', 'legacy'];

    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(LeaveRequest $pause): void
    {
        $estHistoriqueAdministrative = $pause->status === 'completed'
            && in_array($pause->source, self::SOURCES_ADMINISTRATIVES, true);

        if (! $estHistoriqueAdministrative && $pause->status !== 'ongoing') {
            throw new ApiException(
                409,
                'LEAVE_NOT_DELETABLE',
                'Seules les pauses en cours et les pauses historiques saisies par un administrateur peuvent être supprimées.'
            );
        }

        DB::transaction(function () use ($pause) {
            $agent = $pause->driver;

            if ($pause->status === 'completed') {
                // Compteur indicatif : retirer ce que la saisie avait ajouté.
                $agent?->markLeaveDaysUsed(-($pause->effective_days ?? 0));
            }

            if ($pause->vehiclePause) {
                $this->vehicleService->cancelPause($pause->vehiclePause);
            }

            $pause->delete();

            // ⚠️ APRÈS la suppression : sinon la pause qu'on efface se compte elle-même
            // parmi celles qui retiennent l'agent, et il resterait indisponible.
            if ($agent) {
                $agent->update(['is_available' => ! $this->unePauseACommence($agent)]);
            }
        });
    }

    /**
     * Une pause de cet agent a-t-elle RÉELLEMENT commencé ?
     *
     * Le statut ne suffit pas : une pause `ongoing` dont le début est à venir ne bloque
     * personne. C'est la date qui décide.
     */
    private function unePauseACommence(Driver $agent): bool
    {
        return $agent->leaveRequests()
            ->where('status', 'ongoing')
            ->whereDate('start_date', '<=', now()->startOfDay())
            ->exists();
    }
}
