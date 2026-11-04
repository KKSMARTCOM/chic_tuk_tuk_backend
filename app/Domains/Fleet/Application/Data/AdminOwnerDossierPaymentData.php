<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Domain\Enums\PaymentStatus;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Un paiement d'agent récent qui revient au propriétaire (`Payment::forOwner`). */
final class AdminOwnerDossierPaymentData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $paymentDate,
        public ?string $vehicleNumber,
        /** `id` est celui de l'AGENT. */
        public ?AdminVehicleContractPartyData $driver,
        public float $amount,
        #[TypeScriptType(PaymentStatus::class)]
        public string $status,
    ) {}
}
