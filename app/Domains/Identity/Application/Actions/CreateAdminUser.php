<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\AdminUserFormData;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Créer un administrateur — ex-Admin\UserController::store(). */
final class CreateAdminUser
{
    public function __construct(private readonly AdminAccountRules $rules) {}

    public function __invoke(User $actor, AdminUserFormData $data): User
    {
        $role = Role::findByName($data->role, 'web');
        $this->rules->ensureRoleWithinActor($actor, $role);

        return DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'phone' => $data->phone,
                'password' => Hash::make((string) $data->password),
                'profil' => 'admin',
                'adresse' => $data->adresse,
                'is_active' => $data->isActive,
            ]);
            $user->assignRole($role);

            return $user;
        });
    }
}
