<?php

namespace App\Console\Commands;

use App\Domains\Finance\Application\Actions\GenerateDailyContractPayments;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateDailyContractPayment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-daily {--date= : Date au format Y-m-d (défaut: aujourd\'hui)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Génère les paiements journaliers sur contrat pour chaque agent actif';

    public function __construct(private GenerateDailyContractPayments $generatePayments)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        // Un jour à venir n'est pas dû : le planificateur ne le classe jamais (2026-10-01).
        if ($date->copy()->startOfDay()->gt(Carbon::today())) {
            $this->error("[{$date->toDateString()}] date future — un paiement ne se génère que pour un jour arrivé.");

            return self::FAILURE;
        }

        if ($date->isWeekend()) {
            $this->warn("[{$date->toDateString()}] Jour de week-end — aucun paiement généré.");

            return self::SUCCESS;
        }

        $this->info("[{$date->toDateString()}] Génération des paiements journaliers...");

        $result = ($this->generatePayments)($date);

        foreach ($result['errors'] as $error) {
            $this->error("  ✗ Contrat #{$error['contract_id']} — {$error['message']}");
        }

        $this->info("Terminé — {$result['generated']} généré(s), {$result['skipped']} ignoré(s), ".count($result['errors']).' erreur(s).');

        return count($result['errors']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
