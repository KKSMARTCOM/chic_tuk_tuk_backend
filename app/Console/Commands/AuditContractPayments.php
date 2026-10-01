<?php

namespace App\Console\Commands;

use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Models\Payment;
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

        $live = Payment::query()->where('payment_type', 'contract')->where('status', '!=', 'cancelled');

        $this->section('Sans mois', (clone $live)->whereNull('payment_month')->get());
        $this->section('Sans contrat', (clone $live)
            ->where(fn ($q) => $q->whereNull('vehicle_contract_id')->orWhereNull('driver_contract_id'))->get());

        $stopped = collect();
        VehicleContract::query()->where('status', '!=', 'cancelled')->each(function (VehicleContract $contract) use ($stopped) {
            $calculator = ContractMonthCalculator::for($contract);
            $payments = $contract->payments()->where('payment_type', 'contract')->where('status', '!=', 'cancelled')->whereNotNull('payment_month')->get();
            foreach ($payments->groupBy(fn ($p) => $p->payment_month->format('Y-m')) as $key => $ofMonth) {
                $stoppedDates = $calculator->stoppedDates($key);
                $ofMonth->filter(fn ($p) => in_array($p->payment_date->toDateString(), $stoppedDates, true))->each(fn ($p) => $stopped->push($p));
            }
        });
        $this->section('Sur un jour d\'arrêt', $stopped);

        $this->section('Doublons du même jour', (clone $live)->whereNotNull('driver_contract_id')->get()
            ->groupBy(fn ($p) => $p->driver_contract_id.'|'.$p->payment_date->toDateString())
            ->filter(fn ($group) => $group->count() > 1)
            ->flatMap(fn ($group) => $group->slice(1)));

        $this->section('En attente d\'un mois passé', Payment::query()->where('payment_type', 'contract')->where('status', 'pending')
            ->where('payment_month', '<', now()->startOfMonth()->toDateString())->get());

        return self::SUCCESS;
    }

    private function section(string $title, Collection $payments): void
    {
        $this->line('');
        $this->info("{$title} : {$payments->count()}");
        foreach ($payments as $p) {
            $this->line(sprintf('  %s  %s  %s  %s  contrat véhicule %s', $p->id, $p->payment_date?->toDateString() ?? '—', $p->status, $p->amount, $p->vehicle_contract_id ?? '—'));
        }
    }
}
