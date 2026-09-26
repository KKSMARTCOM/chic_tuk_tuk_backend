<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\AdminUserFormData;
use App\Models\Role;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Modifier un administrateur — ex-Admin\UserController::update().
 *
 * ⚠️ Sur son propre compte, ni désactivation ni changement de rôle : le Blade les
 * laissait passer, et l'administrateur s'enfermait dehors ou perdait ses droits d'un
 * clic. Le reste — nom, contact, adresse — se modifie librement.
 */
final class UpdateAdminUser
{
    public function __construct(private readonly AdminAccountRules $rules) {}

    public function __invoke(User $actor, User $user, AdminUserFormData $data): User
    {
        $role = Role::findByName($data->role, 'web');
        $this->rules->ensureTargetWithinActor($actor, $user);
        $this->rules->ensureRoleWithinActor($actor, $role);

        if ($user->is($actor)) {
            if (! $data->isActive) {
                throw SelfAction::deactivation();
            }
            if ($user->getRoleNames()->all() !== [$role->name]) {
                throw new ApiException(409, 'USER_SELF_ROLE_CHANGE',
                    'Vous ne pouvez pas changer votre propre rôle. Demandez-le à un autre administrateur.');
            }
        }

        $wasActive = (bool) $user->is_active;

        DB::transaction(function () use ($user, $data, $role) {
            $user->update([
                'name' => $data->name,
                'email' => $data->email,
                'phone' => $data->phone,
                'adresse' => $data->adresse,
                'is_active' => $data->isActive,
            ]);
            $user->syncRoles([$role]);
        });

        if ($wasActive && ! $data->isActive) {
            $this->rules->endSessions($user, $actor);
        }

        return $user->refresh();
    }
}
