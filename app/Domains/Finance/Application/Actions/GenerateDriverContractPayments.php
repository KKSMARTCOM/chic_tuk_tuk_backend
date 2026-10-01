<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Finance\Application\Data\ContractPaymentGenerationData;
use App\Models\DriverContract;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Créer les paiements EN ATTENTE d'une période (spec 2026-10-01, §3.3).
 *
 * Une transaction, le contrat agent verrouillé : deux générations simultanées ne créent
 * jamais deux fois le même jour. Le classement est refait DANS la transaction ; un jour
 * payé depuis l'aperçu est sauté sans erreur et rendu dans `skipped`.
 */
final class GenerateDriverContractPayments
{
    public function __construct(
        private readonly PlanDriverContractPayments $plan,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  list<string>  $regenerateCancelled  dates `Y-m-d` cochées dans l'aperçu
     * @param  list<string>  $expected  les dates à générer vues dans l'aperçu ; vide = aucune comparaison
     */
    public function __invoke(DriverContract $contract, Carbon $from, Carbon $to, array $regenerateCancelled, string $note, array $expected = []): ContractPaymentGenerationData
    {
        [$created, $total, $skipped] = DB::transaction(function () use ($contract, $from, $to, $regenerateCancelled, $note, $expected) {
            $contract = DriverContract::query()->lockForUpdate()->with('vehicleContract')->findOrFail($contract->id);
            $plan = ($this->plan)($contract, $from, $to);

            $unknown = array_diff($regenerateCancelled, $plan->cancelled());
            if ($unknown !== []) {
                throw ValidationException::withMessages(['regenerate_cancelled' => 'Seuls les jours au paiement annulé se régénèrent : '.implode(', ', $unknown).'.']);
            }

            $dates = array_values(array_unique([...$plan->toGenerate(), ...$regenerateCancelled]));
            sort($dates);
            $vehicleContract = $contract->vehicleContract;
            $amount = (float) $vehicleContract->daily_amount;
            $net = $amount - (float) ($vehicleContract->daily_tax ?? 0);

            foreach ($dates as $date) {
                Payment::create([
                    'driver_id' => $contract->driver_id,
                    'payment_type' => 'contract',
                    'vehicle_contract_id' => $vehicleContract->id,
                    'driver_contract_id' => $contract->id,
                    'payment_month' => Carbon::parse($date)->startOfMonth()->toDateString(),
                    'payment_method' => 'other',
                    'net_amount' => $net,
                    'amount' => $amount,
                    'payment_date' => $date,
                    'status' => 'pending',
                    'notes' => $note,
                ]);
            }

            return [count($dates), count($dates) * $net, array_values(array_diff($expected, $dates))];
        });

        if ($created > 0) {
            $this->journal->contractPaymentsGenerated($contract->loadMissing('driver.user'), $from->toDateString(), $to->toDateString(), $created, $total);
        }

        return new ContractPaymentGenerationData($created, $total, $skipped);
    }
}
