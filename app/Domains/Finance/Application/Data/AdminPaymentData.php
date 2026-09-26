<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Domain\Enums\PaymentStatus;
use App\Domains\Finance\Domain\Enums\PaymentType;
use App\Domains\Fleet\Application\Data\AdminVehicleContractPartyData;
use App\Models\Payment;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Un paiement — ligne de `pages.admin.payments.index`, et sa fiche.
 *
 * Les quatre booléens reprennent les règles du service (2026-09-26), pour que l'écran
 * n'offre que ce que l'API accepterait : modifier, valider et supprimer un paiement EN
 * ATTENTE ; annuler tout paiement qui ne l'est pas déjà.
 *
 * `driver.id` est l'identifiant de l'AGENT (`drivers.id`).
 */
final class AdminPaymentData extends BaseData
{
    public function __construct(
        public string $id,
        public ?AdminVehicleContractPartyData $driver,
        public ?string $driverAgentId,
        public float $amount,
        public ?float $netAmount,
        #[TypeScriptType(PaymentType::class)]
        public string $paymentType,
        #[LiteralTypeScriptType("'cash' | 'bank_transfer' | 'check' | 'mobile_money' | 'other'")]
        public string $paymentMethod,
        #[TypeScriptType(PaymentStatus::class)]
        public string $status,
        public ?string $paymentDate,
        public ?string $referenceNumber,
        public ?string $notes,
        public ?int $contractMonths,
        public ?string $driverContractId,
        public ?string $driverContractVehicleNumber,
        public ?string $vehicleContractId,
        public ?string $vehicleContractVehicleNumber,
        public bool $isEditable,
        public bool $canValidate,
        public bool $canCancel,
        public bool $isDeletable,
        public string $createdAt,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        $driver = $payment->driver;
        $pending = $payment->status === 'pending';

        return new self(
            id: $payment->id,
            driver: $driver?->user ? AdminVehicleContractPartyData::fromUser($driver->id, $driver->user) : null,
            driverAgentId: $driver?->agent_id,
            amount: (float) $payment->amount,
            netAmount: $payment->net_amount !== null ? (float) $payment->net_amount : null,
            paymentType: $payment->payment_type,
            paymentMethod: $payment->payment_method,
            status: $payment->status,
            paymentDate: $payment->payment_date?->toDateString(),
            referenceNumber: $payment->reference_number,
            notes: $payment->notes,
            contractMonths: $payment->vehicleContract?->contract_months,
            driverContractId: $payment->driver_contract_id,
            driverContractVehicleNumber: $payment->driverContract?->vehicle?->vehicle_number,
            vehicleContractId: $payment->vehicle_contract_id,
            vehicleContractVehicleNumber: $payment->vehicleContract?->vehicle?->vehicle_number,
            isEditable: $pending,
            canValidate: $pending,
            canCancel: $payment->status !== 'cancelled',
            isDeletable: $pending,
            createdAt: $payment->created_at->toIso8601String(),
        );
    }
}
