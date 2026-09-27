<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Change le titulaire d'un abonnement déjà pris — ex. quand l'agent titulaire ne tient
 * plus ses engagements. Livré en production par le Blade le 2026-09-25, déplacé ici le
 * 2026-09-27 : `BookingService::transferSubscription()` DÉLÈGUE, les deux chemins
 * partagent donc la même règle. Les refus portent les messages d'origine mot pour mot.
 *
 * Passent au nouvel agent :
 *  - le parent (titulaire), et son J1 s'il est accepté mais pas encore démarré ;
 *  - les courses enfants à venir : en attente, ou déjà acceptées par l'ancien titulaire
 *    — elles restent acceptées, pour B, sans rien à refaire ;
 *  - les courses que l'ancien titulaire avait révoquées et que personne n'a reprises.
 *
 * Ne bougent pas : ce qui est en cours ou terminé — les revenus restent à qui a roulé —,
 * ni une course révoquée déjà reprise par un autre agent, qui s'y est engagé.
 * Les courses générées ensuite reprennent le titulaire du parent, donc le nouvel agent.
 */
final class TransferSubscription
{
    public function __construct(private readonly Notifier $notifier) {}

    public function __invoke(string $parentId, string $newDriverId): Booking
    {
        $parent = DB::transaction(function () use ($parentId, $newDriverId) {
            $parent = Booking::lockForUpdate()->findOrFail($parentId);

            if (! $parent->is_subscription_parent) {
                throw new ApiException(409, 'SUBSCRIPTION_NOT_PARENT', 'Seul un abonnement parent peut être transféré.');
            }
            if (in_array($parent->status, ['cancelled', 'expired'], true)) {
                throw new ApiException(409, 'SUBSCRIPTION_CLOSED', 'Cet abonnement est annulé ou expiré.');
            }
            if (! $parent->subscription_driver_id) {
                throw new ApiException(409, 'SUBSCRIPTION_WITHOUT_HOLDER', "Cet abonnement n'a pas encore de titulaire : affectez-le plutôt.");
            }
            if ($parent->subscription_driver_id === $newDriverId) {
                throw new ApiException(409, 'SUBSCRIPTION_SAME_HOLDER', 'Cet agent est déjà titulaire de cet abonnement.');
            }

            $newDriver = Driver::with('user')->findOrFail($newDriverId);
            if ($newDriver->user?->profil !== 'driver' || ! $newDriver->user?->is_active) {
                throw new ApiException(409, 'DRIVER_UNAVAILABLE', "Cet agent n'est pas disponible.");
            }

            $oldDriverId = $parent->subscription_driver_id;

            $parentUpdate = ['subscription_driver_id' => $newDriverId];
            if ($parent->status === 'confirmed' && $parent->driver_id === $oldDriverId) {
                $parentUpdate['driver_id'] = $newDriverId;
            }
            $parent->update($parentUpdate);

            $children = Booking::where('parent_booking_id', $parent->id);

            // Déjà acceptées par l'ancien titulaire, pas encore démarrées.
            (clone $children)->where('status', 'confirmed')->where('driver_id', $oldDriverId)
                ->update(['driver_id' => $newDriverId, 'subscription_driver_id' => $newDriverId]);

            // En attente — liées à l'ancien titulaire, ou révoquées par lui sans preneur.
            (clone $children)->where('status', 'pending')->whereNull('driver_id')
                ->where(fn ($q) => $q->where('subscription_driver_id', $oldDriverId)->orWhere('is_revoked', true))
                ->update(['subscription_driver_id' => $newDriverId, 'is_revoked' => false, 'revoked_at' => null]);

            Log::info("[transferSubscription] Abonnement {$parent->id} transféré de {$oldDriverId} à {$newDriverId}");

            return $parent;
        });

        // Après validation — et `Notifier` n'échoue jamais bruyamment : un échec d'envoi
        // ne doit pas annuler le transfert.
        $newAgent = Driver::with('user')->find($newDriverId)?->user;
        if ($newAgent) {
            $this->notifier->subscriptionTransferred($parent, $newAgent);
        }

        return $parent->refresh();
    }
}
