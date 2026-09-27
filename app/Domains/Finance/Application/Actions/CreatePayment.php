<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Driver;
use App\Models\Payment;

/**
 * Enregistrer un paiement — ex-`PaymentService::create()`, déplacé sans changement le
 * 2026-09-27.
 */
final class CreatePayment
{
    public function __construct(private readonly CheckPaymentData $checkPaymentData) {}

    /**
     * Créer un paiement
     */
    public function __invoke(array $data)
    {
        $driver = Driver::with('activeDriverContract')->findOrFail($data['driver_id']);

        if ($driver->activeDriverContract) {
            $data['driver_contract_id'] = $driver->activeDriverContract->id;
            $data['vehicle_contract_id'] = $driver->activeDriverContract->vehicle_contract_id;
        }

        ($this->checkPaymentData)($data);

        $payment = Payment::create([
            'driver_id' => $data['driver_id'],
            'payment_type' => $data['payment_type'] ?? 'commission',
            'amount' => $data['amount'],
            'payment_month' => $data['payment_month'] ?? null,
            'payment_method' => $data['payment_method'],
            'payment_date' => $data['payment_date'],
            'notes' => $data['notes'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'status' => 'completed',
            'vehicle_contract_id' => $data['vehicle_contract_id'] ?? null,
            'driver_contract_id' => $data['driver_contract_id'] ?? null,
            'net_amount' => $data['net_amount'] ?? null,
        ]);

        return $payment;
    }
}
