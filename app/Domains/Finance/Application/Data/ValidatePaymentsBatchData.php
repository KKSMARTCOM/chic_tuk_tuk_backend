<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/payments/validate-batch — sans notification par défaut. */
final class ValidatePaymentsBatchData extends BaseData
{
    public function __construct(
        /** @var string[] */
        public array $paymentIds,
        public string $collectedOn,
        public bool $notifyDrivers = false,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'payment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'payment_ids.*' => ['uuid'],
            'collected_on' => ['required', 'date_format:Y-m-d'],
            'notify_drivers' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'payment_ids.required' => 'Sélectionnez au moins un paiement.',
            'collected_on.required' => 'La date d\'encaissement est obligatoire.',
        ];
    }
}
