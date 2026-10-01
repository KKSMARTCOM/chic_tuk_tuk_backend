<?php

namespace App\Console\Commands;

use App\Domains\Finance\Application\ContractPaymentAnomalies;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * L'état des paiements de contrat avant une reconstitution des fiches (spec 2026-10-01,
 * §9.1). LECTURE SEULE : elle liste, l'admin corrige avec les outils de l'application.
 */
class AuditContractPayments extends Command
{
    protected $signature = 'app:audit-contract-payments';

    protected $description = 'Liste les paiements de contrat à corriger avant de reconstituer les fiches (lecture seule)';

    public function handle(): int
    {
        $first = VehicleContract::query()->where('status', '!=', 'cancelled')->min('start_date');
        if ($first === null) {
            $this->info('Aucun contrat véhicule.');

            return self::SUCCESS;
        }
        $month = Carbon::parse($first)->startOfMonth();
        $this->info('Plus ancien contrat véhicule : '.$month->locale('fr')->translatedFormat('F Y')." (REMUNERATION_FIRST_MONTH={$month->format('Y-m')} ou plus tôt)");

        // Les règles vivent dans ContractPaymentAnomalies, que la liste des paiements filtre
        // aussi : l'audit et l'écran tombent toujours d'accord (2026-10-01).
        foreach (ContractPaymentAnomalies::KINDS as $kind => $title) {
            $this->section($title, ContractPaymentAnomalies::payments($kind), $kind);
        }

        return self::SUCCESS;
    }

    private function section(string $title, Collection $payments, string $kind): void
    {
        $this->line('');
        $this->info("{$title} : {$payments->count()}");
        if ($payments->isNotEmpty()) {
            $this->line("  À l'écran : /admin/payments?filter[anomaly]={$kind}");
        }
        foreach ($payments as $p) {
            $this->line(sprintf('  %s  %s  %s  %s  contrat véhicule %s', $p->id, $p->payment_date?->toDateString() ?? '—', $p->status, $p->amount, $p->vehicle_contract_id ?? '—'));
        }
    }
}
