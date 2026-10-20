<?php

namespace App\Console\Commands;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Booking\Domain\BookingLifecycle;
use App\Models\Booking;
use App\Models\SubscriptionEnrollment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Efface la pièce d'identité d'une inscription 3 mois après la fin de son abonnement
 * (spec 2026-10-14, §6). La ligne et les réponses restent.
 *
 * Terminé = ni le parent ni ses courses ne sont à venir ou en cours, et il ne reste ni jour
 * à générer ni trajet à rattraper. Fin = la plus tardive de la fin prévue, de la dernière
 * course et de l'annulation du parent. Résilié ou annulé (décidé le 2026-10-15), la fin
 * prévue ne compte plus : fin = la plus tardive de la résiliation et de la dernière course
 * non annulée.
 */
class PurgeEnrollmentIdDocuments extends Command
{
    public const RETENTION_MONTHS = 3;

    protected $signature = 'app:purge-enrollment-id-documents';

    protected $description = 'Efface les pièces d\'identité des abonnements terminés depuis plus de 3 mois';

    public function handle(ActivityJournal $journal): int
    {
        $limit = now()->subMonths(self::RETENTION_MONTHS)->startOfDay();
        $count = 0;

        // Par identifiant et non par page : la boucle vide la colonne qu'elle filtre, et un
        // découpage par décalage sautait des lignes au-delà de 1000 (relecture du 2026-10-14).
        SubscriptionEnrollment::query()
            ->with('booking')
            ->whereNotNull('id_document_path')
            ->lazyById()
            ->each(function (SubscriptionEnrollment $enrollment) use ($limit, &$count) {
                $end = $this->endOf($enrollment->booking);
                if ($end === null || $end->gte($limit)) {
                    return;
                }
                // Un fichier resté sur le disque n'est pas dit effacé : la nuit suivante réessaie.
                if (! Storage::disk('local')->delete($enrollment->id_document_path)) {
                    return;
                }
                $enrollment->update(['id_document_path' => null, 'id_document_purged_at' => now()]);
                $count++;
            });

        if ($count > 0) {
            $journal->enrollmentIdDocumentsPurged($count);
        }
        $this->info("{$count} pièce(s) d'identité effacée(s).");

        return self::SUCCESS;
    }

    /** La fin de l'abonnement, ou `null` s'il n'est pas terminé. */
    private function endOf(?Booking $parent): ?Carbon
    {
        if (! $parent) {
            return null;
        }

        $open = ['pending', 'confirmed', 'in_progress'];
        $stillRunning = in_array($parent->status, $open, true)
            || BookingLifecycle::isRunningSubscription($parent)
            || Booking::query()->where('parent_booking_id', $parent->id)->whereIn('status', $open)->exists();
        if ($stillRunning) {
            return null;
        }

        $stoppedAt = $parent->subscription_terminated_at ?? ($parent->status === 'cancelled' ? $parent->cancelled_at : null);
        if ($stoppedAt) {
            $lastRide = Booking::query()->where('parent_booking_id', $parent->id)->where('status', '!=', 'cancelled')->max('pickup_date');

            return collect([$stoppedAt, $lastRide, $parent->status === 'cancelled' ? null : $parent->pickup_date])
                ->filter()
                ->map(fn ($date) => Carbon::parse($date))
                ->max();
        }

        $lastRide = Booking::query()->where('parent_booking_id', $parent->id)->max('pickup_date');

        return collect([$parent->subscription_end_date, $lastRide, $parent->pickup_date, $parent->cancelled_at])
            ->filter()
            ->map(fn ($date) => Carbon::parse($date))
            ->max();
    }
}
