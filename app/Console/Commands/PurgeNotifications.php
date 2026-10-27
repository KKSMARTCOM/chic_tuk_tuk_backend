<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;

/**
 * La purge de nuit des notifications (spec 2026-10-27-liste-des-notifications, §4) :
 * les notifications lues de plus de 90 jours, et les « course disponible » d'une course
 * terminée ou supprimée, qui ne reviendront plus. Une notification non lue d'une autre
 * sorte n'est jamais touchée : personne ne perd ce qu'il n'a pas vu.
 */
class PurgeNotifications extends Command
{
    /** La table n'a pas de `read_at` : l'âge se mesure à la création. */
    private const READ_RETENTION_DAYS = 90;

    protected $signature = 'app:purge-notifications';

    protected $description = 'Efface les notifications lues de plus de 90 jours et celles des courses terminées';

    public function handle(): int
    {
        $deleted = Notification::query()
            ->where(fn ($q) => $q
                ->where(fn ($old) => $old->where('is_read', true)->where('created_at', '<', now()->subDays(self::READ_RETENTION_DAYS)))
                ->orWhere(fn ($stale) => Notification::whereStaleBooking($stale->getQuery(), terminalOnly: true)))
            ->delete();

        $this->info("{$deleted} notification(s) supprimée(s).");

        return self::SUCCESS;
    }
}
