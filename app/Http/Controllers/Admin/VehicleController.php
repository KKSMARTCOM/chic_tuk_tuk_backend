<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePause;
use App\Services\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VehicleController extends Controller
{
    public function __construct(private VehicleService $vehicleService) {}

    public function index(Request $request)
    {
        $filters  = $request->only(['search', 'is_active', 'owner_id']);
        $vehicles = $this->vehicleService->getAll($filters);
        $owners   = User::whereHas('roles', fn($q) => $q->where('name', 'proprietaire'))
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        // Enrichir chaque véhicule pour le modal JS
        $vehicles->each(function ($v) {
            $v->has_active_contract = $v->activeVehicleContract !== null;
        });

        return view('pages.admin.vehicles.index', compact('vehicles', 'owners'));
    }

    public function create()
    {
        $owners = User::whereHas('roles', fn($q) => $q->where('name', 'proprietaire'))->get();
        return view('pages.admin.vehicles.create', compact('owners'));
    }

    public function store(Request $request)
    {
        $rules = [
            'vehicle_number'           => 'required|string|unique:vehicles,vehicle_number',
            'vehicle_type'             => 'required|in:moto,tricycle,car',
            'notes'                    => 'nullable|string|max:500',
        ];

        $validated = $request->validate($rules, [
            'vehicle_number.required'      => 'Le numéro de véhicule est obligatoire.',
            'vehicle_number.unique'        => 'Ce numéro de véhicule existe déjà.',
            'vehicle_type.required'        => 'Le type de véhicule est obligatoire.',
            'vehicle_type.in'              => 'Le type de véhicule sélectionné est invalide.',
            'notes.string'                 => 'Les notes doivent être une chaîne de caractères.',
            'notes.max'                    => 'Les notes ne doivent pas dépasser 500 caractères.',
        ]);

        try {
            $data = $validated;

            $vehicle = $this->vehicleService->create($data);

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'vehicle' => $vehicle, 'message' => 'Véhicule créé avec succès.']);
            }

            return redirect()->route('admin.vehicles.index')->with('success', 'Véhicule créé avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du véhicule: ' . $e->getMessage(), ['exception' => $e]);
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(Vehicle $vehicle)
    {
        $vehicle->load([
            'owner',
            'activeVehicleContract.payments',
            'vehicleContracts',
            'activeDriverContract.driver.user',
            'driverContracts.driver.user',
            'pauses',
            'activePause',
        ]);

        $vehicle->has_active_contract = $vehicle->activeVehicleContract !== null;

        $owners   = User::whereHas('roles', fn($q) => $q->where('name', 'proprietaire'))
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        return view('pages.admin.vehicles.show', compact('vehicle', 'owners'));
    }

    public function edit(Vehicle $vehicle)
    {
        $owners = User::whereHas('roles', fn($q) => $q->where('name', 'proprietaire'))->get();
        return view('pages.admin.vehicles.edit', compact('vehicle', 'owners'));
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $activeContract    = $vehicle->activeVehicleContract;
        $hasActiveContract = $activeContract !== null;

        $rules = [
            'vehicle_number' => 'required|string|unique:vehicles,vehicle_number,' . $vehicle->id,
            'vehicle_type'   => 'required|in:moto,tricycle,car',
            'notes'          => 'nullable|string|max:500',
        ];

        $validated = $request->validate($rules, [
            'vehicle_number.unique'        => 'Ce numéro de véhicule existe déjà.',
            'vehicle_number.required'      => 'Le numéro de véhicule est obligatoire.',
            'vehicle_type.required'        => 'Le type de véhicule est obligatoire.',
            'vehicle_type.in'              => 'Le type de véhicule sélectionné est invalide.',
            'notes.string'                 => 'Les notes doivent être une chaîne de caractères.',
            'notes.max'                    => 'Les notes ne doivent pas dépasser 500 caractères.',
        ]);

        try {
            $data = array_merge($validated, ['has_active_contract' => $hasActiveContract]);

            $this->vehicleService->update($vehicle, $data);

            return back()->with('success', 'Véhicule mis à jour avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du véhicule: ' . $e->getMessage(), ['exception' => $e]);
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy(Vehicle $vehicle)
    {
        try {
            // La règle vit dans le service, partagée avec l'API (2026-09-25) : pas de
            // suppression dès qu'il existe un contrat, véhicule ou agent, même terminé.
            $this->vehicleService->delete($vehicle);

            return back()->with('success', 'Véhicule supprimé avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du véhicule: ' . $e->getMessage(), ['exception' => $e]);
            return back()->with('error', 'Impossible de supprimer ce véhicule : ' . $e->getMessage());
        }
    }

    public function addPause(Request $request, Vehicle $vehicle)
    {
        $validated = $request->validate([
            'vehicle_id'   => 'required|exists:vehicles,id',
            'start_date'   => 'required|date',
            'end_date'     => 'nullable|date|after_or_equal:start_date',
            'reason_type'  => 'required|in:agent_leave,agent_change,technical,accident,legal,other',
            'reason_notes' => 'nullable|string|max:1000',
        ], [
            'vehicle_id.required' => 'Le véhicule est requis.',
            'vehicle_id.exists' => 'Le véhicule sélectionné est invalide.',
            'start_date.required' => 'La date de début est requise.',
            'start_date.date' => 'La date de début doit être une date valide.',
            'end_date.date' => 'La date de fin doit être une date valide.',
            'end_date.after_or_equal' => 'La date de fin doit être après ou égale à la date de début.',
            'reason_type.required' => 'Le type de raison est requis.',
            'reason_type.in' => 'Le type de raison sélectionné est invalide.',
            'reason_notes.string' => 'Les notes doivent être une chaîne de caractères.',
            'reason_notes.max' => 'Les notes ne doivent pas dépasser 1000 caractères.',
        ]);

        try {
            $this->vehicleService->pauseVehicle($vehicle, $validated);

            return back()->with('success', 'Pause véhicule enregistrée avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'ajout de la pause: ' . $e->getMessage(), ['exception' => $e]);
            return back()->withInput()->with('error', 'Erreur lors de l\'ajout de la pause : ' . $e->getMessage());
        }
    }

    // Terminer une pause en cours
    public function endPause(Request $request, VehiclePause $vehiclePause)
    {
        $validated = $request->validate([
            'end_date' => 'required|date|after_or_equal:' . $vehiclePause->start_date->toDateString(),
        ], [
            'end_date.required' => 'La date de fin est requise.',
            'end_date.date' => 'La date de fin doit être une date valide.',
            'end_date.after_or_equal' => 'La date de fin doit être après ou égale à la date de début.',
        ]);

        try {
            $this->vehicleService->endPause($vehiclePause, $validated['end_date']);

            return back()->with('success', 'Pause terminée avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la fin de la pause: ' . $e->getMessage(), ['exception' => $e]);
            return back()->withInput()->with('error', 'Erreur lors de la fin de la pause : ' . $e->getMessage());
        }
    }

    public function destroyPause(VehiclePause $vehiclePause)
    {
        try {
            // `cancelManualPause` réactive le véhicule — un simple delete() le laissait
            // inactif (corrigé le 2026-09-25) — et refuse une pause automatique.
            $this->vehicleService->cancelManualPause($vehiclePause);
            return back()->with('success', 'Pause supprimée avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la pause: ' . $e->getMessage(), ['exception' => $e]);
            return back()->with('error', 'Erreur lors de la suppression de la pause : ' . $e->getMessage());
        }
    }

    public function toggleStatus(Vehicle $vehicle)
    {
        try {
            $this->vehicleService->toggleStatus($vehicle);
            return back()->with('success', 'Statut du véhicule mis à jour avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du statut: ' . $e->getMessage(), ['exception' => $e]);
            return back()->with('error', 'Erreur lors de la mise à jour du statut : ' . $e->getMessage());
        }
    }

    // AJAX — véhicules d'un propriétaire
    public function byOwner(User $owner)
    {
        $vehicles = $owner->vehicles()
            ->select('id', 'vehicle_number', 'vehicle_type', 'is_active')
            ->get();

        return response()->json($vehicles);
    }

    // Détacher un véhicule de son propriétaire
    public function detachOwner(Vehicle $vehicle)
    {
        $this->vehicleService->removeOwner($vehicle);

        return response()->json([
            'success' => true,
            'message' => "Véhicule {$vehicle->vehicle_number} détaché avec succès.",
            'vehicle' => $vehicle->only('id', 'vehicle_number', 'vehicle_type', 'color'),
        ]);
    }
}
