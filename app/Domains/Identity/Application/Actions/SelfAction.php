<?php

namespace App\Domains\Identity\Application\Actions;

use App\Shared\Http\ApiException;

/** Les refus d'agir sur son propre compte, partagés par plusieurs actions. */
final class SelfAction
{
    public static function deactivation(): ApiException
    {
        return new ApiException(409, 'USER_SELF_DEACTIVATION',
            'Vous ne pouvez pas désactiver votre propre compte.');
    }
}
