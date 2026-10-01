<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Payment;
use App\Models\RemunerationStatement;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Les garde-fous de la date d'encaissement (spec 2026-10-01, §4.4).
 *
 * Une date antérieure ou égale à la date d'établissement d'une fiche VALIDÉE qui aurait
 * dû compter ce paiement — celle de son mois ou d'un mois suivant — est refusée : le
 * paiement se glisserait sinon en silence dans une fiche ultérieure.
 */
final class CheckCollectionDate
{
    public function __invoke(Payment $payment, Carbon $collectedOn): void
    {
        if ($collectedOn->copy()->startOfDay()->gt(Carbon::today())) {
            throw ValidationException::withMessages(['collected_on' => 'La date d\'encaissement ne peut pas être dans le futur.']);
        }
        if ($payment->vehicle_contract_id === null || $payment->payment_month === null) {
            return;
        }

        $blocking = RemunerationStatement::query()
            ->where('vehicle_contract_id', $payment->vehicle_contract_id)
            ->where('status', 'validated')
            ->whereDate('month', '>=', $payment->payment_month)
            ->whereNotNull('issued_on')
            ->whereDate('issued_on', '>=', $collectedOn)
            ->orderBy('month')
            ->first();

        if ($blocking !== null) {
            $month = $blocking->month->locale('fr')->translatedFormat('F Y');
            $article = preg_match('/^[aeiouyh]/i', $month) ? 'd\'' : 'de ';
            throw ValidationException::withMessages(['collected_on' => "La fiche {$article}{$month} est déjà arrêtée au {$blocking->issued_on->format('d/m/Y')} : cet encaissement aurait dû y figurer. Annulez-la d'abord, ou choisissez une date postérieure."]);
        }
    }
}
