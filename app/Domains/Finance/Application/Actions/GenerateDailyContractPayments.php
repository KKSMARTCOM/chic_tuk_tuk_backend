<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Audit\Application\ActivityJournal;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les paiements journaliers sur contrat — ex-`PaymentService::generateDailyContractPayments()`,
 * déplacé sans changement le 2026-09-27, avec sa génération par contrat.
 */
final class GenerateDailyContractPayments
{
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly PlanDriverContractPayments $plan,
    ) {}

    /**
     * Génère les paiements journaliers sur contrat pour tous les agents actifs.
     * Retourne un tableau de résultats pour le logging dans la commande.
     */
    public function __invoke(?Carbon $date = null): array
    {
        $today = $date ?? Carbon::today();
        $result = ['generated' => 0, 'skipped' => 0, 'errors' => []];

        // Un contrat terminé ce jour-là, ou dont la fin est à venir, doit encore ce jour :
        // le dernier jour est dû quand l'agent n'était pas en pause (règle du 2026-10-06).
        // Le classement des jours écarte ensuite ce qui sort du contrat.
        $contracts = DriverContract::with(['driver', 'vehicleContract'])
            ->where(fn ($query) => $query->where('status', 'active')
                ->orWhere(fn ($ended) => $ended->where('status', 'ended')->whereDate('end_date', '>=', $today)))
            ->whereHas('vehicleContract', fn ($q) => $q->where('status', 'active'))
            ->get();

        foreach ($contracts as $contract) {
            try {
                $this->generateForContract($contract, $today)
                    ? $result['generated']++
                    : $result['skipped']++;
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'contract_id' => $contract->id,
                    'driver_id' => $contract->driver_id,
                    'message' => $e->getMessage(),
                ];
                Log::error('Erreur génération paiement journalier', [
                    'contract_id' => $contract->id,
                    'driver_id' => $contract->driver_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Une ligne par passage, seulement s'il a produit quelque chose : un week-end
        // ou un soir sans contrat n'a rien à raconter.
        if ($result['generated'] > 0) {
            $this->journal->dailyPaymentsGenerated($result['generated']);
        }

        return $result;
    }

    /**
     * Génère le paiement journalier pour un contrat donné.
     * Retourne true si créé, false si ignoré.
     *
     * Le jour est classé par `ContractPaymentPlanner`, comme pour la génération sur une
     * période (2026-10-01) : week-end, pause d'agent, immobilisation, déjà payé — une seule
     * règle. Le contrat véhicule est celui du contrat agent, plus le contrat actif du
     * véhicule.
     */
    private function generateForContract(DriverContract $contract, Carbon $today): bool
    {
        if ($today->isWeekend()) {
            return false;
        }

        $vehicleContract = $contract->vehicleContract;
        // Montants journaliers FIGÉS sur le contrat à sa création (2026-09-29). Un contrat
        // sans versement journalier — une durée hors des réglages, du temps où elle était
        // libre — générait chaque soir un paiement de 0 FCFA : il est écarté et signalé.
        if ($vehicleContract === null || $vehicleContract->daily_amount === null) {
            Log::warning('Contrat véhicule sans versement journalier : aucun paiement généré', [
                'vehicle_contract_id' => $vehicleContract?->id,
                'contract_months' => $vehicleContract?->contract_months,
            ]);

            return false;
        }

        $dailyAmount = (float) $vehicleContract->daily_amount;
        $netAmount = $dailyAmount - (float) ($vehicleContract->daily_tax ?? 0);

        // Le contrat agent verrouillé, le jour reclassé APRÈS le verrou : comme la
        // génération sur une période (revue du 2026-10-01), sans quoi les deux pouvaient
        // créer le paiement du même jour.
        return DB::transaction(function () use ($contract, $vehicleContract, $today, $dailyAmount, $netAmount) {
            $contract = DriverContract::query()->lockForUpdate()->with('vehicleContract')->findOrFail($contract->id);
            // Le contrat VÉHICULE aussi : deux agents d'un même véhicule ne génèrent jamais le
            // même jour en parallèle (2026-10-01).
            VehicleContract::query()->lockForUpdate()->find($contract->vehicle_contract_id);

            try {
                $plan = ($this->plan)($contract, $today, $today);
            } catch (ValidationException) {
                return false; // le jour est hors du contrat
            }

            if ($plan->toGenerate() !== [$today->toDateString()]) {
                return false;
            }

            Payment::create([
                'driver_id' => $contract->driver_id,
                'payment_type' => 'contract',
                'vehicle_contract_id' => $vehicleContract->id,
                'driver_contract_id' => $contract->id,
                'payment_month' => $today->copy()->startOfMonth()->toDateString(),
                'payment_method' => 'other',
                'net_amount' => $netAmount,
                'amount' => $dailyAmount,
                'payment_date' => $today->toDateString(),
                'status' => 'pending',
                'notes' => "Paiement journalier auto — {$today->format('d/m/Y')}",
            ]);

            return true;
        });
    }
}

