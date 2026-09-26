<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\UpdateAdminUserPasswordData;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Remplacer le mot de passe d'un administrateur — ex-Admin\UserController::updatePassword().
 *
 * Met fin à ses sessions ouvertes, comme la réinitialisation par e-mail : un mot de
 * passe qu'on change parce qu'il a fui ne doit pas laisser en place l'accès de celui
 * qui l'a trouvé. La session en cours est épargnée si l'on change le sien.
 */
final class UpdateAdminUserPassword
{
    public function __construct(private readonly AdminAccountRules $rules) {}

    public function __invoke(User $actor, User $user, UpdateAdminUserPasswordData $data): void
    {
        $this->rules->ensureTargetWithinActor($actor, $user);

        $user->update(['password' => Hash::make($data->password)]);
        $this->rules->endSessions($user, $actor);
    }
}
