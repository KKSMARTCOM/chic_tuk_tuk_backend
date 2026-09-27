<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Constate une course d'abonnement non traitée — ex-`BookingService::recordMissedChild()`,
 * déplacé sans changement le 2026-09-27.
 */
final class RecordMissedChild
{
    public function __construct(private readonly CatchUpSubscriptionDays $catchUpDays) {}

    /**
     * Une course enfant d'abonnement que personne n'a prise : elle passe « non traitée »
     * (`missed`), et le trajet est dû au client, rattrapé à la fin de l'abonnement.
     *
     * ⚠️ Le compteur est tenu PAR SENS : un aller-retour dont seul le retour a été manqué
     * ne rattrape que le retour — un jour entier donnerait au client un aller de trop.
     *
     * `expired_at` garde son rôle de date du constat, et empêche un second passage.
     */
    public function __invoke(string $childId): void
    {
        DB::transaction(function () use ($childId) {
            $child = Booking::lockForUpdate()->find($childId);
            if (! $child || ! in_array($child->status, ['pending', 'expired'])) {
                return;
            }

            $parent = Booking::lockForUpdate()->find($child->parent_booking_id);
            if (! $parent) {
                return;
            }

            $child->update(['status' => 'missed', 'expired_at' => $child->expired_at ?? now()]);
            $parent->increment($child->trip_type === 'return' ? 'makeup_return_count' : 'makeup_go_count');

            // Abonnement déjà au bout de ses jours normaux : la génération s'était arrêtée.
            // Elle reprend sur le prochain jour autorisé qui n'a pas encore sa course —
            // jamais aujourd'hui, pour que le rattrapage soit lui aussi généré la veille.
            if (! $parent->next_recurring_date && $parent->remaining_days <= 1) {
                $lastChildDate = Booking::where('parent_booking_id', $parent->id)->max('pickup_date');
                $base = Carbon::parse(max($lastChildDate ?? $parent->pickup_date, now()->toDateString()));
                $makeupDay = getNextAllowedDay($base, $parent->week_days ?? 'lun_dim');

                if ($makeupDay) {
                    $parent->update(['next_recurring_date' => $makeupDay->copy()->subDay()->setTime(1, 0)]);
                }
            }
        });

        $child = Booking::find($childId);
        if ($child?->parent_booking_id) {
            ($this->catchUpDays)($child->parent_booking_id);
        }
    }
}
