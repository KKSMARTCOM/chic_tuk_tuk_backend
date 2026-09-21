<?php

namespace App\Services;

use App\Consts\VehicleContractConsts;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * Créer un paiement
     */
    public function create(array $data)
    {
        $driver = Driver::with('activeDriverContract')->findOrFail($data['driver_id']);

        if ($driver->activeDriverContract) {
            $data['driver_contract_id'] = $driver->activeDriverContract->id;
            $data['vehicle_contract_id'] = $driver->activeDriverContract->vehicle_contract_id;
        }

        $this->validatePaymentData($data);

        $payment = Payment::create([
            'driver_id'            => $data['driver_id'],
            'payment_type'         => $data['payment_type'] ?? 'commission',
            'amount'               => $data['amount'],
            'payment_month'        => $data['payment_month'] ?? null,
            'payment_method'       => $data['payment_method'],
            'payment_date'         => $data['payment_date'],
            'notes'                => $data['notes'] ?? null,
            'reference_number'     => $data['reference_number'] ?? null,
            'status'               => 'completed',
            'vehicle_contract_id'  => $data['vehicle_contract_id'] ?? null,
            'driver_contract_id'   => $data['driver_contract_id'] ?? null,
            'net_amount'         => $data['net_amount'] ?? null,
        ]);

        return $payment;
    }

    /**
     * Récupérer tous les paiements avec filtres
     */
    public function getAllPayments($filters = [])
    {
        $query = Payment::query()->with(['driver.user'])->latest('payment_date');

        if (isset($filters['driver_id']) && !empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        if (isset($filters['status']) && !empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['payment_type']) && !empty($filters['payment_type'])) {
            $query->where('payment_type', $filters['payment_type']);
        }

        if (isset($filters['search']) && !empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('driver.user', function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
            })->orWhere('reference_number', 'like', '%' . $search . '%');
        }

        if (isset($filters['date_from']) && !empty($filters['date_from'])) {
            $query->whereDate('payment_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to']) && !empty($filters['date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['date_to']);
        }

        return $query->latest()->get();
    }

    /**
     * Obtenir les statistiques des paiements
     */
    public function getPaymentStats()
    {
        $validatedCommissionAmount = Payment::where('payment_type', 'commission')
            ->where('status', 'completed')
            ->sum('amount');
        $totalCommissionCount = Payment::where('payment_type', 'commission')
            ->where('status', 'completed')
            ->count();
        $totalDue = Commission::where('status', 'active')->sum('amount');
        $totalPaidThisMonth = Payment::where('payment_type', 'commission')
            ->whereMonth('payment_date', Carbon::now()->month)
            ->whereYear('payment_date', Carbon::now()->year)
            ->where('status', 'completed')
            ->sum('amount');

        $validatedPaymentsCount = Payment::where('payment_type', 'contract')
            ->where('status', 'completed')
            ->count();
        $validatedPaymentsAmount = Payment::where('payment_type', 'contract')
            ->where('status', 'completed')
            ->sum('amount');
        $pendingPaymentsCount = Payment::where('payment_type', 'contract')
            ->where('status', 'pending')
            ->count();
        $pendingPaymentsAmount = Payment::where('payment_type', 'contract')
            ->where('status', 'pending')
            ->sum('amount');
        $cancelledPaymentsAmount = Payment::where('payment_type', 'contract')
            ->where('status', 'cancelled')
            ->sum('amount');
        $cancelledPaymentsCount = Payment::where('payment_type', 'contract')
            ->where('status', 'cancelled')
            ->count();

        return [
            // Type = commission
            'total_paid' => $validatedCommissionAmount,
            'total_paid_commission' => $validatedCommissionAmount,
            'total_due' => $totalDue,
            'balance_due' => $totalDue - $validatedCommissionAmount,
            'paid_this_month' => $totalPaidThisMonth,
            'payments_count' => $totalCommissionCount,

            // Type = contrat
            'validated_payments_amount' => $validatedPaymentsAmount,
            'validated_payments_count' => $validatedPaymentsCount,
            'pending_payments_amount' => $pendingPaymentsAmount,
            'pending_payments_count' => $pendingPaymentsCount,
            'cancelled_payments_amount' => $cancelledPaymentsAmount,
            'cancelled_payments_count' => $cancelledPaymentsCount,
        ];
    }

    /**
     * Obtenir les paiements d'un conducteur
     */
    public function getDriverPayments(string $driverId)
    {
        $driver = Driver::with('user')->findOrFail($driverId);

        $totalDue = $driver->commissions()->where('status', 'active')->sum('amount');
        $totalPaid = $driver->payments()->where('payment_type', 'commission')->where('status', 'completed')->sum('amount');
        $balanceDue = $totalDue - $totalPaid;

        return [
            'driver' => $driver,
            'total_due' => $totalDue,
            'total_paid' => $totalPaid,
            'balance_due' => $balanceDue,
            'payments_count' => $driver->payments()->count(),
            'commissions_count' => $driver->commissions()->count(),
        ];
    }

    /**
     * Mettre à jour un paiement
     */
    public function update(string $paymentId, array $data)
    {
        $payment = Payment::findOrFail($paymentId);

        $driver = Driver::with('activeDriverContract')->findOrFail($data['driver_id']);

        if ($driver->activeDriverContract) {
            $data['driver_contract_id'] = $driver->activeDriverContract->id;
            $data['vehicle_contract_id'] = $driver->activeDriverContract->vehicle_contract_id;
        }

        $this->validatePaymentData($data, $paymentId);

        $payment->update([
            'driver_id' => $data['driver_id'],
            'payment_type' => $data['payment_type'] ?? 'commission',
            'amount' => $data['amount'],
            'vehicle_contract_id' => $data['vehicle_contract_id'] ?? null,
            'driver_contract_id' => $data['driver_contract_id'] ?? null,
            'payment_month' => $data['payment_month'] ?? null,
            'payment_method' => $data['payment_method'],
            'payment_date' => $data['payment_date'],
            'notes' => $data['notes'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'net_amount' => $data['net_amount'] ?? null,
        ]);

        return $payment;
    }

    /**
     * Valider les données de paiement selon le type
     */
    private function validatePaymentData(array &$data, ?string $existingPaymentId = null): void
    {
        $data['payment_type'] = $data['payment_type'] ?? 'commission';

        if ($data['payment_type'] === 'commission') {
            //$driver = Driver::findOrFail($data['driver_id']);
            $totalDue = Commission::where('driver_id', $data['driver_id'])->where('status', 'active')->sum('amount');
            $totalPaid = Payment::where('driver_id', $data['driver_id'])->where('payment_type', 'commission');

            if ($existingPaymentId) {
                $totalPaid->where('id', '!=', $existingPaymentId);
            }

            $totalPaid = $totalPaid->sum('amount');
            $remaining = $totalDue - $totalPaid;

            if ($data['amount'] > $remaining) {
                throw new \Exception(
                    "Le montant saisi ({$data['amount']}) dépasse la commission restante due ({$remaining})."
                );
            }

            return;
        }

        if ($data['payment_type'] === 'contract') {
            if (empty($data['vehicle_contract_id']) && empty($data['driver_contract_id'])) {
                throw new \Exception('Un paiement contractuel doit être lié à un contrat agent ou véhicule.');
            }

            if (!empty($data['driver_contract_id'])) {
                $driverContract = DriverContract::findOrFail($data['driver_contract_id']);

                if (empty($data['vehicle_contract_id'])) {
                    $data['vehicle_contract_id'] = $driverContract->vehicle_contract_id;
                }
            }

            if (!empty($data['vehicle_contract_id'])) {
                $vehicleContract = VehicleContract::findOrFail($data['vehicle_contract_id']);
                $contractPaid = Payment::where('vehicle_contract_id', $vehicleContract->id)->where('payment_type', 'contract');

                if ($existingPaymentId) {
                    $contractPaid->where('id', '!=', $existingPaymentId);
                }

                $contractPaid = $contractPaid->sum('amount');

                $remaining = max(0, (float) $vehicleContract->total_amount - $contractPaid);

                if ($data['amount'] > $remaining) {
                    throw new \Exception(
                        "Le montant saisi ({$data['amount']}) dépasse le solde restant du contrat véhicule ({$remaining})."
                    );
                }

                $contractMonths = (int) $vehicleContract->contract_months;

                $taxe = VehicleContractConsts::TAXE[$contractMonths] ?? 0;

                $data['net_amount'] = $data['amount'] - $taxe;
            }
        }
    }

    /**
     * Supprimer un paiement
     */
    public function delete(string $paymentId)
    {
        $payment = Payment::findOrFail($paymentId);
        $payment->delete();
        return true;
    }

    /**
     * Récupérer les commissions dues d'un conducteur (non payées)
     */
    public function getDriverDueCommissions(string $driverId)
    {
        return Commission::where('driver_id', $driverId)
            ->where('status', 'active')
            ->with(['booking'])
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * Génère les paiements journaliers sur contrat pour tous les agents actifs.
     * Retourne un tableau de résultats pour le logging dans la commande.
     */
    public function generateDailyContractPayments(?Carbon $date = null): array
    {
        $today  = $date ?? Carbon::today();
        $result = ['generated' => 0, 'skipped' => 0, 'errors' => []];

        $contracts = DriverContract::with(['driver', 'vehicleContract'])
            ->where('status', 'active')
            ->whereHas('vehicleContract', fn($q) => $q->where('status', 'active'))
            ->get();

        foreach ($contracts as $contract) {
            try {
                $this->generateDailyPaymentForContract($contract, $today)
                    ? $result['generated']++
                    : $result['skipped']++;
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'contract_id' => $contract->id,
                    'driver_id'   => $contract->driver_id,
                    'message'     => $e->getMessage(),
                ];
                Log::error('Erreur génération paiement journalier', [
                    'contract_id' => $contract->id,
                    'driver_id'   => $contract->driver_id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Génère le paiement journalier pour un contrat donné.
     * Retourne true si créé, false si ignoré (déjà existant ou données manquantes).
     */
    public function generateDailyPaymentForContract(DriverContract $contract, ?Carbon $date = null): bool
    {
        $today = $date ?? Carbon::today();

        // Ne pas générer le week-end (samedi=6, dimanche=0)
        if ($today->isWeekend()) {
            return false;
        }

        // Déjà généré aujourd'hui pour ce contrat
        $alreadyExists = Payment::where('driver_contract_id', $contract->id)
            ->where('payment_type', 'contract')
            ->whereDate('payment_date', $today)
            ->exists();
        if ($alreadyExists) return false;

        $vehicleContract = $contract->vehicle->activeVehicleContract;

        if (!$vehicleContract) return false;

        // Montant journalier
        $contractMonths = (int) $vehicleContract->contract_months;

        $dailyAmount = VehicleContractConsts::AMOUNTS[$contractMonths] ?? 0;

        $taxe = VehicleContractConsts::TAXE[$contractMonths] ?? 0;

        $netAmount = $dailyAmount - $taxe;

        DB::transaction(function () use ($contract, $vehicleContract, $dailyAmount, $today, $netAmount) {
            Payment::create([
                'driver_id'           => $contract->driver_id,
                'payment_type'        => 'contract',
                'vehicle_contract_id' => $vehicleContract->id,
                'driver_contract_id'  => $contract->id,
                'payment_month'       => $today->copy()->startOfMonth()->toDateString(),
                'payment_method'      => 'other',
                'net_amount'          => $netAmount,
                'amount'              => $dailyAmount,
                'payment_date'        => $today->toDateString(),
                'status'              => 'pending',
                'notes'               => "Paiement journalier auto — {$today->format('d/m/Y')}",
            ]);
        });

        return true;
    }
}
