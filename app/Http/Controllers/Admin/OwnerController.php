<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use App\Services\OwnerService;
use Illuminate\Support\Facades\Log;

class OwnerController extends Controller
{
    public function __construct(private OwnerService $ownerService) {}

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'is_active']);
        $users   = $this->ownerService->getAll($filters);
        $stats   = $this->ownerService->getStats();

        return view('pages.admin.owners.index', compact('users', 'stats'));
    }

    public function create()
    {
        $users = $this->ownerService->getAll();

        // Véhicules sans propriétaire ou disponibles pour réassignation
        $availableVehicles = Vehicle::where('is_active', true)
            ->whereDoesntHave('activeVehicleContract')
            ->get(['id', 'vehicle_number', 'vehicle_type']);

        return view('pages.admin.owners.create', compact('users', 'availableVehicles'));
    }

    public function store(Request $request)
    {
        $rules = [
            'name'     => 'required|string|max:255',
            // Unicité sur TOUS les comptes, comme la contrainte en base : elle n'était
            // vérifiée que parmi les `profil=client` (corrigé le 2026-09-25).
            'email'    => 'nullable|email|unique:users,email',
            'phone'    => 'required|string|unique:users,phone',
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&]/',
            ],
            'adresse'  => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ];

        if (isset($request->vehicle_id) && $request->vehicle_id != null) {
            $rules['vehicle_id'] = 'nullable|exists:vehicles,id';
            $rules['contract_start_date'] = 'required_with:vehicle_id|date';
            $rules['contract_total_amount'] = 'required_with:vehicle_id|numeric|min:0';
            $rules['contract_months'] = 'required_with:vehicle_id|integer|min:1';
            $rules['unlimited_internet'] = 'required_with:vehicle_id|numeric|min:0';
            $rules['spotify_premium'] = 'required_with:vehicle_id|numeric|min:0';
            $rules['manager_remuneration'] = 'required_with:vehicle_id|numeric|min:0';
        }

        if (isset($request->new_vehicle_number) && $request->new_vehicle_number != null) {
            $rules['new_vehicle_number'] = 'required|string|max:255|unique:vehicles,vehicle_number';
            $rules['new_vehicle_type'] = 'required|string|max:255';
            $rules['new_vehicle_notes'] = 'nullable|string|max:255';
            $rules['contract_start_date'] = 'required_with:new_vehicle_number|date';
            $rules['contract_total_amount'] = 'required_with:new_vehicle_number|numeric|min:0';
            $rules['contract_months'] = 'required_with:new_vehicle_number|integer|min:1';
            $rules['unlimited_internet'] = 'required_with:new_vehicle_number|numeric|min:0';
            $rules['spotify_premium'] = 'required_with:new_vehicle_number|numeric|min:0';
            $rules['manager_remuneration'] = 'required_with:new_vehicle_number|numeric|min:0';
        }

        $validated = $request->validate($rules, [
            'name.required'   => 'Le nom est obligatoire.',
            'email.unique'    => 'Cette adresse email est déjà utilisée.',
            'phone.required'  => 'Le téléphone est obligatoire.',
            'phone.unique'    => 'Ce numéro est déjà utilisé.',
            'password.min'    => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.regex'  => 'Le mot de passe doit contenir au moins une majuscule, un chiffre et un caractère spécial (@$!%*#?&).',
            'vehicle_id.exists' => 'Le véhicule sélectionné n\'existe pas.',
            'contract_start_date.required_with' => 'La date de début du contrat est obligatoire si un véhicule est sélectionné.',
            'contract_start_date.date' => 'La date de début du contrat doit être une date valide.',
            'contract_total_amount.required_with' => 'Le montant total du contrat est obligatoire si un véhicule est sélectionné.',
            'contract_total_amount.numeric' => 'Le montant total du contrat doit être un nombre.',
            'contract_months.required_with' => 'La durée du contrat en mois est obligatoire si un véhicule est sélectionné.',
            'contract_months.integer' => 'La durée du contrat en mois doit être un entier.',
            'contract_months.min' => 'La durée du contrat en mois doit être un nombre positif.',
            'unlimited_internet.required_with' => 'Le coût de l\'internet illimité est obligatoire si un véhicule est sélectionné.',
            'unlimited_internet.numeric' => 'Le coût de l\'internet illimité doit être un nombre.',
            'spotify_premium.required_with' => 'Le coût de Spotify Premium est obligatoire si un véhicule est sélectionné.',
            'spotify_premium.numeric' => 'Le coût de Spotify Premium doit être un nombre.',
            'manager_remuneration.required_with' => 'La rémunération du manager est obligatoire si un véhicule est sélectionné.',
            'manager_remuneration.numeric' => 'La rémunération du manager doit être un nombre.',
            'new_vehicle_number.unique' => 'Ce numéro de véhicule est déjà utilisé.',
            'new_vehicle_number.required' => 'Le numéro du nouveau véhicule est obligatoire si vous souhaitez créer un nouveau véhicule.',
            'new_vehicle_type.required' => 'Le type du nouveau véhicule est obligatoire si vous souhaitez créer un nouveau véhicule.',
            'new_vehicle_notes.string' => 'Les notes du nouveau véhicule doivent être une chaîne de caractères.',
        ]);

        try {
            $this->ownerService->create($validated);

            return redirect()->route('admin.owners.index')->with('success', 'Propriétaire créé avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur création propriétaire : ' . $e->getMessage());
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(User $owner)
    {
        $owner->load('vehicles.activeVehicleContract', 'vehicles.activeDriverContract.driver.user');
        // Véhicules sans propriétaire ou disponibles pour réassignation
        $availableVehicles = Vehicle::where('is_active', true)
            ->whereDoesntHave('activeVehicleContract')
            ->orWhere('owner_id', $owner->id) // Inclure le véhicule actuel du propriétaire
            ->get(['id', 'vehicle_number', 'vehicle_type']);

        return view('pages.admin.owners.edit', compact('owner', 'availableVehicles'));
    }

    public function update(Request $request, User $owner)
    {
        $addMode = $request->input('_add_vehicle_mode');

        // ── Règles communes ──────────────────────────────────
        $rules = [
            'name'      => 'required|string|max:255',
            'email'     => 'nullable|email|unique:users,email,' . $owner->id,
            'phone'     => 'required|string|unique:users,phone,' . $owner->id,
            'adresse'   => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'role'      => 'nullable|exists:roles,name',

            // Véhicules existants du proprio
            'vehicles.*.vehicle_number'              => 'nullable|string',
            'vehicles.*.vehicle_type'                => 'nullable|in:moto,tricycle,car',
            'vehicles.*.vehicle_notes'               => 'nullable|string|max:255',
            'vehicles.*.contract_months'             => 'nullable|in:24,30,36,other',
            'vehicles.*.contract_months_other'       => 'nullable|integer|min:1',
            'vehicles.*.contract_total_amount'       => 'nullable|numeric|min:1',
            'vehicles.*.contract_start_date'         => 'nullable|date',
            'vehicles.*.unlimited_internet'          => 'nullable|numeric|min:0',
            'vehicles.*.spotify_premium'             => 'nullable|numeric|min:0',
            'vehicles.*.manager_remuneration'        => 'nullable|numeric|min:0',
        ];

        // ── Règles selon le mode d'ajout de véhicule ────────
        if ($addMode === 'new') {
            $rules['new_vehicle_number']                      = 'nullable|string|unique:vehicles,vehicle_number';
            $rules['new_vehicle_type']                        = 'nullable|in:moto,tricycle,car';
            $rules['new_vehicle_notes']                       = 'nullable|string|max:255';
            $rules['new.contract_months']                     = 'nullable|in:24,30,36,other';
            $rules['new.contract_months_other']               = 'nullable|integer|min:1';
            $rules['new.contract_total_amount']               = 'nullable|numeric|min:1';
            $rules['new.contract_start_date']                 = 'nullable|date';
            $rules['new.contract_end_date']                   = 'nullable|date|after:new.contract_start_date';
            $rules['new.unlimited_internet']                  = 'nullable|numeric|min:0';
            $rules['new.spotify_premium']                     = 'nullable|numeric|min:0';
            $rules['new.manager_remuneration']                = 'nullable|numeric|min:0';
        } elseif ($addMode === 'existing') {
            $rules['vehicle_id']                              = 'nullable|exists:vehicles,id';
            $rules['existing_vehicle.contract_months']        = 'nullable|in:24,30,36,other';
            $rules['existing_vehicle.contract_months_other']  = 'nullable|integer|min:1';
            $rules['existing_vehicle.contract_total_amount']  = 'nullable|numeric|min:1';
            $rules['existing_vehicle.contract_start_date']    = 'nullable|date';
            $rules['existing_vehicle.contract_end_date']      = 'nullable|date|after:existing_vehicle.contract_start_date';
            $rules['existing_vehicle.unlimited_internet']     = 'nullable|numeric|min:0';
            $rules['existing_vehicle.spotify_premium']        = 'nullable|numeric|min:0';
            $rules['existing_vehicle.manager_remuneration']   = 'nullable|numeric|min:0';
        }

        $validated = $request->validate($rules, [
            'name.required'                   => 'Le nom est obligatoire.',
            'phone.unique'                  => 'Ce numéro est déjà utilisé.',
            'email.unique'                  => 'Cet email est déjà utilisé.',
            'new_vehicle_number.unique'     => 'Ce numéro de véhicule existe déjà.',
            'new.contract_end_date.after'   => 'La date de fin doit être après la date de début.',
            'existing_vehicle.contract_end_date.after' => 'La date de fin doit être après la date de début.',
            'new.contract_start_date.date' => 'La date de début doit être une date valide.',
            'existing_vehicle.contract_start_date.date' => 'La date de début doit être une date valide.',
            'new.contract_total_amount.numeric' => 'Le montant total doit être un nombre.',
            'existing_vehicle.contract_total_amount.numeric' => 'Le montant total doit être un nombre.',
            'new.contract_months_other.integer' => 'La durée du contrat doit être un entier.',
            'existing_vehicle.contract_months_other.integer' => 'La durée du contrat doit être un entier.',
            'new.unlimited_internet.numeric' => 'Le coût de l\'internet illimité doit être un nombre.',
            'existing_vehicle.unlimited_internet.numeric' => 'Le coût de l\'internet illimité doit être un nombre.',
            'new.spotify_premium.numeric' => 'Le coût de Spotify Premium doit être un nombre.',
            'existing_vehicle.spotify_premium.numeric' => 'Le coût de Spotify Premium doit être un nombre.',
            'new.manager_remuneration.numeric' => 'La rémunération du manager doit être un nombre.',
            'existing_vehicle.manager_remuneration.numeric' => 'La rémunération du manager doit être un nombre.',
            'new.contract_months_other.min' => 'La durée du contrat doit être un entier positif.',
            'existing_vehicle.contract_months_other.min' => 'La durée du contrat doit être un entier positif.',
        ]);
        try {
            $this->ownerService->update($owner, array_merge($validated, ['_add_vehicle_mode' => $addMode,]));

            return back()->with('success', 'Propriétaire mis à jour avec succès.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }
}
