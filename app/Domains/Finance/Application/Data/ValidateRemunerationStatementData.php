<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/**
 * POST /admin/remuneration-statements/{id}/validate — la date d'établissement (aujourd'hui
 * par défaut) et l'envoi : `send: false` pour une fiche reconstituée, que le propriétaire
 * a déjà reçue sur papier (spec 2026-10-01, §5).
 */
final class ValidateRemunerationStatementData extends BaseData
{
    public function __construct(
        public ?string $issuedOn = null,
        public bool $send = true,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'send' => ['sometimes', 'boolean'],
        ];
    }
}
