<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Efface les lignes expirées du cache `database` (relecture finale de la recherche de
 * lieux, 2026-10-27) : le cache ne les supprime que si on les relit, et chaque saisie
 * de la recherche de lieux en crée une, gardée 6 heures.
 */
class PurgeExpiredCache extends Command
{
    protected $signature = 'app:purge-expired-cache';

    protected $description = 'Efface les lignes expirées de la table du cache';

    public function handle(): int
    {
        $table = config('cache.stores.database.table', 'cache');
        if (! Schema::hasTable($table)) {
            return self::SUCCESS;
        }

        $deleted = DB::table($table)->where('expiration', '<', now()->timestamp)->delete();
        $this->info("{$deleted} ligne(s) de cache expirée(s) effacée(s).");

        return self::SUCCESS;
    }
}
