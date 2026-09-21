<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class DriverLeaveController extends Controller
{
    /**
     * Show leave history and status
     */
    public function index()
    {
        $driver = Auth::user()->driver;

        if (!$driver) {
            return redirect()->route('driver.dashboard')->with('error', 'Profil Agent non trouvé.');
        }

        $pendingRequests = $driver->leaveRequests()->where('status', 'pending')->orderByDesc('created_at')->get();
        $ongoingLeave     = $driver->leaveRequests()->where('status', 'ongoing')->first();
        $history          = $driver->leaveRequests()->where('status', 'completed')->orderByDesc('start_date')->get();
        $rejectedRequests = $driver->leaveRequests()->where('status', 'rejected')->orderByDesc('created_at')->get();

        $leaveInfo = [
            'leave_days_per_month' => $driver->getLeaveDaysPerMonth(),
            'total_leave_days' => $driver->getTotalLeaveDays(),
            // Recompté depuis les pauses terminées : la colonne `leave_days_used` avait
                // dérivé de la réalité sur des agents existants.
                'leave_days_used' => $driver->getLeaveDaysTaken(),
            'available_leave_days' => $driver->available_leave_days,
            'remaining_leave_days' => $driver->getRemainingLeaveDays(),
        ];

        return view('pages.driver.leaves.index', compact(
            'driver',
            'leaveInfo',
            'pendingRequests',
            'ongoingLeave',
            'history',
            'rejectedRequests'
        ));
    }

    /**
     * Show the form to request leave
     */
    public function create()
    {
        $driver = Auth::user()->driver;

        if (!$driver) {
            return redirect()->route('driver.dashboard')->with('error', 'Profil Agent non trouvé.');
        }

        $leaveInfo = [
            'available_leave_days' => $driver->available_leave_days,
            'remaining_leave_days' => $driver->getRemainingLeaveDays(),
        ];

        $pendingRequests  = $driver->leaveRequests()->where('status', 'pending')->orderByDesc('created_at')->get();
        $ongoingLeave     = $driver->leaveRequests()->where('status', 'ongoing')->first();
        $rejectedRequests = $driver->leaveRequests()->where('status', 'rejected')->orderByDesc('created_at')->limit(3)->get();

        $canRequest = $pendingRequests->isEmpty() && !$ongoingLeave;

        return view('pages.driver.leaves.create', compact(
            'driver',
            'leaveInfo',
            'canRequest',
            'pendingRequests',
            'ongoingLeave',
            'rejectedRequests'
        ));
    }

    /**
     * Store a leave request
     */
    public function store(Request $request)
    {
        $driver = Auth::user()->driver;

        $contract = $driver->activeDriverContract;

        if (!$driver) {
            return redirect()->route('driver.dashboard')->with('error', 'Profil Agent non trouvé.');
        }

        if (!$contract) {
            return redirect()->back()->with('error', "Vous devez être sous contrat actif pour demander une pause.");
        }

        $request->validate([
            'start_date' => 'required|date|after_or_equal:tomorrow',
            'requested_days' => 'required|integer|min:1',
        ], [
            'start_date.after_or_equal' => "La pause doit être demandée au moins 24 heures à l'avance.",
        ]);

        $start = Carbon::parse($request->start_date)->startOfDay();

        if ($start->lt(Carbon::parse($contract->start_date)->startOfDay())) {
            return redirect()->back()->with('error', "La date de début de la pause ne peut pas être antérieure à la date de début du contrat (" . Carbon::parse($contract->start_date)->format('d/m/Y') . ").");
        }

        if ($driver->leaveRequests()->whereIn('status', ['pending', 'ongoing'])->exists()) {
            return redirect()->back()->with('error', 'Vous avez déjà une demande en attente ou une pause en cours.');
        }

        LeaveRequest::create([
            'driver_id' => $driver->id,
            'driver_contract_id' => $driver->activeDriverContract?->id,
            'start_date' => $request->start_date,
            'requested_days' => $request->requested_days,
            'status' => 'pending',
            'source' => 'driver_request',
        ]);

        return redirect()->back()->with('success', "Demande de pause soumise. L'administrateur l'examinera bientôt.");
    }
}
