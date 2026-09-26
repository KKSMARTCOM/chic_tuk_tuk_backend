<?php

namespace App\Domains\Identity\Application\Actions;

use App\Models\User;

/**
 * Activer ou désactiver un administrateur — ex-Admin\UserController::toggleStatus().
 * Désactiver met fin à ses sessions ouvertes, et on ne se désactive pas soi-même.
 */
final class SetAdminUserStatus
{
    public function __construct(private readonly AdminAccountRules $rules) {}

    public function __invoke(User $actor, User $user, bool $isActive): void
    {
        $this->rules->ensureTargetWithinActor($actor, $user);

        if (! $isActive && $user->is($actor)) {
            throw SelfAction::deactivation();
        }

        $user->update(['is_active' => $isActive]);

        if (! $isActive) {
            $this->rules->endSessions($user, $actor);
        }
    }
}
