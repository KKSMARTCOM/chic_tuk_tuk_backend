<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\AdminRoleFormData;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Créer un rôle — ex-Admin\RoleController::store().
 *
 * Le nom technique est le slug du libellé, comme au Blade. ⚠️ Le Blade vérifiait
 * l'unicité d'un champ `name` que son formulaire n'envoyait jamais : un libellé dont le
 * slug existait déjà finissait en violation de contrainte, donc en 500.
 */
final class CreateRole
{
    public function __construct(private readonly RoleRules $rules) {}

    public function __invoke(User $actor, AdminRoleFormData $data): Role
    {
        $name = Str::slug($data->label);

        if ($name === '') {
            throw ValidationException::withMessages(['label' => 'Le nom du rôle doit contenir au moins une lettre ou un chiffre.']);
        }
        if (Role::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['label' => 'Un rôle porte déjà ce nom.']);
        }

        $this->rules->ensureWithinActor($actor, $data->permissions);

        return DB::transaction(function () use ($name, $data) {
            $role = Role::create([
                'name' => $name,
                'label' => $data->label,
                'description' => $data->description,
                'guard_name' => 'web',
            ]);
            $role->syncPermissions($data->permissions);

            return $role;
        });
    }
}
