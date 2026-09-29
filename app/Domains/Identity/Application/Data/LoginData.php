<?php

namespace App\Domains\Identity\Application\Data;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Shared\Data\BaseData;
use App\Shared\Validation\EmailRules;
use Illuminate\Validation\Rule;

final class LoginData extends BaseData
{
    public function __construct(
        public string $email,
        public string $password,
        // Optionnel : absent au premier appel, fourni au second pour lever une
        // ambiguïté de profil. On ne le demande jamais d'emblée — le sélecteur du
        // formulaire Blade produit « Identifiants incorrects » sur un mauvais choix
        // alors que le mot de passe est bon.
        public ?Profil $profil = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'email' => ['required', 'string', ...EmailRules::rules()],
            'password' => ['required', 'string'],
            'profil' => ['nullable', Rule::enum(Profil::class)],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'email.required' => "L'adresse email est obligatoire.",
            'email.email' => "L'adresse email est invalide.",
            'password.required' => 'Le mot de passe est obligatoire.',
            'profil.enum' => 'Le profil sélectionné est invalide.',
        ];
    }
}
