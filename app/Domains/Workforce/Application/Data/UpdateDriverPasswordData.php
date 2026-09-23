<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Le mot de passe d'un agent, réinitialisé par l'administration —
 * ex-Admin\DriverController::updatePassword().
 *
 * Mêmes règles que le Blade : `confirmed` exige un champ `password_confirmation`
 * identique dans le corps de la requête, sans qu'il faille le déclarer ici — voir
 * `ChangePasswordData`, qui pose la même convention.
 */
final class UpdateDriverPasswordData extends BaseData
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
