<?php

namespace App\Domains\Identity\Application\Data;

use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Le corps de PATCH /auth/profile.
 *
 * ⚠️ Trois champs seulement, et c'est une décision, pas un oubli.
 *
 * L'E-MAIL est absent : c'est l'identifiant de connexion, et le laisser modifier sans
 * vérification par lien exposerait un agent à s'enfermer dehors sur une faute de frappe.
 * La PHOTO est absente aussi : elle serait le premier envoi de fichier de l'API v1 —
 * multipart, stockage, URL publique, limite de taille — et mérite son propre lot.
 *
 * `SettingsController::updateProfile()` valide les cinq ; on en reprend trois.
 */
final class UpdateProfileData extends BaseData
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $adresse,
    ) {}

    /** @return array<string, array<int, mixed>> */
    public static function rules(ValidationContext $context): array
    {
        // `users.phone` est UNIQUE en base : sans la règle, le conflit ressortirait en
        // 500 depuis PostgreSQL au lieu d'un 422 qui désigne le champ.
        $id = auth()->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($id)],
            'adresse' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.required' => 'Votre nom est obligatoire.',
            'phone.required' => 'Votre numéro de téléphone est obligatoire.',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé par un autre compte.',
        ];
    }
}
