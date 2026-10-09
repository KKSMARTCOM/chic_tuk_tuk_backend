<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\CreateOwnerData;
use App\Domains\Fleet\Domain\ContractTerms;
use App\Domains\Fleet\Domain\VehicleContractRules;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Créer un propriétaire — ex-Admin\OwnerController::store().
 *
 * Le code est celui du Blade, ex-`CreateOwner` déplacé ici le 2026-09-27 : compte, véhicule et
 * contrat dans une même transaction. Les refus métier y sont des `ApiException`
 * (transfert non confirmé, véhicule sous contrat), qui traversent telles quelles.
 */
final class CreateOwner
{
    public function __construct(
        private readonly ClaimVehicleForOwner $claimVehicle,
        private readonly CheckContractTotal $checkTotal,
    ) {}

    public function __invoke(CreateOwnerData $data): User
    {
        return $this->create($data->toServicePayload());
    }

    private function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // 1. Créer l'utilisateur
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
                'profil' => 'owner',
                'adresse' => $data['adresse'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // 2. Assigner le rôle Spatie
            $user->assignRole('proprietaire');

            // 3. Si rôle propriétaire → gérer véhicule + contrat
            $vehicle = null;

            // 3a. Nouveau véhicule à créer
            if (! empty($data['new_vehicle_number'])) {
                $vehicle = Vehicle::create([
                    'owner_id' => $user->id,
                    'vehicle_number' => $data['new_vehicle_number'],
                    'vehicle_type' => $data['new_vehicle_type'] ?? 'tricycle',
                    'notes' => $data['new_vehicle_notes'] ?? null,
                    'is_active' => true,
                ]);
            }
            // 3b. Véhicule existant sélectionné → changer le propriétaire
            elseif (! empty($data['vehicle_id'])) {
                $vehicle = Vehicle::findOrFail($data['vehicle_id']);
                ($this->claimVehicle)($vehicle, $user, (bool) ($data['confirm_transfer'] ?? false));
            }

            // 4. Créer le contrat proprio-véhicule si montant renseigné.
            // Corrigé le 2026-09-29 : les charges laissées vides valaient 0 ici, et les
            // valeurs par défaut partout ailleurs.
            if ($vehicle && ! empty($data['contract_total_amount'])) {
                $months = (int) ($data['contract_months'] ?? 24);
                VehicleContractRules::assertCanCarryAnActiveContract($vehicle);
                ($this->checkTotal)($months, $data['contract_total_amount']);

                VehicleContract::create([
                    'vehicle_id' => $vehicle->id,
                    'owner_id' => $user->id,
                    'total_amount' => $data['contract_total_amount'],
                    'contract_months' => $months,
                    ...ContractTerms::dailyAmountsFor($months),
                    'monthly_payment' => $data['contract_monthly_payment'] ?? 0,
                    ...ContractTerms::chargesFrom($data),
                    'start_date' => $data['contract_start_date'] ?? now(),
                    'notes' => $data['contract_notes'] ?? null,
                    'status' => 'active',
                ]);
            }

            return $user;
        });
    }
}
