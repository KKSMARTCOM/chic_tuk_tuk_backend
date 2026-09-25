<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\User;

/** Activer ou désactiver un compte propriétaire — ex-Admin\UserController::toggleStatus(). */
final class SetOwnerStatus
{
    public function __invoke(User $owner, bool $isActive): void
    {
        $owner->update(['is_active' => $isActive]);
    }
}
