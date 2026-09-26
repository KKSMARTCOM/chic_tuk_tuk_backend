<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * POST /admin/payments — le formulaire « Enregistrer un paiement », et les deux fenêtres
 * du dossier agent. Un paiement saisi à la main naît VALIDÉ, comme au Blade.
 */
final class CreatePaymentData extends BaseData
{
    public function __construct(
        public string $driverId,
        #[LiteralTypeScriptType("'commission' | 'contract' | 'subscription_revenue'")]
        public string $paymentType,
        public float $amount,
        #[LiteralTypeScriptType("'cash' | 'bank_transfer' | 'check' | 'mobile_money' | 'other'")]
        public string $paymentMethod,
        public string $paymentDate,
        public ?string $notes = null,
        public ?string $referenceNumber = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'driver_id' => ['required', 'uuid', 'exists:drivers,id'],
            'payment_type' => ['required', 'in:commission,contract,subscription_revenue'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'in:cash,bank_transfer,check,mobile_money,other'],
            'payment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reference_number' => ['nullable', 'string', 'max:100', 'unique:payments,reference_number'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'driver_id.required' => 'L\'agent est obligatoire.',
            'driver_id.exists' => 'L\'agent sélectionné est invalide.',
            'payment_type.required' => 'Le type de paiement est obligatoire.',
            'payment_type.in' => 'Le type de paiement sélectionné est invalide.',
            'amount.required' => 'Le montant est obligatoire.',
            'amount.min' => 'Le montant doit être supérieur à 0.',
            'payment_method.required' => 'La méthode de paiement est obligatoire.',
            'payment_date.required' => 'La date de paiement est obligatoire.',
            'reference_number.unique' => 'Le numéro de référence existe déjà pour un autre paiement.',
        ];
    }

    /** @return array<string, mixed> les clés de `PaymentService::create()` */
    public function toServicePayload(): array
    {
        return [
            'driver_id' => $this->driverId,
            'payment_type' => $this->paymentType,
            'amount' => $this->amount,
            'payment_method' => $this->paymentMethod,
            'payment_date' => $this->paymentDate,
            'notes' => $this->notes,
            'reference_number' => $this->referenceNumber,
        ];
    }
}
