<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Changer le statut d'une course depuis l'administration — ex-Admin\BookingController::updateStatus().
 *
 * ## Deux défauts du chemin Blade, corrigés ici
 *
 * ⚠️ **Passer une course à « Terminée » ne créait AUCUNE commission.** Le contrôleur
 * Blade traverse `BookingService::update()` avec `_partial`, qui se contente de poser la
 * colonne : ni `driver_earning`, ni `commission`, ni ligne comptable, ni `completed_at`,
 * ni `total_trips`. Le « Terminer » de l'agent, lui, passe par `CompleteBooking` qui fait
 * les cinq. Une course terminée par l'administration ne rapportait donc rien à l'agent et
 * ne devait rien à l'entreprise — un trou silencieux, puisque la course s'affiche
 * « Terminée » exactement comme les autres.
 *
 * La clôture DÉLÈGUE donc à `CompleteBooking`. Conséquence assumée : elle exige un agent
 * et une course EN COURS, comme pour l'agent lui-même. On ne peut pas clôturer une course
 * que personne n'a conduite — ce qui s'affichait auparavant comme un succès produisait un
 * trajet à zéro franc.
 *
 * ⚠️ **Toucher au statut d'un abonnement annulait toutes ses courses à venir.** Dans
 * `BookingService::update()`, la branche `is_subscription_parent` annule les enfants
 * `pending` et `confirmed` à CHAQUE mise à jour partielle — y compris passer le parent à
 * « Confirmée », ou lui retirer son agent. Seule l'ANNULATION doit propager, et c'est ce
 * que fait cette action.
 */
final class ChangeBookingStatus
{
    /** Les statuts qu'un administrateur peut poser, repris de la validation du Blade. */
    public const ALLOWED = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];

    public function __construct(private readonly CompleteBooking $completeBooking) {}

    public function __invoke(Booking $booking, string $status, ?string $reason = null): Booking
    {
        if (! in_array($status, self::ALLOWED, true)) {
            throw new ApiException(422, 'BOOKING_STATUS_INVALID', 'Le statut sélectionné est invalide.');
        }

        if ($status === 'completed') {
            return $this->complete($booking);
        }

        if ($status === 'cancelled') {
            return $this->cancel($booking, $reason);
        }

        $booking->update(['status' => $status]);

        return $booking->refresh();
    }

    /**
     * Clôturer, par le MÊME chemin que l'agent.
     *
     * Les deux refus sont énoncés avant l'appel pour qu'ils portent un message qui parle
     * à un administrateur : `CompleteBooking` répond `BOOKING_NOT_COMPLETABLE` sans dire
     * ce qui manque, parce que côté agent une seule des deux causes est possible.
     */
    private function complete(Booking $booking): Booking
    {
        if (! $booking->driver_id) {
            throw new ApiException(
                409,
                'BOOKING_NO_DRIVER',
                'Cette course n\'a pas d\'agent : elle ne peut pas être terminée. Affectez-en un d\'abord.'
            );
        }

        if ($booking->status !== 'in_progress') {
            throw new ApiException(
                409,
                'BOOKING_NOT_IN_PROGRESS',
                'Seule une course en cours peut être terminée.'
            );
        }

        return ($this->completeBooking)($booking->id, $booking->driver_id);
    }

    /**
     * Annuler — et propager aux courses filles, mais SEULEMENT ici.
     */
    private function cancel(Booking $booking, ?string $reason): Booking
    {
        // Repris du Blade : une course déjà commencée ne s'annule pas, elle se termine.
        if ($booking->status === 'in_progress') {
            throw new ApiException(
                409,
                'BOOKING_NOT_CANCELLABLE',
                'Une course en cours ne peut pas être annulée.'
            );
        }

        return DB::transaction(function () use ($booking, $reason) {
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                // ⚠️ Le Blade écrit `$validated['cancellation_reason']` dans
                // `BookingService::update()`, où `$validated` n'existe pas : le motif
                // saisi était donc TOUJOURS remplacé par « Abonnement annulé ».
                'cancellation_reason' => $reason ?: $booking->cancellation_reason,
            ]);

            if ($booking->is_subscription_parent) {
                Booking::where('parent_booking_id', $booking->id)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'cancellation_reason' => $reason ?: 'Abonnement annulé',
                    ]);
            }

            return $booking->refresh();
        });
    }
}
