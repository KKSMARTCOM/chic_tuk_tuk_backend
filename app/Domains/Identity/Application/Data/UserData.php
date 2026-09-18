<?php

namespace App\Domains\Identity\Application\Data;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use App\Shared\Data\BaseData;

/**
 * Représentation de l'utilisateur connecté, renvoyée par /auth/login et /auth/me.
 *
 * Les permissions sont les permissions EFFECTIVES (getAllPermissions), donc celles
 * des rôles et celles attribuées directement : le front en a besoin pour construire
 * sa navigation, et un menu calculé sur les seuls rôles serait faux.
 *
 * BaseData applique le mapping snake_case en sortie : dashboardPath devient
 * dashboard_path dans le JSON.
 */
final class UserData extends BaseData
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $email,
        /**
         * Ajoutés le 2026-09-19 pour l'écran de profil : `/auth/me` en devient la
         * lecture, plutôt qu'un endpoint de plus qui renverrait le même utilisateur.
         * Purement additif — les fronts qui les ignorent ne voient aucun changement.
         */
        public ?string $phone,
        public ?string $adresse,
        public string $profil,
        public string $dashboardPath,
        public array $roles,
        public array $permissions,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            phone: $user->phone,
            adresse: $user->adresse,
            profil: $user->profil,
            dashboardPath: Profil::from($user->profil)->dashboardPath(),
            roles: $user->getRoleNames()->all(),
            permissions: $user->getAllPermissions()->pluck('name')->all(),
        );
    }
}
