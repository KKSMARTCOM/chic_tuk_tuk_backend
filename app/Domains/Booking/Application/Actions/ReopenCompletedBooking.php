<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Annuler la CLÔTURE d'une course : elle repasse « En cours ».
 *
 * ## Pourquoi une action, et pas un statut de plus dans le menu
 *
 * Depuis le 2026-09-22, « Terminée » CLÔT une réservation : la matrice de transitions
 * n'en laisse rien sortir. La raison n'est pas qu'une clôture serait sacrée, mais qu'elle
 * a produit des effets — une commission, un gain d'agent, un trajet de plus à son
 * compteur — qu'un simple changement de statut ne défait pas. C'est exactement le défaut
 * que la matrice a fermé : le Blade laissait repasser une course terminée « En attente »
 * en gardant sa commission.
 *
 * Rouvrir DÉFAIT ces effets. C'est ce qui lui vaut une action à elle, un nom explicite et
 * une confirmation à l'écran, plutôt qu'une ligne dans un menu déroulant.
 *
 * ## Ce qui est défait
 *
 *  1. le statut revient à `in_progress` — l'état d'où la clôture était partie — et
 *     `completed_at` s'efface ;
 *  2. `commission` et `driver_earning` retombent à zéro. ⚠️ Pas à `null` : les deux
 *     colonnes sont NOT NULL en base, avec 0 pour défaut ;
 *  3. la ligne de COMMISSION est supprimée ;
 *  4. le compteur de trajets de l'agent est décrémenté.
 *
 * ⚠️ **Si la commission avait déjà été payée**, rien ne casse et rien n'est perdu : ce
 * que l'agent doit est un SOLDE — `Σ commissions − Σ paiements de type commission` — sans
 * lien ligne à ligne. Retirer la commission laisse donc le paiement en place, et le solde
 * bascule en faveur de l'agent, qui garde un crédit. C'est le reflet exact de la
 * situation : il a payé une commission sur une course qui n'est plus terminée.
 */
final class ReopenCompletedBooking
{
    public function __construct(private readonly Notifier $notifier) {}

    public function __invoke(Booking $booking): Booking
    {
        if ($booking->status !== 'completed') {
            throw new ApiException(
                409,
                'BOOKING_NOT_COMPLETED',
                'Seule une course terminée peut être rouverte.'
            );
        }

        $driverId = $booking->driver_id;

        DB::transaction(function () use ($booking, $driverId) {
            Commission::where('booking_id', $booking->id)->delete();

            if ($driverId) {
                // ⚠️ Plancher à zéro. Le compteur a pu être remis à plat par ailleurs, et
                // un décrément aveugle le rendrait négatif — un agent ayant conduit « -1
                // course » est un chiffre que personne ne saurait lire.
                Driver::where('id', $driverId)
                    ->where('total_trips', '>', 0)
                    ->decrement('total_trips');
            }

            $booking->update([
                'status' => 'in_progress',
                'completed_at' => null,
                'commission' => 0,
                'driver_earning' => 0,
            ]);
        });

        // ⚠️ APRÈS la transaction, comme toute notification : dedans, elle partirait pour
        // une opération qu'un `rollback` annulerait ensuite.
        $this->notifier->bookingReopened($booking->refresh());

        return $booking;
    }
}
