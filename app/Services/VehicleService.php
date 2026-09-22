<?php

namespace App\Services;

use App\Domains\Notification\Application\Notifier;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VehicleService
{
    public function getAll(array $filters = [])
    {
        $query = Vehicle::with(['owner', 'activeVehicleContract', 'activeDriverContract.driver.user', 'activePause']);

        if (!empty($filters['search'])) {
            $query->where('vehicle_number', 'LIKE', "%{$filters['search']}%");
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (!empty($filters['owner_id'])) {
            $query->where('owner_id', $filters['owner_id']);
        }

        return $query->latest()->get();
    }

    public function create(array $data): Vehicle
    {
        return DB::transaction(function () use ($data) {
            // Créer le véhicule
            $vehicle = Vehicle::create([
                'vehicle_number' => $data['vehicle_number'],
                'vehicle_type'   => $data['vehicle_type'],
                'notes'          => $data['notes']  ?? null,
                'is_active'      => true,
            ]);

            return $vehicle;
        });
    }

    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        return DB::transaction(function () use ($data, $vehicle) {
            // Mettre à jour le véhicule
            $vehicle->update([
                'vehicle_number' => $data['vehicle_number'] ?? $vehicle->vehicle_number,
                'vehicle_type'   => $data['vehicle_type'] ?? $vehicle->vehicle_type,
                'notes'          => $data['notes'] ?? $vehicle->notes,
                'is_active'      => $data['is_active'] ?? $vehicle->is_active,
            ]);

            return $vehicle->refresh();
        });
    }

    public function toggleStatus(Vehicle $vehicle): Vehicle
    {
        $vehicle->update(['is_active' => !$vehicle->is_active]);
        return $vehicle->refresh();
    }

    // Mettre le véhicule en pause manuellement
    public function pauseVehicle(Vehicle $vehicle, array $data): VehiclePause
    {
        $pause = DB::transaction(function () use ($vehicle, $data) {
            // Vérifier si il y a un contrat actif
            $activeContract = $vehicle->activeVehicleContract;
            if (!$activeContract) {
                throw new \Exception("Impossible de mettre le véhicule en pause car il n'a pas de contrat actif.");
            }

            // Clôturer la pause active si existante
            if ($vehicle->activePause) {
                $vehicle->activePause->update(['end_date' => $data['start_date'] ?? now()->toDateString()]);
            }

            // Créer une nouvelle pause
            $pause = VehiclePause::create([
                'vehicle_id'          => $vehicle->id,
                'vehicle_contract_id' => $vehicle->activeVehicleContract?->id,
                'driver_contract_id'  => $data['driver_contract_id'] ?? null,
                'start_date'          => $data['start_date'] ?? now()->toDateString(),
                'end_date'            => $data['end_date'] ?? null,
                'reason_type'         => $data['reason_type'] ?? 'manual',
                'reason_notes'        => $data['reason_notes'] ?? null,
                'is_auto'             => false,
            ]);

            $vehicle->update(['is_active' => false]);

            return $pause;
        });

        // Le propriétaire est prévenu, avec le MOTIF : un véhicule à l'arrêt sans
        // explication déclenche un appel à l'administration, et c'est cet appel que la
        // notification remplace.
        app(Notifier::class)->vehiclePaused($pause);

        return $pause;
    }

    // Terminer une pause véhicule
    public function endPause(VehiclePause $pause, ?string $endDate = null): VehiclePause
    {
        $termine = DB::transaction(function () use ($pause, $endDate) {
            $pause->update(['end_date' => $endDate ?? now()->toDateString()]);

            // Réactiver le véhicule si pas de pause active
            if (!$pause->vehicle->activePause) {
                $pause->vehicle->update(['is_active' => true]);
            }

            return $pause->refresh();
        });

        // Le propriétaire apprend la reprise sans avoir à ouvrir l'application.
        app(Notifier::class)->vehiclePauseEnded($termine);

        return $termine;
    }

    /**
     * ANNULER une pause véhicule : la supprimer, comme si elle n'avait jamais existé.
     *
     * ⚠️ À distinguer de `endPause()`, qui la CLÔTURE et en garde la trace. On annule une
     * pause posée par erreur ; on clôture une pause qui a réellement eu lieu. Confondre
     * les deux laisserait dans l'historique du propriétaire une immobilisation fictive.
     *
     * ⚠️ Si cette pause en avait clôturé une précédente à sa création — ce que fait
     * `createAutoAgentPause` —, la précédente n'est PAS rouverte : on ne sait pas si sa
     * clôture était juste par ailleurs. Cas rare, signalé plutôt que deviné.
     */
    public function cancelPause(VehiclePause $pause): void
    {
        DB::transaction(function () use ($pause) {
            $vehicule = $pause->vehicle;
            $pause->delete();

            // Le véhicule reprend du service si plus rien ne le retient.
            if ($vehicule && ! $vehicule->fresh()->activePause) {
                $vehicule->update(['is_active' => true]);
            }
        });
    }

    // Créer automatiquement une pause véhicule suite à l'absence d'un agent
    public function createAutoAgentPause(string $vehicleId, string $driverContractId, string $startDate, ?string $endDate = null): VehiclePause
    {
        $pause = DB::transaction(function () use ($vehicleId, $driverContractId, $startDate, $endDate) {
            $vehicle = Vehicle::findOrFail($vehicleId);
            $start   = Carbon::parse($startDate)->startOfDay();
            $end     = $endDate ? Carbon::parse($endDate)->startOfDay() : null;
            $today   = Carbon::today();

            // Déterminer si la pause est passée, présente ou future
            $isPast    = $end && $end->lt($today);
            $isCurrent = $start->lte($today) && (!$end || $end->gte($today));
            // $isFuture  = $startDate->gt($today);  // implicite

            // ── Clôturer la pause active éventuelle ──────────────
            // Uniquement si la nouvelle pause commence aujourd'hui ou dans le futur
            if (!$isPast && $vehicle->activePause) {
                $vehicle->activePause->update(['end_date' => $start->toDateString()]);
            }

            // ── Créer la pause véhicule ───────────────────────────
            $pause = VehiclePause::create([
                'vehicle_id'          => $vehicle->id,
                'vehicle_contract_id' => $vehicle->activeVehicleContract?->id,
                'driver_contract_id'  => $driverContractId,
                'start_date'          => $start->toDateString(),
                'end_date'            => $end?->toDateString(), // toujours renseigné car on connaît les dates
                'reason_type'         => 'agent_leave',
                'reason_notes'        => 'Pause automatique suite à une pause agent — '
                    . ($isPast ? 'passé' : ($isCurrent ? 'en cours' : 'futur'))
                    . '.',
                'is_auto'             => true,
            ]);

            // ── Désactiver le véhicule uniquement si la pause est en cours ──
            // Passée → le véhicule n'est plus en pause, rien à changer
            // Présente → désactiver
            // Future → on ne touche pas à is_active maintenant (sera géré par un job ou à la date)
            if ($isCurrent) {
                $vehicle->update(['is_active' => false]);
            }

            return $pause;
        });

        // ⚠️ Le propriétaire est prévenu ICI AUSSI, et pas seulement sur une pause
        // manuelle : une pause née de l'absence d'un agent immobilise son véhicule tout autant,
        // et c'est le cas le plus fréquent. Le motif dit « Pause agent ».
        app(Notifier::class)->vehiclePaused($pause);

        return $pause;
    }

    // Terminer la pause véhicule liée à un contrat chauffeur (appelée quand l'admin clôture la pause agent)
    public function endAgentPause(string $driverContractId, ?string $endDate = null): ?VehiclePause
    {
        $pause = VehiclePause::where('driver_contract_id', $driverContractId)
            ->whereNull('end_date')
            ->latest('start_date')
            ->first();

        if (!$pause) {
            return null;
        }

        return $this->endPause($pause, $endDate);
    }

    /**
     * Corriger la date de début (et éventuellement la date de fin) d'une pause véhicule existante,
     * sans en créer une nouvelle.
     */
    public function correctPauseDates(VehiclePause $pause, string $startDate, ?string $endDate = null): VehiclePause
    {
        return DB::transaction(function () use ($pause, $startDate, $endDate) {
            $pause->update([
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            $vehicle = $pause->vehicle;
            $today = Carbon::today();
            $start = Carbon::parse($startDate)->startOfDay();
            $end   = $endDate ? Carbon::parse($endDate)->startOfDay() : null;

            $isCurrentlyPaused = $start->lte($today) && (!$end || $end->gte($today));

            // Le véhicule doit refléter l'état réel après correction
            if ($isCurrentlyPaused) {
                $vehicle->update(['is_active' => false]);
            } elseif (!$vehicle->activePause) {
                $vehicle->update(['is_active' => true]);
            }

            return $pause->refresh();
        });
    }

    public function removeOwner(Vehicle $vehicle): Vehicle
    {
        return DB::transaction(function () use ($vehicle) {

            // Clôturer le contrat actif si existant
            $active = $vehicle->activeVehicleContract;
            if ($active) {
                $active->update([
                    'status'   => 'cancelled',
                    'end_date' => now()->toDateString(),
                    'notes'    => ($active->notes ? $active->notes . "\n" : '')
                        . 'Contrat clôturé suite au détachement du propriétaire.',
                ]);
            }

            // Retirer le propriétaire
            $vehicle->update(['owner_id' => null]);

            return $vehicle->refresh();
        });
    }

    // Récupère les véhicules d'un propriétaire avec statistiques (contrat, paiements par mois, pauses)
    // ── Méthodes privées ──────────────────────────────────────────

    private function resolveOwner(string $mode, array $data): string
    {
        if ($mode === 'existing') {
            return $data['owner_id'];
        }

        $ownerRole = Role::firstOrCreate(
            ['name' => 'proprietaire', 'guard_name' => 'web'],
            ['label' => 'Propriétaire']
        );

        $owner = User::create([
            'name'      => $data['new_owner_name'],
            'phone'     => $data['new_owner_phone'],
            'email'     => $data['new_owner_email']     ?? null,
            'password'  => Hash::make($data['new_owner_password']),
            'profil'    => 'client',
            'is_active' => true,
        ]);

        $owner->assignRole($ownerRole);

        return $owner->id;
    }

    private function cancelActiveContract(VehicleContract $contract, string $reason = ''): void
    {
        $contract->update([
            'status'   => 'cancelled',
            'end_date' => now()->toDateString(),
            'notes'    => trim(($contract->notes ? $contract->notes . "\n" : '') . $reason),
        ]);
    }
}
