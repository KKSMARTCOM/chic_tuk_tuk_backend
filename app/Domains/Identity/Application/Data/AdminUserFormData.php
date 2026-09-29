<?php

namespace App\Domains\Identity\Application\Data;

use App\Domains\Identity\Application\Actions\AssignableRoles;
use App\Shared\Data\BaseData;
use App\Shared\Validation\EmailRules;
use Illuminate\Validation\Rule;

/**
 * Création et modification d'un administrateur — ex-Admin\UserController::store() et
 * update(). Le mot de passe n'est exigé qu'à la création.
 *
 * Unicité de l'e-mail et du téléphone parmi les comptes ADMINISTRATEURS, comme la
 * contrainte en base (`users_email_profil_unique`, `users_phone_profil_unique`) : une même
 * personne peut avoir un compte agent et un compte administrateur, et la connexion sait
 * alors lui demander lequel (`PROFIL_AMBIGUOUS`).
 *
 * ⚠️ Le rôle est OBLIGATOIRE et choisi parmi les rôles d'administration. Le Blade
 * acceptait tout rôle existant, `driver` et `proprietaire` compris, et son option
 * « Par défaut (profil) » plantait sur une clé `profil` absente.
 */
final class AdminUserFormData extends BaseData
{
    public function __construct(
        public string $name,
        public ?string $email,
        public string $phone,
        public string $role,
        public ?string $adresse,
        public bool $isActive = true,
        public ?string $password = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        // Absent à la création : l'ignorer ne change alors rien.
        $userId = request()->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', ...EmailRules::rules(), Rule::unique('users', 'email')->where('profil', 'admin')->ignore($userId)],
            'phone' => ['required', 'string', Rule::unique('users', 'phone')->where('profil', 'admin')->ignore($userId)],
            'role' => ['required', 'string', Rule::in(app(AssignableRoles::class)->names())],
            'adresse' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'password' => $userId === null
                ? ['required', 'string', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[@$!%*#?&]/']
                // Ignoré à la modification : il a son propre formulaire, qui révoque les sessions.
                : ['exclude'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'phone.required' => 'Le téléphone est obligatoire.',
            'phone.unique' => 'Ce numéro est déjà utilisé.',
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => 'Ce rôle ne peut pas être attribué à un administrateur.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.regex' => 'Le mot de passe doit contenir au moins une majuscule, un chiffre et un caractère spécial (@$!%*#?&).',
        ];
    }
}
