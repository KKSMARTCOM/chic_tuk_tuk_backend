<?php

namespace App\Domains\Identity\Application\Actions;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

/**
 * Les rôles qu'un compte administrateur peut porter : tous, sauf ceux des trois autres
 * espaces. Même liste que `UserService::getAvailableRoles()` du Blade, qui ne servait
 * pourtant qu'à remplir la liste déroulante — la validation acceptait n'importe quel rôle.
 */
final class AssignableRoles
{
    /** Rôles propres aux espaces agent, propriétaire et client. */
    private const OTHER_SPACES = ['driver', 'proprietaire', 'client'];

    /** @return Collection<int, Role> */
    public function __invoke(): Collection
    {
        return Role::query()->whereNotIn('name', self::OTHER_SPACES)->orderBy('id')->get();
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this()->pluck('name')->all();
    }
}
