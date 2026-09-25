<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Le mot de passe d'un propriétaire, réinitialisé par l'administration —
 * ex-Admin\UserController::updatePassword(), que la liste Blade des propriétaires
 * appelait. `confirmed` exige un `password_confirmation` identique.
 */
final class UpdateOwnerPasswordData extends BaseData
{
    public function __construct(
        public string $password,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[@$!%*#?&]/'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'password.required' => 'Le mot de passe est requis.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.regex' => 'Le mot de passe doit contenir au moins une majuscule, un chiffre et un caractère spécial (@$!%*#?&).',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
        ];
    }
}
