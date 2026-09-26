<?php

namespace App\Domains\Identity\Application\Actions;

use App\Models\User;

/**
 * Retrouve un compte administrateur, ou lève un 404.
 *
 * ⚠️ Le Blade liait `{user}` à N'IMPORTE QUEL compte : `/admin/users/{id}` modifiait ou
 * supprimait un agent ou un propriétaire, et `UserService::update()` lui imposait
 * `profil=admin` au passage.
 */
final class FindAdminUser
{
    public function __invoke(string $userId): User
    {
        return User::query()->where('profil', 'admin')->with('roles.permissions')->findOrFail($userId);
    }
}
