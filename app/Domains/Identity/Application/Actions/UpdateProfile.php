<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\UpdateProfileData;
use App\Models\User;

/**
 * Mettre à jour son propre profil.
 *
 * Placée sous `Identity` et non sous un espace : c'est l'utilisateur qu'on modifie, pas
 * l'agent. Le propriétaire et l'administrateur en auront besoin aussi, et dupliquer par
 * espace coûterait trois fois.
 */
final class UpdateProfile
{
    public function __invoke(User $user, UpdateProfileData $data): User
    {
        $user->update([
            'name' => $data->name,
            'phone' => $data->phone,
            'adresse' => $data->adresse,
        ]);

        return $user->fresh();
    }
}
