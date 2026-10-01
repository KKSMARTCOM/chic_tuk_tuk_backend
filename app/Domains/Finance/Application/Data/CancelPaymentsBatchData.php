<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/payments/cancel-batch — un motif obligatoire, sans notification par défaut. */
final class CancelPaymentsBatchData extends BaseData
{
    public function __construct(
        /** @var string[] */
        public array $paymentIds,
        public string $reason,
        public bool $notifyDrivers = false,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'payment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'payment_ids.*' => ['uuid'],
            'reason' => ['required', 'string', 'max:255'],
            'notify_drivers' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'payment_ids.required' => 'Sélectionnez au moins un paiement.',
            'reason.required' => 'Le motif de l\'annulation est obligatoire.',
        ];
    }
}
