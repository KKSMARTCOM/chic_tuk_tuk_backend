<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminRemunerationStatementListItemData;
use App\Domains\Finance\Application\Data\AdminRemunerationStatementPageData;
use App\Domains\Finance\Domain\Enums\RemunerationStatementStatus;
use App\Models\RemunerationStatement;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * La liste des fiches de rémunération (spec 2026-09-30, §6.2).
 *
 * Une fiche validée ou annulée donne ses chiffres figés ; un brouillon est recalculé — 25
 * lignes au plus par page, le coût reste borné.
 */
final class ListRemunerationStatements
{
    public function __construct(private readonly BuildStatementFigures $figures) {}

    /**
     * @param  array<string, mixed>  $params  `filter[month|status|owner_id]`, `sort` (month,
     *                                         created_at), `page`, `per_page`
     */
    public function __invoke(array $params = []): AdminRemunerationStatementPageData
    {
        $page = ListQuery::paginate($this->query($params), $params);

        return new AdminRemunerationStatementPageData(
            statements: collect($page->items())
                ->map(fn (RemunerationStatement $statement) => $this->item($statement))
                ->all(),
            pagination: PaginationData::fromPaginator($page),
        );
    }

    private function query(array $params): QueryBuilder
    {
        return ListQuery::build(RemunerationStatement::query()->with('contract.vehicle.owner'), $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                ListQuery::exact('status'),
                AllowedFilter::callback('month', function (Builder $q, $value) {
                    if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)) {
                        $q->whereDate('month', $value.'-01');
                    }
                }),
                AllowedFilter::callback('owner_id', function (Builder $q, $value) {
                    if (is_string($value) && $value !== '') {
                        $q->whereHas('contract', fn (Builder $c) => $c->where('owner_id', $value));
                    }
                }),
            ])
            ->allowedSorts(['month', 'created_at'])
            ->defaultSort('-month'));
    }

    private function item(RemunerationStatement $statement): AdminRemunerationStatementListItemData
    {
        $status = RemunerationStatementStatus::from($statement->status);

        if ($status === RemunerationStatementStatus::Draft) {
            $figures = ($this->figures)($statement->contract, $statement->monthKey(), $statement);
            $balance = $figures->balanceDue;
            $anomalies = count($figures->anomalies);
        } else {
            $balance = (float) $statement->balance_due;
            $anomalies = count($statement->figures['anomalies'] ?? []);
        }

        return new AdminRemunerationStatementListItemData(
            id: $statement->id,
            number: $statement->number,
            status: $status->value,
            statusLabel: $status->label(),
            month: $statement->monthKey(),
            ownerName: (string) $statement->contract?->vehicle?->owner?->name,
            vehicleNumber: (string) $statement->contract?->vehicle?->vehicle_number,
            balanceDue: $balance,
            anomalyCount: $anomalies,
        );
    }
}
