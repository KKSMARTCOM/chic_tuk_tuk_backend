<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\ResetPasswordData;
use App\Domains\Identity\Domain\PasswordReset\UserKeyedTokenRepository;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class ResetPassword
{
    public function __construct(
        private readonly UserKeyedTokenRepository $tokens,
    ) {}

    /** Rend le compte réinitialisé, que le journal d'activité nomme. */
    public function __invoke(ResetPasswordData $data): User
    {
        $user = $this->tokens->resolve($data->token);

        if ($user === null) {
            throw ValidationException::withMessages([
                'token' => ['Ce lien de réinitialisation est invalide ou a expiré.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($data->password),
            // Une réinitialisation réussie vaut preuve de possession de la boîte mail :
            // laisser le compte verrouillé n'aurait aucun sens.
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_failed_login' => null,
        ])->saveQuietly();

        // Un mot de passe réinitialisé signifie un accès possiblement compromis :
        // toutes les sessions tombent, y compris celles d'autres appareils.
        $user->tokens()->delete();

        $this->tokens->consume($user);

        return $user;
    }
}
