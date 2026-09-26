<?php

namespace App\Services;

use App\Consts\VehicleContractConsts;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

class VehicleContractService
{
    /**
     * Crée le contrat d'un véhicule, au nom de son propriétaire.
     *
     * Corrigé le 2026-09-26 : un véhicule sans propriétaire faisait échouer l'insertion
     * (`owner_id` NOT NULL), et rien n'empêchait un second contrat actif. Les charges
     * laissées vides prennent les valeurs par défaut, comme depuis l'écran propriétaire.
     */
    public function create(array $data): VehicleContract
    {
        return DB::transaction(function () use ($data) {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($data['vehicle_id']);
            $this->assertCanCarryAnActiveContract($vehicle);

            return VehicleContract::create([
                'vehicle_id'          => $vehicle->id,
                'owner_id'            => $vehicle->owner_id,
                'total_amount'        => $data['total_amount'],
                'monthly_payment'     => $data['monthly_payment'] ?? 0,
                'start_date'          => $data['start_date'],
                'end_date'            => $data['end_date'] ?? null,
                'status'              => 'active',
                'notes'               => $data['notes'] ?? null,
                'contract_months'     => $data['contract_months'] ?? null,
                'unlimited_internet'  => $data['unlimited_internet'] ?? VehicleContractConsts::DEFAULT_UNLIMITED_INTERNET,
                'spotify_premium'     => $data['spotify_premium'] ?? VehicleContractConsts::DEFAULT_SPOTIFY_PREMIUM,
                'manager_remuneration' => $data['manager_remuneration'] ?? VehicleContractConsts::DEFAULT_MANAGER_REMUNERATION,
            ]);
        });
    }

    public function getStats(VehicleContract $contract): array
    {
        $totalPaid     = (float) $contract->payments()->where('status', 'completed')->sum('net_amount');
        $totalAmount   = (float) $contract->total_amount;
        $remaining     = $totalAmount - $totalPaid;
        $surplus       = $remaining < 0 ? abs($remaining) : 0;
        $remaining     = max(0, $remaining);

        return [
            'total_amount'       => $totalAmount,
            'total_paid'         => $totalPaid,
            'remaining'          => $remaining,
            'surplus'            => $surplus,
            'progress_percent'   => $totalAmount > 0 ? min(100, round(($totalPaid / $totalAmount) * 100)) : 0,
            'payments_count'     => $contract->payments()->count(),
            'monthly_payment'    => (float) $contract->monthly_payment,
        ];
    }

    /**
     * Modifie un contrat.
     *
     * Corrigé le 2026-09-26 : la date de fin n'est plus touchée — le contrôleur Blade
     * lisait un `end_date` que sa validation ne laissait jamais passer, et chaque
     * enregistrement l'effaçait. Un contrat actif ne peut plus atterrir sur un véhicule
     * qui en a déjà un, ni sur un véhicule sans propriétaire.
     */
    public function update(VehicleContract $contract, array $data): VehicleContract
    {
        return DB::transaction(function () use ($contract, $data) {
            if ($contract->vehicle && $contract->vehicle->activeDriverContract()->exists()) {
                throw new ApiException(
                    409,
                    'VEHICLE_CONTRACT_HAS_ACTIVE_DRIVER',
                    'Impossible de modifier ce contrat : le véhicule possède un agent actif.'
                );
            }

            $updateData = [
                'total_amount'        => $data['total_amount'],
                'start_date'          => $data['start_date'],
                'status'              => $data['status'] ?? $contract->status,
                'notes'               => $data['notes'] ?? null,
                'contract_months'     => $data['contract_months'] ?? $contract->contract_months,
                'unlimited_internet'  => $data['unlimited_internet'] ?? $contract->unlimited_internet,
                'spotify_premium'     => $data['spotify_premium'] ?? $contract->spotify_premium,
                'manager_remuneration' => $data['manager_remuneration'] ?? $contract->manager_remuneration,
            ];

            $vehicle = $contract->vehicle;

            if (!empty($data['vehicle_id']) && $data['vehicle_id'] !== $contract->vehicle_id) {
                $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($data['vehicle_id']);
                if ($vehicle->activeDriverContract()->exists()) {
                    throw new ApiException(
                        409,
                        'VEHICLE_CONTRACT_HAS_ACTIVE_DRIVER',
                        'Impossible de changer le véhicule : le véhicule sélectionné possède un agent actif.'
                    );
                }

                $this->assertHasOwner($vehicle);

                $updateData['vehicle_id'] = $vehicle->id;
                $updateData['owner_id']   = $vehicle->owner_id;
            }

            if ($updateData['status'] === 'active' && $vehicle) {
                $this->assertCanCarryAnActiveContract($vehicle, except: $contract);
            }

            $contract->update($updateData);

            return $contract->refresh();
        });
    }

    /**
     * Supprime un contrat, et rien d'autre.
     *
     * Décidé le 2026-09-26 : un contrat qui a un contrat agent, un paiement ou une pause
     * véhicule, même terminé, ne se supprime plus — les clés en cascade effaçaient les
     * contrats agents et les pauses, et détachaient les paiements. Et la suppression ne
     * remet plus `vehicles.owner_id` à null : le Blade le faisait même quand le véhicule
     * avait changé de propriétaire depuis. Rattacher ou retirer un véhicule se fait
     * depuis l'écran du propriétaire.
     *
     * Des `ApiException` : le Blade les affiche en message flash comme avant.
     */
    public function delete(VehicleContract $contract): void
    {
        if ($contract->status === 'active') {
            throw new ApiException(
                409,
                'VEHICLE_CONTRACT_ACTIVE',
                'Impossible de supprimer un contrat actif. Passez-le à « Soldé » ou « Annulé » d\'abord.'
            );
        }

        $hasHistory = $contract->driverContracts()->exists()
            || $contract->payments()->exists()
            || $contract->pauses()->exists();

        if ($hasHistory) {
            throw new ApiException(
                409,
                'VEHICLE_CONTRACT_NOT_DELETABLE',
                'Impossible de supprimer ce contrat : il a des agents, des paiements ou des pauses véhicule, en cours ou terminés.'
            );
        }

        $contract->delete();
    }

    /**
     * Un véhicule ne porte un contrat actif que s'il a un propriétaire et aucun autre
     * contrat actif.
     */
    private function assertCanCarryAnActiveContract(Vehicle $vehicle, ?VehicleContract $except = null): void
    {
        $this->assertHasOwner($vehicle);

        $hasAnotherActiveContract = $vehicle->vehicleContracts()
            ->where('status', 'active')
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();

        if ($hasAnotherActiveContract) {
            throw new ApiException(
                409,
                'VEHICLE_HAS_ACTIVE_CONTRACT',
                "Le véhicule {$vehicle->vehicle_number} a déjà un contrat actif."
            );
        }
    }

    /** Le contrat est au nom du propriétaire du véhicule : sans lui, pas de contrat. */
    private function assertHasOwner(Vehicle $vehicle): void
    {
        if (!$vehicle->owner_id) {
            throw new ApiException(
                409,
                'VEHICLE_HAS_NO_OWNER',
                "Le véhicule {$vehicle->vehicle_number} n'a pas de propriétaire : rattachez-le d'abord depuis l'écran d'un propriétaire."
            );
        }
    }
}
