<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\ChangePasswordData;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class ChangePassword
{
    public function __construct(private readonly RevokeOtherDeviceSessions $revokeOtherSessions) {}

    public function __invoke(User $user, ChangePasswordData $data): void
    {
        if (! Hash::check($data->currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $user->forceFill(['password' => Hash::make($data->password)])->saveQuietly();

        // Un mot de passe changé doit couper les autres appareils : ils ont pu être la
        // raison du changement.
        ($this->revokeOtherSessions)($user);
    }
}
