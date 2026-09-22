<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Workforce\Application\Actions\AddHistoricalLeave;
use App\Domains\Workforce\Application\Actions\AddOngoingLeave;
use App\Domains\Workforce\Application\Actions\ApproveLeaveRequest;
use App\Domains\Workforce\Application\Actions\DeleteLeave;
use App\Domains\Workforce\Application\Actions\EndLeave;
use App\Domains\Workforce\Application\Actions\RejectLeaveRequest;
use App\Domains\Workforce\Application\Actions\UpdateHistoricalLeave;
use App\Domains\Workforce\Application\Actions\UpdateOngoingLeave;
use App\Shared\Http\ApiException;
use App\Domains\Notification\Application\Notifier;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class LeaveController extends Controller
{
    /*
     * ⚠️ `VehicleService` n'est plus injecté ici depuis le 2026-09-22 : les pauses
     * véhicule sont créées, clôturées et corrigées par les actions du domaine Workforce,
     * qui portent désormais toute la logique d'écriture. Le garder aurait laissé croire
     * que ce contrôleur fait encore quelque chose du parc.
     */

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
    /**
     * ⚠️ Le corps vit dans `ApproveLeaveRequest` depuis le 2026-09-22 : une seule
     * implémentation pour le chemin Blade et pour l'API, comme les cinq écritures de
     * course du sous-lot 3a. Les refus sont des `ApiException` portant les messages
     * d'origine mot pour mot — on les rend ici en flash, à l'identique.
     */
    public function approveRequest(LeaveRequest $leaveRequest)
    {
        try {
            app(ApproveLeaveRequest::class)($leaveRequest);
        } catch (ApiException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Demande de Pause approuvée avec succès.');
    }

    /**
     * Reject a leave request
     */
    public function rejectRequest(Request $request, LeaveRequest $leaveRequest)
    {
        $request->validate(['rejection_reason' => 'required|string|min:5']);

        try {
            app(RejectLeaveRequest::class)($leaveRequest, $request->rejection_reason);
        } catch (ApiException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Demande de Pause rejetée avec succès.');
    }

    public function addOngoingLeave(Request $request, string $id)
    {
        $request->validate([
            'start_date' => 'required|date',
            'requested_days' => 'required|integer|min:1',
        ]);

        try {
            app(AddOngoingLeave::class)(
                Driver::findOrFail($id),
                $request->start_date,
                (int) $request->requested_days,
                (string) Auth::id(),
            );
        } catch (ApiException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Pause ajoutée et activée avec succès.');
    }

    /**
     * Ajout admin : pause historique (déjà terminée)
     */
    public function addHistoricalLeave(Request $request, string $id)
    {
        $request->validate([
            'start_date' => 'required|date|before:today',
            'requested_days' => 'required|integer|min:1',
        ]);

        try {
            app(AddHistoricalLeave::class)(
                Driver::findOrFail($id),
                $request->start_date,
                (int) $request->requested_days,
                (string) Auth::id(),
            );
        } catch (ApiException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Pause historique ajoutée avec succès.');
    }

    /**
     * Mettre fin à une pause "ongoing" (instant admin ou demande agent approuvée)
     */
    public function endLeave(Request $request, LeaveRequest $leaveRequest)
    {
        $request->validate(['end_date' => 'required|date']);

        try {
            $cloturee = app(EndLeave::class)($leaveRequest, $request->end_date);
        } catch (ApiException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Pause terminée. Jours effectifs : {$cloturee->effective_days}.");
    }

    /**
     * Modifier une pause historique mal saisie
     */
    public function updateHistoricalLeave(Request $request, LeaveRequest $leaveRequest)
    {
        $request->validate([
            'start_date' => 'required|date|before:today',
            'requested_days' => 'required|integer|min:1',
        ]);

        try {
            app(UpdateHistoricalLeave::class)($leaveRequest, $request->start_date, (int) $request->requested_days);
        } catch (ApiException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Pause historique modifiée avec succès.');
    }

    /**
     * Supprimer une pause historique mal saisie
     */
    public function destroyHistoricalLeave(LeaveRequest $leaveRequest)
    {
        try {
            app(DeleteLeave::class)($leaveRequest);
        } catch (ApiException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Pause historique supprimée avec succès.');
    }

    public function updateOngoingLeave(Request $request, LeaveRequest $leaveRequest)
    {
        $request->validate([
            'start_date' => 'required|date',
            'requested_days' => 'required|integer|min:1',
        ]);

        try {
            app(UpdateOngoingLeave::class)($leaveRequest, $request->start_date, (int) $request->requested_days);
        } catch (ApiException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Pause en cours corrigée avec succès.');
    }
}
