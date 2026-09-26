<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Services\CommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CommissionController extends Controller
{
    protected $commissionService;

    public function __construct(CommissionService $commissionService)
    {
        $this->commissionService = $commissionService;
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->only(['driver_id', 'search']);
            $commissions = $this->commissionService->getAllCommissions($filters);
            $stats = $this->commissionService->getCommissionStats();

            return view('pages.admin.commissions.index', compact('commissions', 'stats'));
        } catch (\Exception $e) {
            Log::error('Erreur lors de l’affichage des commissions : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function show(Commission $commission)
    {
        try {
            $commission->load(['driver.user', 'booking']);
            return view('pages.admin.commissions.show', compact('commission'));
        } catch (\Exception $e) {
            Log::error('Erreur lors de l’affichage de la commission : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Commission $commission)
    {
        try {
            // Annulée et non plus supprimée (2026-09-26) : elle reste visible, et ne compte
            // plus dans ce que l'agent doit.
            $this->commissionService->cancelCommission($commission->id);
            return back()->with('success', 'Commission annulée avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de l’annulation de la commission : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
