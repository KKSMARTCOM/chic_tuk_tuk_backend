<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** DELETE /admin/payments/cancelled — sans liste, tous les paiements de contrat annulés. */
final class PurgeCancelledPaymentsData extends BaseData
{
    public function __construct(
        /** @var string[]|null */
        public ?array $paymentIds = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'payment_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'payment_ids.*' => ['uuid'],
        ];
    }
}
