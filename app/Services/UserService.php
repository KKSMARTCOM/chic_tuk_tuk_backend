<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserService
{
    public function getAll(array $filters = [])
    {
        $query = User::with('roles')
            ->where('profil', 'admin');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        if (isset($filters['profil']) && $filters['profil'] !== '') {
            $query->where('profil', $filters['profil']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->latest()->get();
    }

    public function getStats(): array
    {
        $users = User::where('profil', 'admin');

        return [
            'total'    => $users->count(),
            'active'   => (clone $users)->where('is_active', true)->count(),
            'inactive' => (clone $users)->where('is_active', false)->count(),
        ];
    }

    public function generatePassword(int $length = 8): string
    {
        // Génère un mot de passe qui respecte les règles de validation : 8 caractères, 1 majuscule, 1 minuscule, 1 chiffre, 1 caractère spécial
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $digits    = '0123456789';
        $specials  = '@$!%*#?&';

        $password = [
            $uppercase[random_int(0, strlen($uppercase) - 1)],
            $lowercase[random_int(0, strlen($lowercase) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $specials[random_int(0, strlen($specials) - 1)],
        ];

        $allChars = $uppercase . $lowercase . $digits . $specials;

        for ($i = count($password); $i < $length; $i++) {
            $password[] = $allChars[random_int(0, strlen($allChars) - 1)];
        }

        shuffle($password);

        return implode('', $password);
    }

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // 1. Créer l'utilisateur
            $user = User::create([
                'name'      => $data['name'],
                'email'     => $data['email'] ?? null,
                'phone'     => $data['phone'],
                'password'  => Hash::make($data['password']),
                'profil'    => 'admin',
                'adresse'   => $data['adresse'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // 2. Assigner le rôle Spatie
            $role = !empty($data['role']) ? $data['role'] : $data['profil'];
            $user->assignRole($role);

            return $user;
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            // 1. Mettre à jour les infos de base
            $user->update([
                'name'      => $data['name'],
                'email'     => $data['email']   ?? $user->email,
                'phone'     => $data['phone'],
                'profil'    => 'admin',
                'adresse'   => $data['adresse'] ?? $user->adresse,
                'is_active' => $data['is_active'] ?? $user->is_active,
            ]);

            // 2. Mettre à jour le rôle Spatie
            if (!empty($data['role'])) {
                $user->syncRoles([$data['role']]);
            }

            return $user->refresh();
        });
    }

    public function updatePassword(User $user, string $password): void
    {
        $user->update(['password' => Hash::make($password)]);
    }

    public function toggleStatus(User $user): User
    {
        $user->update(['is_active' => !$user->is_active]);
        return $user->refresh();
    }

    public function delete(User $user): void
    {
        // Empêcher la suppression de son propre compte
        if ($user->id === Auth::id()) {
            throw new \Exception('Vous ne pouvez pas supprimer votre propre compte.');
        }

        // Un propriétaire qui a un véhicule ou un contrat véhicule, même terminé, ne se
        // supprime pas (décidé le 2026-09-25). Les clés étrangères sont en cascade : la
        // suppression emportait ses véhicules, leurs contrats terminés, les contrats
        // agents et les pauses véhicule. Seul un contrat ACTIF l'empêchait jusque-là.
        if ($user->profil === 'owner' || $user->hasRole('proprietaire')) {
            $hasFleet = $user->vehicles()->exists()
                || VehicleContract::where('owner_id', $user->id)->exists();

            if ($hasFleet) {
                throw new ApiException(
                    409,
                    'OWNER_NOT_DELETABLE',
                    'Impossible de supprimer ce propriétaire : il a des véhicules ou des contrats véhicule. Désactivez son compte à la place.'
                );
            }
        }

        $user->delete();
    }

    public function getAvailableRoles(): \Illuminate\Support\Collection
    {
        // Tous les rôles sauf driver, propriétaire et client
        return Role::whereNotIn('name', ['driver', 'proprietaire', 'client'])->get();
    }
}
