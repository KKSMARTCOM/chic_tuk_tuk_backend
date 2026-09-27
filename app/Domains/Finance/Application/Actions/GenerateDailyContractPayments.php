<?php

namespace App\Domains\Finance\Application\Actions;

use App\Consts\VehicleContractConsts;
use App\Models\DriverContract;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Les paiements journaliers sur contrat — ex-`PaymentService::generateDailyContractPayments()`,
 * déplacé sans changement le 2026-09-27, avec sa génération par contrat.
 */
final class GenerateDailyContractPayments
{
    /**
     * Génère les paiements journaliers sur contrat pour tous les agents actifs.
     * Retourne un tableau de résultats pour le logging dans la commande.
     */
    public function __invoke(?Carbon $date = null): array
    {
        $today = $date ?? Carbon::today();
        $result = ['generated' => 0, 'skipped' => 0, 'errors' => []];

        $contracts = DriverContract::with(['driver', 'vehicleContract'])
            ->where('status', 'active')
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

        return $result;
    }

    /**
     * Génère le paiement journalier pour un contrat donné.
     * Retourne true si créé, false si ignoré (déjà existant ou données manquantes).
     */
    private function generateForContract(DriverContract $contract, ?Carbon $date = null): bool
    {
        $today = $date ?? Carbon::today();

        // Ne pas générer le week-end (samedi=6, dimanche=0)
        if ($today->isWeekend()) {
            return false;
        }

        // Déjà généré aujourd'hui pour ce contrat
        $alreadyExists = Payment::where('driver_contract_id', $contract->id)
            ->where('payment_type', 'contract')
            ->whereDate('payment_date', $today)
            ->exists();
        if ($alreadyExists) {
            return false;
        }

        $vehicleContract = $contract->vehicle->activeVehicleContract;

        if (! $vehicleContract) {
            return false;
        }

        // Montant journalier
        $contractMonths = (int) $vehicleContract->contract_months;

        $dailyAmount = VehicleContractConsts::AMOUNTS[$contractMonths] ?? 0;

        $taxe = VehicleContractConsts::TAXE[$contractMonths] ?? 0;

        $netAmount = $dailyAmount - $taxe;

        DB::transaction(function () use ($contract, $vehicleContract, $dailyAmount, $today, $netAmount) {
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
        });

        return true;
    }
}
