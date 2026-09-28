<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminPaymentData;
use App\Domains\Finance\Application\Data\AdminPaymentDriverOptionData;
use App\Domains\Finance\Application\Data\AdminPaymentPageData;
use App\Domains\Finance\Application\Data\AdminPaymentStatsData;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\Payment;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** La liste des paiements — ex-Admin\PaymentController::index(). */
final class ListPayments
{
    /**
     * @param  array<string, mixed>  $params  `filter[driver_id|status|payment_type|search|date_from|date_to]`,
     *                                         `sort` (amount, payment_date, created_at), `page`, `per_page`
     */
    public function __invoke(array $params = []): AdminPaymentPageData
    {
        $page = ListQuery::paginate($this->query($params), $params);
        $payments = collect($page->items());

        return new AdminPaymentPageData(
            payments: $payments->map(fn (Payment $payment) => AdminPaymentData::fromModel($payment))->all(),
            pagination: PaginationData::fromPaginator($page),
            stats: AdminPaymentStatsData::fromStats($this->getPaymentStats()),
            drivers: Driver::with('user')->get()
                ->sortBy(fn (Driver $driver) => $driver->user?->name)
                ->map(fn (Driver $driver) => AdminPaymentDriverOptionData::fromModel($driver))
                ->values()
                ->all(),
        );
    }

    /**
     * Les paiements filtrés et triés — paginés côté serveur depuis le 2026-09-28 : les
     * paiements journaliers s'accumulent sans fin, et tout renvoyer aurait fini par
     * charger des milliers de lignes en un appel.
     */
    private function query(array $params): QueryBuilder
    {
        return ListQuery::build(Payment::query()->with(['driver.user', 'vehicleContract.vehicle', 'driverContract.vehicle']), $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                ListQuery::exact('driver_id'),
                ListQuery::exact('status'),
                ListQuery::exact('payment_type'),
                // Groupé (2026-09-26) : sans parenthèses, le `orWhere` sur la référence
                // échappait à tous les autres filtres — agent, statut, type, dates.
                ListQuery::search(fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                    ->whereHas('driver.user', fn ($u) => $u->where('name', 'ilike', '%'.$search.'%'))
                    ->orWhere('reference_number', 'ilike', '%'.$search.'%'))),
                AllowedFilter::callback('date_from', fn (Builder $q, $date) => $q->whereDate('payment_date', '>=', $date))->ignore(''),
                AllowedFilter::callback('date_to', fn (Builder $q, $date) => $q->whereDate('payment_date', '<=', $date))->ignore(''),
            ])
            ->allowedSorts(['amount', 'payment_date', 'created_at'])
            // Le tri du Blade : date du paiement, puis date d'enregistrement.
            ->defaultSort('-payment_date', '-created_at'));
    }

    /**
     * Obtenir les statistiques des paiements
     */
    private function getPaymentStats()
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
}
