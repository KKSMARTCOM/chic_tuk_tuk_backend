<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\OwnerPaymentListItemData;
use App\Domains\Finance\Application\Data\OwnerPaymentListPageData;
use App\Domains\Finance\Application\Data\OwnerPaymentTotalsData;
use App\Models\Payment;
use App\Models\Vehicle;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Les paiements d'un véhicule, un par un, pour son propriétaire (2026-10-07).
 *
 * Les paiements de CONTRAT des contrats de ce propriétaire sur ce véhicule : un véhicule
 * qui a changé de mains ne montre pas l'historique de l'ancien propriétaire. Payés et en
 * attente seulement — les annulés sont des corrections internes.
 *
 * La portée sur le VÉHICULE (le véhicule d'autrui en 404) est celle du contrôleur.
 */
final class ListOwnerVehiclePayments
{
    /**
     * @param  array<string, mixed>  $params  `filter[month|status|from|to]`, `page`, `per_page`
     */
    public function __invoke(Vehicle $vehicle, string $ownerId, array $params = []): OwnerPaymentListPageData
    {
        $query = $this->query($vehicle, $ownerId, $params);

        // Les totaux portent sur tout le filtre, avant découpe en pages.
        $totals = (clone $query)->getEloquentBuilder()->reorder()
            ->selectRaw("coalesce(sum(net_amount) filter (where status = 'completed'), 0) as paid")
            ->selectRaw("coalesce(sum(net_amount) filter (where status = 'pending'), 0) as pending")
            ->toBase()->first();

        $page = ListQuery::paginate($query, $params);

        return new OwnerPaymentListPageData(
            payments: collect($page->items())->map(fn (Payment $p) => OwnerPaymentListItemData::fromModel($p))->all(),
            totals: new OwnerPaymentTotalsData(paid: (float) $totals->paid, pending: (float) $totals->pending),
            pagination: PaginationData::fromPaginator($page),
        );
    }

    private function query(Vehicle $vehicle, string $ownerId, array $params): QueryBuilder
    {
        $base = Payment::query()
            ->where('payment_type', 'contract')
            ->whereIn('status', ['completed', 'pending'])
            ->whereHas('vehicleContract', fn (Builder $q) => $q->where('vehicle_id', $vehicle->id)->where('owner_id', $ownerId));

        return ListQuery::build($base, $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                ListQuery::exact('status'),
                AllowedFilter::callback('month', function (Builder $q, $value) {
                    if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)) {
                        $start = $value.'-01';
                        $q->whereDate('payment_date', '>=', $start)
                            ->whereDate('payment_date', '<', date('Y-m-d', strtotime($start.' +1 month')));
                    }
                }),
                AllowedFilter::callback('from', function (Builder $q, $value) {
                    if ($this->isDate($value)) {
                        $q->whereDate('payment_date', '>=', $value);
                    }
                }),
                AllowedFilter::callback('to', function (Builder $q, $value) {
                    if ($this->isDate($value)) {
                        $q->whereDate('payment_date', '<=', $value);
                    }
                }),
            ])
            ->allowedSorts(['payment_date'])
            ->defaultSort('-payment_date'));
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }
}
