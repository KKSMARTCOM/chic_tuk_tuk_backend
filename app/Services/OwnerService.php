<?php

namespace App\Services;

use App\Consts\VehicleContractConsts;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OwnerService
{
    public function getAll(array $filters = [])
    {
        $query = User::with('roles', 'vehicles')
            ->where('profil', 'owner')
            ->role('proprietaire');

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
        $users = User::where('profil', 'owner')->role('proprietaire');

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
                'profil'    => 'owner',
                'adresse'   => $data['adresse'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // 2. Assigner le rôle Spatie
            $user->assignRole('proprietaire');

            // 3. Si rôle propriétaire → gérer véhicule + contrat
            $vehicle = null;

            // 3a. Nouveau véhicule à créer
            if (!empty($data['new_vehicle_number'])) {
                $vehicle = Vehicle::create([
                    'owner_id'       => $user->id,
                    'vehicle_number' => $data['new_vehicle_number'],
                    'vehicle_type'   => $data['new_vehicle_type']  ?? 'tricycle',
                    'notes'          => $data['new_vehicle_notes'] ?? null,
                    'is_active'      => true,
                ]);
            }
            // 3b. Véhicule existant sélectionné → changer le propriétaire
            elseif (!empty($data['vehicle_id'])) {
                $vehicle = Vehicle::findOrFail($data['vehicle_id']);
                $this->claimVehicle($vehicle, $user, (bool) ($data['confirm_transfer'] ?? false));
            }

            // 4. Créer le contrat proprio-véhicule si montant renseigné
            if ($vehicle && !empty($data['contract_total_amount'])) {
                VehicleContract::create([
                    'vehicle_id'      => $vehicle->id,
                    'owner_id'        => $user->id,
                    'total_amount'    => $data['contract_total_amount'],
                    'contract_months'  => $data['contract_months'] ?? 24,
                    'monthly_payment' => $data['contract_monthly_payment'] ?? 0,
                    'unlimited_internet' => $data['unlimited_internet'] ?? 0,
                    'spotify_premium' => $data['spotify_premium'] ?? 0,
                    'manager_remuneration' => $data['manager_remuneration'] ?? 0,
                    'start_date'      => $data['contract_start_date']      ?? now(),
                    'notes'           => $data['contract_notes']            ?? null,
                    'status'          => 'active',
                ]);
            }

            return $user;
        });
    }

    public function update(User $owner, array $data): User
    {
        return DB::transaction(function () use ($owner, $data) {
            // ── 1. Infos de base ─────────────────────────────────
            $owner->update([
                'name'      => $data['name'],
                'email'     => $data['email']    ?? $owner->email,
                'phone'     => $data['phone'],
                'profil'    => 'owner',
                'adresse'   => $data['adresse']  ?? $owner->adresse,
                'is_active' => $data['is_active'] ?? $owner->is_active,
            ]);

            // ── 2. Véhicules existants (sans agent actif uniquement) ──
            if (!empty($data['vehicles']) && is_array($data['vehicles'])) {
                foreach ($data['vehicles'] as $vehicleId => $vData) {

                    $vehicle = Vehicle::find($vehicleId);

                    // Véhicule introuvable ou n'appartient pas à ce proprio → ignorer
                    if (!$vehicle || $vehicle->owner_id !== $owner->id) continue;

                    // Véhicule avec agent actif → non modifiable
                    if ($vehicle->activeDriverContract) continue;

                    // Vérifier si le véhicule a un contrat actif
                    $activeContract = $vehicle->activeVehicleContract;
                    $vData['contract_id'] = $activeContract?->id;

                    // Mettre à jour les infos du véhicule
                    $vehicle->update([
                        'vehicle_number' => $vData['vehicle_number'] ?? $vehicle->vehicle_number,
                        'vehicle_type'   => $vData['vehicle_type']   ?? $vehicle->vehicle_type,
                        // La colonne est `notes`. Le service écrivait `vehicle_notes`, que
                        // `$fillable` ignorait : les notes étaient perdues (corrigé le
                        // 2026-09-25). Une note vidée efface la note.
                        'notes'          => array_key_exists('vehicle_notes', $vData) ? $vData['vehicle_notes'] : $vehicle->notes,
                        'is_active'      => $vData['is_active'] ?? $vehicle->is_active,
                    ]);

                    // Mettre à jour ou créer le contrat
                    $this->syncVehicleContract($vehicle, $owner->id, $vData);
                }
            }

            // ── 3. Ajouter un véhicule selon le mode ─────────────
            $addMode = $data['_add_vehicle_mode'] ?? null;

            if ($addMode === 'new' && !empty($data['new_vehicle_number'])) {
                // Nouveau véhicule
                $newVehicle = Vehicle::create([
                    'owner_id'       => $owner->id,
                    'vehicle_number' => $data['new_vehicle_number'],
                    'vehicle_type'   => $data['new_vehicle_type']  ?? 'tricycle',
                    'notes'          => $data['new_vehicle_notes'] ?? null,
                    'is_active'      => true,
                ]);

                $this->syncVehicleContract($newVehicle, $owner->id, $data['new'] ?? []);
            } elseif ($addMode === 'existing' && !empty($data['vehicle_id'])) {
                // Véhicule existant
                $vehicle = Vehicle::findOrFail($data['vehicle_id']);
                $this->claimVehicle($vehicle, $owner, (bool) ($data['confirm_transfer'] ?? false));

                $this->syncVehicleContract($vehicle, $owner->id, $data['existing_vehicle'] ?? []);
            }

            return $owner->refresh();
        });
    }

    /**
     * Rattache un véhicule existant au propriétaire.
     *
     * Règle décidée le 2026-09-25, la même à la création et à l'édition : un véhicule
     * qui appartient déjà à un autre propriétaire ne change de mains que si
     * l'administrateur a CONFIRMÉ le transfert. Auparavant, la création le transférait
     * en silence et l'édition le refusait.
     *
     * Un véhicule sous contrat propriétaire-véhicule en cours ne se transfère jamais :
     * le contrat lie son propriétaire actuel.
     *
     * Des `ApiException` : elles héritent d'`Exception`, le Blade les affiche donc en
     * message flash comme avant. Il n'envoie jamais `confirm_transfer`, et refuse
     * désormais le transfert dans les deux écrans.
     */
    private function claimVehicle(Vehicle $vehicle, User $owner, bool $transferConfirmed): void
    {
        if ($vehicle->owner_id === $owner->id) {
            return;
        }

        $runningContract = $vehicle->activeVehicleContract;
        if ($runningContract !== null) {
            throw new ApiException(
                409,
                'VEHICLE_UNDER_CONTRACT',
                "Le véhicule {$vehicle->vehicle_number} est sous contrat avec son propriétaire actuel : il ne peut pas être transféré.",
                ['vehicle_number' => $vehicle->vehicle_number],
            );
        }

        if ($vehicle->owner_id !== null && ! $transferConfirmed) {
            $currentOwnerName = $vehicle->owner?->name;

            throw new ApiException(
                409,
                'VEHICLE_TRANSFER_UNCONFIRMED',
                "Le véhicule {$vehicle->vehicle_number} appartient déjà à {$currentOwnerName}. Confirmez le transfert pour le lui retirer.",
                [
                    'vehicle_number' => $vehicle->vehicle_number,
                    'current_owner_name' => $currentOwnerName,
                ],
            );
        }

        $vehicle->update(['owner_id' => $owner->id]);
    }

    // ── Méthode privée : créer ou mettre à jour le contrat ────────
    private function syncVehicleContract(Vehicle $vehicle, string $ownerId, array $data): void
    {
        // Aucun montant renseigné → rien à faire
        if (empty($data['contract_total_amount'])) return;

        // Résoudre la durée (24/30/36 ou "other" → valeur manuelle)
        $months = ($data['contract_months'] ?? '') === 'other'
            ? (int) ($data['contract_months_other'] ?? 0)
            : (int) ($data['contract_months'] ?? 0);

        if ($months <= 0) return;

        $contractData = [
            'contract_months'      => $months,
            'total_amount'         => $data['contract_total_amount'],
            'monthly_payment'      => $data['contract_monthly_payment']  ?? 0,
            'start_date'           => $data['contract_start_date']       ?? now()->toDateString(),
            'end_date'             => $data['contract_end_date']          ?? null,
            'unlimited_internet'   => $data['unlimited_internet']        ?? VehicleContractConsts::DEFAULT_UNLIMITED_INTERNET,
            'spotify_premium'      => $data['spotify_premium']           ?? VehicleContractConsts::DEFAULT_SPOTIFY_PREMIUM,
            'manager_remuneration' => $data['manager_remuneration']      ?? VehicleContractConsts::DEFAULT_MANAGER_REMUNERATION,
        ];

        // Le formulaire d'édition Blade n'a pas de champ de notes : ne les toucher que
        // si elles sont envoyées, pour ne pas effacer celles saisies à la création.
        if (array_key_exists('contract_notes', $data)) {
            $contractData['notes'] = $data['contract_notes'];
        }

        $activeContract = $vehicle->activeVehicleContract;

        if (!empty($data['contract_id']) && $activeContract?->id === $data['contract_id']) {
            // Contrat existant identifié → mise à jour
            $activeContract->update($contractData);
        } elseif (!$activeContract) {
            // Aucun contrat actif → création
            VehicleContract::create(array_merge($contractData, [
                'vehicle_id' => $vehicle->id,
                'owner_id'   => $ownerId,
                'status'     => 'active',
            ]));
        }
        // Sinon (contrat actif mais non identifié dans $data) → on ne touche pas
    }
}
