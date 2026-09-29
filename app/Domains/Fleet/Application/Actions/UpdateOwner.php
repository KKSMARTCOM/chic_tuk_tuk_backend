<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\UpdateOwnerData;
use App\Domains\Fleet\Domain\ContractTerms;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Support\Facades\DB;

/**
 * Modifier un propriétaire et ses véhicules — ex-Admin\OwnerController::update().
 *
 * Le corps est l'ex-`UpdateOwner`, déplacé ici sans changement le 2026-09-27.
 */
final class UpdateOwner
{
    public function __construct(private readonly ClaimVehicleForOwner $claimVehicle) {}

    public function __invoke(User $owner, UpdateOwnerData $data): User
    {
        return $this->update($owner, $data->toServicePayload());
    }

    private function update(User $owner, array $data): User
    {
        return DB::transaction(function () use ($owner, $data) {
            // ── 1. Infos de base ─────────────────────────────────
            $owner->update([
                'name' => $data['name'],
                'email' => $data['email'] ?? $owner->email,
                'phone' => $data['phone'],
                'profil' => 'owner',
                'adresse' => $data['adresse'] ?? $owner->adresse,
                'is_active' => $data['is_active'] ?? $owner->is_active,
            ]);

            // ── 2. Véhicules existants (sans agent actif uniquement) ──
            if (! empty($data['vehicles']) && is_array($data['vehicles'])) {
                foreach ($data['vehicles'] as $vehicleId => $vData) {

                    $vehicle = Vehicle::find($vehicleId);

                    // Véhicule introuvable ou n'appartient pas à ce proprio → ignorer
                    if (! $vehicle || $vehicle->owner_id !== $owner->id) {
                        continue;
                    }

                    // Véhicule avec agent actif → non modifiable
                    if ($vehicle->activeDriverContract) {
                        continue;
                    }

                    // Vérifier si le véhicule a un contrat actif
                    $activeContract = $vehicle->activeVehicleContract;
                    $vData['contract_id'] = $activeContract?->id;

                    // Mettre à jour les infos du véhicule
                    $vehicle->update([
                        'vehicle_number' => $vData['vehicle_number'] ?? $vehicle->vehicle_number,
                        'vehicle_type' => $vData['vehicle_type'] ?? $vehicle->vehicle_type,
                        // La colonne est `notes`. Le service écrivait `vehicle_notes`, que
                        // `$fillable` ignorait : les notes étaient perdues (corrigé le
                        // 2026-09-25). Une note vidée efface la note.
                        'notes' => array_key_exists('vehicle_notes', $vData) ? $vData['vehicle_notes'] : $vehicle->notes,
                        'is_active' => $vData['is_active'] ?? $vehicle->is_active,
                    ]);

                    // Mettre à jour ou créer le contrat
                    $this->syncVehicleContract($vehicle, $owner->id, $vData);
                }
            }

            // ── 3. Ajouter un véhicule selon le mode ─────────────
            $addMode = $data['_add_vehicle_mode'] ?? null;

            if ($addMode === 'new' && ! empty($data['new_vehicle_number'])) {
                // Nouveau véhicule
                $newVehicle = Vehicle::create([
                    'owner_id' => $owner->id,
                    'vehicle_number' => $data['new_vehicle_number'],
                    'vehicle_type' => $data['new_vehicle_type'] ?? 'tricycle',
                    'notes' => $data['new_vehicle_notes'] ?? null,
                    'is_active' => true,
                ]);

                $this->syncVehicleContract($newVehicle, $owner->id, $data['new'] ?? []);
            } elseif ($addMode === 'existing' && ! empty($data['vehicle_id'])) {
                // Véhicule existant
                $vehicle = Vehicle::findOrFail($data['vehicle_id']);
                ($this->claimVehicle)($vehicle, $owner, (bool) ($data['confirm_transfer'] ?? false));

                $this->syncVehicleContract($vehicle, $owner->id, $data['existing_vehicle'] ?? []);
            }

            return $owner->refresh();
        });
    }

    // ── Méthode privée : créer ou mettre à jour le contrat ────────
    private function syncVehicleContract(Vehicle $vehicle, string $ownerId, array $data): void
    {
        // Aucun montant renseigné → rien à faire
        if (empty($data['contract_total_amount'])) {
            return;
        }

        // Résoudre la durée (24/30/36 ou "other" → valeur manuelle)
        $months = ($data['contract_months'] ?? '') === 'other'
            ? (int) ($data['contract_months_other'] ?? 0)
            : (int) ($data['contract_months'] ?? 0);

        if ($months <= 0) {
            return;
        }

        $contractData = [
            'contract_months' => $months,
            'total_amount' => $data['contract_total_amount'],
            'monthly_payment' => $data['contract_monthly_payment'] ?? 0,
            'start_date' => $data['contract_start_date'] ?? now()->toDateString(),
            'end_date' => $data['contract_end_date'] ?? null,
            ...ContractTerms::chargesFrom($data),
        ];

        // Le formulaire d'édition Blade n'a pas de champ de notes : ne les toucher que
        // si elles sont envoyées, pour ne pas effacer celles saisies à la création.
        if (array_key_exists('contract_notes', $data)) {
            $contractData['notes'] = $data['contract_notes'];
        }

        $activeContract = $vehicle->activeVehicleContract;

        if (! empty($data['contract_id']) && $activeContract?->id === $data['contract_id']) {
            // Contrat existant identifié → mise à jour. Il ne reprend les montants
            // journaliers des réglages que si sa durée change : sinon il garde les siens,
            // même si sa durée n'est plus proposée.
            if ($activeContract->contract_months !== $months) {
                $contractData = [...$contractData, ...ContractTerms::dailyAmountsFor($months)];
            }

            $activeContract->update($contractData);
        } elseif (! $activeContract) {
            // Aucun contrat actif → création
            VehicleContract::create(array_merge($contractData, ContractTerms::dailyAmountsFor($months), [
                'vehicle_id' => $vehicle->id,
                'owner_id' => $ownerId,
                'status' => 'active',
            ]));
        }
        // Sinon (contrat actif mais non identifié dans $data) → on ne touche pas
    }
}
