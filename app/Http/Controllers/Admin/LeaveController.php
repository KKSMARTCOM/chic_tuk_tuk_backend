<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Notification\Application\Notifier;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\VehicleService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class LeaveController extends Controller
{
    protected $vehicleService;

    public function __construct(VehicleService $vehicleService)
    {
        $this->vehicleService = $vehicleService;
    }

    /**
     * Display all drivers with their leave information
     */
    public function index(Request $request)
    {
        $query = User::where('profil', 'driver')->with('driver');

        // Filter by search (name)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Filter by contract duration
        if ($request->filled('contract')) {
            $contract = $request->input('contract');
            $query->whereHas('driver', function ($q) use ($contract) {
                $q->where('contract_type', (int) $contract);
            });
        }

        // Récupérer les agents qui ont un contrat actif
        $query->whereHas('driver', function ($q) {
            $q->whereHas('activeDriverContract');
        });

        $allUsers = $query->get();

        $drivers = $allUsers->map(function ($user) {
            $driver = $user->driver;
            $ongoing = $driver->leaveRequests()->where('status', 'ongoing')->first();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'contract_type' => $driver->activeDriverContract->contract_months ?? null,
                'leave_days_per_month' => $driver->getLeaveDaysPerMonth(),
                'total_leave_days' => $driver->getTotalLeaveDays(),
                // Recompté depuis les pauses terminées : la colonne `leave_days_used` avait
                // dérivé de la réalité sur des agents existants.
                'leave_days_used' => $driver->getLeaveDaysTaken(),
                'available_leave_days' => $driver->available_leave_days,
                'remaining_leave_days' => $driver->getRemainingLeaveDays(),
                'is_on_leave' => (bool) $ongoing,
                'ongoing_since' => $ongoing?->start_date,
                'pending_requests' => $driver->leaveRequests()->where('status', 'pending')->count(),
            ];
        });

        // Filter by available days (PHP filtering after calculation)
        if ($request->filled('available')) {
            $available = $request->input('available');
            $drivers = $drivers->filter(function ($driver) use ($available) {
                if ($available === 'yes') {
                    return $driver['available_leave_days'] > 0;
                } elseif ($available === 'no') {
                    return $driver['available_leave_days'] <= 0;
                }
                return true;
            });
        }

        // Filter by pending requests (PHP filtering)
        if ($request->filled('pending')) {
            $pending = $request->input('pending');
            $drivers = $drivers->filter(function ($driver) use ($pending) {
                if ($pending === 'yes') {
                    return $driver['pending_requests'] > 0;
                } elseif ($pending === 'no') {
                    return $driver['pending_requests'] === 0;
                }
                return true;
            });
        }

        // Return all results for DataTable (no pagination)
        $drivers = $drivers->values();

        return view('pages.admin.leaves.index', compact('drivers'));
    }

    /**
     * Show leave details and requests for a specific driver
     */
    public function show(User $driver)
    {
        $driver->load('driver');
        $driverModel = $driver->driver;

        $leaveInfo = [
            'leave_days_per_month' => $driverModel->getLeaveDaysPerMonth(),
            'total_leave_days' => $driverModel->getTotalLeaveDays(),
            'leave_days_used' => $driverModel->getLeaveDaysTaken(),
            'available_leave_days' => $driverModel->available_leave_days,
            'remaining_leave_days' => $driverModel->getRemainingLeaveDays(),
            'contract_start' => $driverModel->activeDriverContract->start_date ?? null,
            'contract_months' => $driverModel->activeDriverContract->contract_months ?? null,
        ];

        // Get pending and approved requests for this month
        $pendingRequests = $driverModel->leaveRequests()->where('status', 'pending')->orderBy('start_date')->get();
        $ongoingLeave     = $driverModel->leaveRequests()->where('status', 'ongoing')->first();
        $history          = $driverModel->leaveRequests()->where('status', 'completed')->orderByDesc('start_date')->get();

        return view('pages.admin.leaves.show', compact('driver', 'leaveInfo', 'pendingRequests', 'ongoingLeave', 'history'));
    }

    /**
     * Display pending leave requests for all drivers
     */
    public function requests()
    {
        $requests = LeaveRequest::with(['driver.user', 'driver.activeDriverContract'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('pages.admin.leaves.requests', compact('requests'));
    }

    /**
     * Approve a leave request
     */
    public function approveRequest(LeaveRequest $leaveRequest)
    {
        $driver = $leaveRequest->driver;

        if (!$driver->activeDriverContract) {
            return redirect()->back()->with('error', "Cet agent n'a pas de contrat actif. Impossible d'approuver la pause.");
        }

        if ($driver->hasOngoingLeave()) {
            return redirect()->back()->with('error', "L'agent a déjà une pause en cours.");
        }

        // Approve the request
        $leaveRequest->update([
            'status' => 'ongoing',
            'rejection_reason' => null,
        ]);

        $activeContract = $driver->activeDriverContract;
        if ($activeContract) {
            $pause = $this->vehicleService->createAutoAgentPause(
                $activeContract->vehicle_id,
                $activeContract->id,
                $leaveRequest->start_date->toDateString()
            );

            $leaveRequest->update(['vehicle_pause_id' => $pause->id]);
        }

        // Add leave dates to driver
        if ($leaveRequest->start_date->lte(now()->startOfDay())) {
            $driver->update(['is_available' => false]);
        }

        // L'agent est prévenu, pas les administrateurs : c'est l'un d'eux qui vient de
        // valider, et un accusé de réception de son propre geste serait du bruit.
        app(Notifier::class)->leaveApproved($leaveRequest->refresh());

        return redirect()->back()->with('success', 'Demande de Pause approuvée avec succès.');
    }

    /**
     * Reject a leave request
     */
    public function rejectRequest(Request $request, LeaveRequest $leaveRequest)
    {
        $request->validate([
            'rejection_reason' => 'required|string|min:5',
        ]);

        $leaveRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);

        // Le motif voyage avec la notification : sans lui, l'agent doit ouvrir l'écran
        // pour savoir pourquoi, et le refus paraît arbitraire.
        app(Notifier::class)->leaveRejected($leaveRequest->refresh());

        return redirect()->back()->with('success', 'Demande de Pause rejetée avec succès.');
    }

    /**
     * Add an instant leave directly for a driver
     */
    public function addOngoingLeave(Request $request, string $id)
    {
        $driver = Driver::findOrFail($id);

        $request->validate([
            'start_date' => 'required|date',
            'requested_days' => 'required|integer|min:1',
        ]);

        if ($driver->hasOngoingLeave()) {
            return redirect()->back()->with('error', "L'agent a déjà une pause en cours. Terminez-la d'abord.");
        }

        $activeContract = $driver->activeDriverContract;
        if (!$activeContract) {
            return redirect()->back()->with('error', 'Aucun contrat actif pour cet agent.');
        }

        $leaveRequest = LeaveRequest::create([
            'driver_id' => $driver->id,
            'driver_contract_id' => $activeContract->id,
            'start_date' => $request->start_date,
            'requested_days' => $request->requested_days,
            'status' => 'ongoing',
            'source' => 'admin_instant',
            'created_by' => Auth::user()->id,
        ]);

        $pause = $this->vehicleService->createAutoAgentPause(
            $activeContract->vehicle_id,
            $activeContract->id,
            $request->start_date
        );

        $leaveRequest->update(['vehicle_pause_id' => $pause->id]);

        if (Carbon::parse($request->start_date)->lte(now()->startOfDay())) {
            $driver->update(['is_available' => false]);
        }

        return redirect()->back()->with('success', 'Pause ajoutée et activée avec succès.');
    }

    /**
     * Ajout admin : pause historique (déjà terminée)
     */
    public function addHistoricalLeave(Request $request, string $id)
    {
        $driver = Driver::findOrFail($id);

        $request->validate([
            'start_date' => 'required|date|before:today',
            'requested_days' => 'required|integer|min:1',
        ]);

        $contract = $driver->activeDriverContract;

        $start = Carbon::parse($request->start_date)->startOfDay();

        if ($start->lt(Carbon::parse($contract->start_date)->startOfDay())) {
            return redirect()->back()->with('error', "La date de début de la pause ne peut pas être antérieure à la date de début du contrat (" . Carbon::parse($contract->start_date)->format('d/m/Y') . ").");
        }

        $end   = LeaveRequest::addBusinessDays($start, $request->requested_days);

        if ($end->gte(now()->startOfDay())) {
            return redirect()->back()->with('error', "Une pause historique doit être entièrement terminée avant aujourd'hui.");
        }

        LeaveRequest::create([
            'driver_id' => $driver->id,
            'driver_contract_id' => $driver->activeDriverContract?->id,
            'start_date' => $start->toDateString(),
            'requested_days' => $request->requested_days,
            'end_date' => $end->toDateString(),
            'effective_days' => $request->requested_days,
            'status' => 'completed',
            'source' => 'admin_historical',
            'created_by' => Auth::user()->id,
        ]);

        $driver->markLeaveDaysUsed($request->requested_days);

        return redirect()->back()->with('success', 'Pause historique ajoutée avec succès.');
    }

    /**
     * Mettre fin à une pause "ongoing" (instant admin ou demande agent approuvée)
     */
    public function endLeave(Request $request, LeaveRequest $leaveRequest)
    {
        if ($leaveRequest->status !== 'ongoing') {
            return redirect()->back()->with('error', "Cette pause n'est pas en cours.");
        }

        $request->validate(['end_date' => 'required|date']);

        $end = Carbon::parse($request->end_date)->startOfDay();
        if ($end->lt($leaveRequest->start_date)) {
            return redirect()->back()->with('error', 'La date de fin ne peut pas précéder la date de début.');
        }

        $effectiveDays = LeaveRequest::countBusinessDays($leaveRequest->start_date, $end);

        $leaveRequest->update([
            'end_date' => $end->toDateString(),
            'effective_days' => $effectiveDays,
            'status' => 'completed',
        ]);

        $driver = $leaveRequest->driver;
        $driver->markLeaveDaysUsed($effectiveDays);

        if ($leaveRequest->vehiclePause) {
            $this->vehicleService->endPause($leaveRequest->vehiclePause, $end->toDateString());
        }

        if (!$driver->hasOngoingLeave()) {
            $driver->update(['is_available' => true]);
        }

        return redirect()->back()->with('success', "Pause terminée. Jours effectifs : {$effectiveDays}.");
    }

    /**
     * Modifier une pause historique mal saisie
     */
    public function updateHistoricalLeave(Request $request, LeaveRequest $leaveRequest)
    {
        if (!in_array($leaveRequest->source, ['admin_historical', 'legacy']) || $leaveRequest->status !== 'completed') {
            return redirect()->back()->with('error', "Seules les pauses historiques peuvent être modifiées.");
        }

        $request->validate([
            'start_date' => 'required|date|before:today',
            'requested_days' => 'required|integer|min:1',
        ]);

        $start = Carbon::parse($request->start_date)->startOfDay();
        $end   = LeaveRequest::addBusinessDays($start, $request->requested_days);

        if ($end->gte(now()->startOfDay())) {
            return redirect()->back()->with('error', "Une pause historique doit rester entièrement terminée avant aujourd'hui.");
        }

        $driver = $leaveRequest->driver;

        // On retire l'ancien décompte avant d'appliquer le nouveau
        $driver->markLeaveDaysUsed(- ($leaveRequest->effective_days ?? 0));

        $leaveRequest->update([
            'start_date' => $start->toDateString(),
            'requested_days' => $request->requested_days,
            'end_date' => $end->toDateString(),
            'effective_days' => $request->requested_days,
        ]);

        $driver->markLeaveDaysUsed($request->requested_days);

        return redirect()->back()->with('success', 'Pause historique modifiée avec succès.');
    }

    /**
     * Supprimer une pause historique mal saisie
     */
    public function destroyHistoricalLeave(LeaveRequest $leaveRequest)
    {
        if (!in_array($leaveRequest->source, ['admin_historical', 'legacy']) || $leaveRequest->status !== 'completed') {
            return redirect()->back()->with('error', "Seules les pauses historiques peuvent être supprimées.");
        }

        $driver = $leaveRequest->driver;
        $driver->markLeaveDaysUsed(- ($leaveRequest->effective_days ?? 0));

        $leaveRequest->delete();

        return redirect()->back()->with('success', 'Pause historique supprimée avec succès.');
    }

    public function updateOngoingLeave(Request $request, LeaveRequest $leaveRequest)
    {
        if ($leaveRequest->status !== 'ongoing') {
            return redirect()->back()->with('error', "Seule une pause en cours peut être corrigée ici.");
        }

        $request->validate([
            'start_date' => 'required|date',
            'requested_days' => 'required|integer|min:1',
        ]);

        $driver = $leaveRequest->driver;
        $newStart = Carbon::parse($request->start_date)->startOfDay();

        $leaveRequest->update([
            'start_date' => $newStart->toDateString(),
            'requested_days' => $request->requested_days,
        ]);

        // Répercuter la correction sur la pause véhicule liée
        if ($leaveRequest->vehiclePause) {
            $this->vehicleService->correctPauseDates(
                $leaveRequest->vehiclePause,
                $newStart->toDateString()
            );
        }

        // Réévaluer la disponibilité de l'agent selon la nouvelle date
        if ($newStart->lte(now()->startOfDay())) {
            $driver->update(['is_available' => false]);
        } elseif ($driver->is_available === false && !$driver->hasOngoingLeave()) {
            // Cas limite improbable ici puisque la pause reste ongoing, gardé par sécurité
            $driver->update(['is_available' => true]);
        }

        return redirect()->back()->with('success', 'Pause en cours corrigée avec succès.');
    }
}
