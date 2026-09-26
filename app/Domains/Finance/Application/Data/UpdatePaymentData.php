<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * PUT /admin/payments/{id} — seulement un paiement en attente, et seulement ces champs :
 * le type, l'agent et le contrat ne se modifient plus (2026-09-26).
 */
final class UpdatePaymentData extends BaseData
{
    public function __construct(
        public float $amount,
        #[LiteralTypeScriptType("'cash' | 'bank_transfer' | 'check' | 'mobile_money' | 'other'")]
        public string $paymentMethod,
        public string $paymentDate,
        public ?string $notes = null,
        public ?string $referenceNumber = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'in:cash,bank_transfer,check,mobile_money,other'],
            'payment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reference_number' => [
                'nullable', 'string', 'max:100',
                Rule::unique('payments', 'reference_number')->ignore(request()->route('payment')),
            ],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'amount.required' => 'Le montant est obligatoire.',
            'amount.min' => 'Le montant doit être supérieur à 0.',
            'payment_method.required' => 'La méthode de paiement est obligatoire.',
            'payment_date.required' => 'La date de paiement est obligatoire.',
            'reference_number.unique' => 'Le numéro de référence existe déjà pour un autre paiement.',
        ];
    }

    /** @return array<string, mixed> les clés de `PaymentService::update()` */
    public function toServicePayload(): array
    {
        return [
            'amount' => $this->amount,
            'payment_method' => $this->paymentMethod,
            'payment_date' => $this->paymentDate,
            'notes' => $this->notes,
            'reference_number' => $this->referenceNumber,
        ];
    }
}
