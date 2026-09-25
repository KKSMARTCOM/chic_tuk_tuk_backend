<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Le statut d'un compte propriétaire, POSÉ et non basculé : le Blade inversait l'état
 * courant, si bien qu'un double clic ou une requête rejouée annulait l'action.
 */
final class SetOwnerStatusData extends BaseData
{
    public function __construct(
        public bool $isActive,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
