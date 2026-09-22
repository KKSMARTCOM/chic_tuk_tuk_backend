<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Le motif d'un refus de pause.
 *
 * ⚠️ Cinq caractères au minimum, comme le formulaire Blade. Le motif voyage jusqu'à
 * l'agent — dans sa notification et sur son écran — et « non » n'explique rien.
 */
final class RejectLeaveData extends BaseData
{
    public function __construct(public string $rejectionReason) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['rejection_reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
