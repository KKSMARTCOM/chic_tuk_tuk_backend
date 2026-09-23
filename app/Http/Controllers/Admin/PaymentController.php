<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Afficher la liste des paiements
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['driver_id', 'status', 'payment_type', 'search', 'date_from', 'date_to']);
            $payments = $this->paymentService->getAllPayments($filters);
            $stats = $this->paymentService->getPaymentStats();
            $drivers = Driver::with('user')->orderBy('id')->get();

            return view('pages.admin.payments.index', compact('payments', 'stats', 'drivers'));
        } catch (\Exception $e) {
            Log::error('Erreur lors de l’affichage des paiements : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Afficher le formulaire de création de paiement
     */
    public function create()
    {
        try {
            // Récupérer uniquement les agents qui ont un contrat actif
            $drivers = Driver::whereHas('driverContracts', function ($query) {
                $query->where('status', 'active');
            })->with('user', 'activeDriverContract', 'currentVehicle.activeVehicleContract')->orderBy('id')->get();

            return view('pages.admin.payments.create', compact('drivers'));
        } catch (\Exception $e) {
            Log::error('Erreur lors de l’affichage du formulaire de paiement : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Créer un nouveau paiement
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'driver_id' => 'required|exists:drivers,id',
                'payment_type' => 'required|in:commission,contract,subscription_revenue',
                'amount'           => 'required|numeric|min:0.01',
                'payment_method' => 'required|in:cash,bank_transfer,check,mobile_money,other',
                'payment_date' => 'required|date',
                'notes' => 'nullable|string|max:500',
                'reference_number' => 'nullable|string|max:100|unique:payments',
            ], [
                'driver_id.required' => 'L\'agent est obligatoire.',
                'driver_id.exists' => 'L\'agent sélectionné est invalide.',
                'payment_type.required' => 'Le type de paiement est obligatoire.',
                'payment_type.in' => 'Le type de paiement sélectionné est invalide.',
                'amount.required' => 'Le montant est obligatoire.',
                'amount.numeric' => 'Le montant doit être un nombre.',
                'amount.min' => 'Le montant doit être supérieur à 0.',
                'payment_method.required' => 'La méthode de paiement est obligatoire.',
                'payment_method.in' => 'La méthode de paiement sélectionnée est invalide.',
                'payment_date.required' => 'La date de paiement est obligatoire.',
                'payment_date.date' => 'La date de paiement n\'est pas valide.',
                'notes.string' => 'Les notes doivent être une chaîne de caractères.',
                'notes.max' => 'Les notes ne doivent pas dépasser 500 caractères.',
                'reference_number.string' => 'Le numéro de référence doit être une chaîne de caractères.',
                'reference_number.max' => 'Le numéro de référence ne doit pas dépasser 100 caractères.',
                'reference_number.unique' => 'Le numéro de référence existe déjà pour un autre paiement.',
            ]);

            $this->paymentService->create($validated);

            return redirect()->route('admin.payments.index')->with('success', 'Paiement enregistré avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du paiement : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Afficher les détails d'un paiement
     */
    public function show(Payment $payment)
    {
        try {
            $payment->load(['driver.user']);
            $driverStats = $this->paymentService->getDriverPayments($payment->driver_id);

            return view('pages.admin.payments.show', compact('payment', 'driverStats'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Afficher le formulaire d'édition
     */
    public function edit(Payment $payment)
    {
        try {
            $drivers = Driver::whereHas('driverContracts', function ($query) {
                $query->where('status', 'active');
            })->with('user', 'activeDriverContract', 'currentVehicle.activeVehicleContract')->orderBy('id')->get();
            $driverContracts = DriverContract::with('driver.user', 'vehicle')->latest()->get();
            $vehicleContracts = VehicleContract::with('vehicle', 'owner')->latest()->get();

            return view('pages.admin.payments.edit', compact('payment', 'drivers', 'driverContracts', 'vehicleContracts'));
        } catch (\Exception $e) {
            Log::error('Erreur lors de l’affichage du paiement : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Mettre à jour un paiement
     */
    public function update(Request $request, Payment $payment)
    {
        try {
            $validated = $request->validate([
                'driver_id' => 'required|exists:drivers,id',
                'amount' => 'required|numeric|min:0.01',
                'payment_method' => 'required|in:cash,bank_transfer,check,mobile_money,other',
                'payment_date' => 'required|date',
                'notes' => 'nullable|string|max:500',
                'reference_number' => 'nullable|string|max:100|unique:payments,reference_number,' . $payment->id,
                'status' => 'nullable|in:pending,completed,cancelled',
            ], [
                'driver_id.required' => 'L\'agent est obligatoire.',
                'driver_id.exists' => 'L\'agent sélectionné est invalide.',
                'amount.required' => 'Le montant est obligatoire.',
                'amount.numeric' => 'Le montant doit être un nombre.',
                'amount.min' => 'Le montant doit être supérieur à 0.',
                'payment_method.required' => 'La méthode de paiement est obligatoire.',
                'payment_method.in' => 'La méthode de paiement sélectionnée est invalide.',
                'payment_date.required' => 'La date de paiement est obligatoire.',
                'payment_date.date' => 'La date de paiement n\'est pas valide.',
                'notes.string' => 'Les notes doivent être une chaîne de caractères.',
                'notes.max' => 'Les notes ne doivent pas dépasser 500 caractères.',
                'reference_number.string' => 'Le numéro de référence doit être une chaîne de caractères.',
                'reference_number.max' => 'Le numéro de référence ne doit pas dépasser 100 caractères.',
                'reference_number.unique' => 'Le numéro de référence existe déjà pour un autre paiement.',
            ]);

            $this->paymentService->update($payment->id, $validated);

            return redirect()->route('admin.payments.show', $payment)->with('success', 'Paiement mis à jour avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du paiement : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Supprimer un paiement
     */
    public function destroy(Payment $payment)
    {
        try {
            $this->paymentService->delete($payment->id);

            return redirect()->route('admin.payments.index')->with('success', 'Paiement supprimé avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du paiement : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Afficher les détails de paiement d'un conducteur
     */
    public function driverPaymentDetails(string $driverId)
    {
        try {
            $driverStats = $this->paymentService->getDriverPayments($driverId);
            $payments = Payment::where('driver_id', $driverId)
                ->latest('payment_date')
                ->paginate(15);
            $commissions = $this->paymentService->getDriverDueCommissions($driverId);

            return view('pages.admin.payments.driver-details', compact('driverStats', 'payments', 'commissions'));
        } catch (\Exception $e) {
            Log::error('Erreur lors de l’affichage des détails de paiement : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function validate(Payment $payment)
    {
        try {
            $payment->update(['status' => 'completed']);
            return back()->with('success', 'Paiement validé avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la validation du paiement : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Payment $payment)
    {
        try {
            $payment->update(['status' => 'cancelled']);
            return back()->with('success', 'Paiement annulé avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de l’annulation du paiement : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function generatePayment(Payment $payment)
    {
        try {
            $contract = $payment->driverContract;
            $this->paymentService->generateDailyPaymentForContract($contract);
            return back()->with('success', 'Paiement généré avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la génération du paiement : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
