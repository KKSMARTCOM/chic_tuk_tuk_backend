<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\UpdateOwnerPasswordData;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** Réinitialiser le mot de passe d'un propriétaire — ex-Admin\UserController::updatePassword(). */
final class UpdateOwnerPassword
{
    public function __invoke(User $owner, UpdateOwnerPasswordData $data): void
    {
        $owner->update(['password' => Hash::make($data->password)]);
    }
}
