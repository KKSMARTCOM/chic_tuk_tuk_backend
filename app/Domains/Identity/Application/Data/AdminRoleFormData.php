<?php

namespace App\Domains\Identity\Application\Data;

use App\Domains\Identity\Domain\ReferenceCatalog;
use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;

/**
 * Création et modification d'un rôle — ex-Admin\RoleController::store() et update().
 *
 * `permissions` porte des noms techniques, pris dans le catalogue de référence : une
 * ligne hors référence est un reliquat qu'aucun code ne vérifie. Le Blade envoyait des
 * identifiants numériques, qui changent d'une base à l'autre.
 *
 * Le nom technique n'est PAS un champ : il naît du libellé à la création, puis ne bouge
 * plus. Le Blade le recalculait à chaque modification.
 */
final class AdminRoleFormData extends BaseData
{
    public function __construct(
        public string $label,
        public ?string $description,
        /** @var string[] */
        public array $permissions,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(ReferenceCatalog::permissionNames())],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'label.required' => 'Le nom du rôle est obligatoire.',
            'permissions.*.in' => 'Cette permission ne fait pas partie du catalogue.',
        ];
    }
}
