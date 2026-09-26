<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\AdminRoleFormData;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Modifier un rôle créé à l'écran — ex-Admin\RoleController::update().
 *
 * ⚠️ Le nom technique ne bouge plus. Le Blade le recalculait depuis le libellé : renommer
 * « Administrateur » en changeait le nom, et `hasRole('admin')` cessait de le trouver.
 *
 * Retirer une permission que l'on ne possède pas revient aussi à décider pour un rôle
 * plus puissant que soi : les permissions actuelles du rôle sont donc contrôlées avec
 * les nouvelles.
 */
final class UpdateRole
{
    public function __construct(private readonly RoleRules $rules) {}

    public function __invoke(User $actor, Role $role, AdminRoleFormData $data): Role
    {
        $this->rules->ensureEditable($role);
        $this->rules->ensureWithinActor($actor, array_merge($role->permissions->pluck('name')->all(), $data->permissions));

        DB::transaction(function () use ($role, $data) {
            $role->update(['label' => $data->label, 'description' => $data->description]);
            $role->syncPermissions($data->permissions);
        });

        return $role->refresh()->load('permissions');
    }
}
