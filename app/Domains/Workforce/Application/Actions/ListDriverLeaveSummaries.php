<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\AdminDriverLeavePageData;
use App\Domains\Workforce\Application\Data\AdminDriverLeaveSummaryData;
use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ApiException;
use App\Shared\Http\ListQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * GET /admin/leaves — la liste des pauses, paginée côté serveur depuis le 2026-09-28.
 *
 * Même convention que les autres listes (`filter[...]`, `sort`, `page`, `per_page`), mais
 * pas le même moteur : les colonnes triables sont des soldes CALCULÉS (`LeaveBalance`,
 * contrat de référence), qu'aucune colonne SQL ne contient. On filtre donc par
 * `ListDriversForLeaves`, on calcule, on trie en PHP sur toute la liste filtrée, et on
 * découpe ensuite. Cela tient tant que la flotte se compte en dizaines d'agents.
 */
final class ListDriverLeaveSummaries
{
    /** Les filtres de `ListDriversForLeaves`, sous leur nom d'API. */
    private const FILTERS = ['search', 'contract', 'available', 'pending', 'status'];

    /** Colonne d'API => propriété de `AdminDriverLeaveSummaryData`. */
    private const SORTS = [
        'name' => 'name',
        'contract_months' => 'contractMonths',
        'total_leave_days' => 'totalLeaveDays',
        'leave_days_used' => 'leaveDaysUsed',
        'available_leave_days' => 'availableLeaveDays',
        'remaining_leave_days' => 'remainingLeaveDays',
        'pending_requests' => 'pendingRequests',
    ];

    /** Le tri du DataTable du Blade : « Disponibles » décroissant. */
    private const DEFAULT_SORT = '-available_leave_days';

    public function __construct(
        private readonly ListDriversForLeaves $lister,
    ) {}

    /** @param  array<string, mixed>  $params */
    public function __invoke(array $params = []): AdminDriverLeavePageData
    {
        $filters = $this->filters($params);
        [$property, $descending] = $this->sort($params);

        $rows = ($this->lister)($filters)
            ->map(fn (Driver $driver) => AdminDriverLeaveSummaryData::fromModel($driver));
        $sorted = $this->sorted($rows, $property, $descending);

        $perPage = max(1, min((int) ($params['per_page'] ?? ListQuery::PER_PAGE), ListQuery::MAX_PER_PAGE));
        $page = max(1, (int) ($params['page'] ?? 1));
        $paginator = new LengthAwarePaginator($sorted->forPage($page, $perPage)->values(), $sorted->count(), $perPage, $page);

        return new AdminDriverLeavePageData(
            drivers: $paginator->items(),
            pagination: PaginationData::fromPaginator($paginator),
            contractMonthsOptions: $this->contractMonthsOptions($filters === [] ? $rows : null),
            // Comme la somme de la colonne sur la liste NON filtrée : les demandes en
            // attente des agents qui ont un dossier de pauses, donc un contrat.
            pendingRequestsTotal: LeaveRequest::where('status', 'pending')
                ->whereHas('driver.driverContracts')
                ->count(),
        );
    }

    /** @return array<string, string> */
    private function filters(array $params): array
    {
        $filters = is_array($params['filter'] ?? null) ? $params['filter'] : [];

        if (array_diff(array_keys($filters), self::FILTERS) !== []) {
            throw $this->invalid();
        }

        return array_filter(
            array_map(fn ($value) => is_array($value) ? implode(',', $value) : (string) $value, $filters),
            fn (string $value) => $value !== '',
        );
    }

    /** @return array{0: string, 1: bool} */
    private function sort(array $params): array
    {
        $sort = (string) ($params['sort'] ?? self::DEFAULT_SORT);
        $descending = str_starts_with($sort, '-');
        $column = ltrim($sort, '-');

        if (! isset(self::SORTS[$column])) {
            throw $this->invalid();
        }

        return [self::SORTS[$column], $descending];
    }

    /**
     * Trie sur toute la liste. Les noms se comparent sans accents ni casse — « éric » se
     * range près de « Eric » —, faute de l'extension `intl` dans l'image ; les nombres
     * tels quels, `null` valant zéro. Le nom puis l'identifiant départagent les ex aequo,
     * pour qu'une ligne ne saute pas d'une page à l'autre.
     *
     * @param  Collection<int, AdminDriverLeaveSummaryData>  $rows
     * @return Collection<int, AdminDriverLeaveSummaryData>
     */
    private function sorted(Collection $rows, string $property, bool $descending): Collection
    {
        $key = fn (AdminDriverLeaveSummaryData $row, string $prop) => $prop === 'name'
            ? Str::lower(Str::ascii((string) $row->name))
            : ($row->{$prop} ?? 0);

        return $rows->sort(function (AdminDriverLeaveSummaryData $a, AdminDriverLeaveSummaryData $b) use ($key, $property, $descending) {
            $order = $key($a, $property) <=> $key($b, $property);

            return ($descending ? -$order : $order)
                ?: ($key($a, 'name') <=> $key($b, 'name'))
                ?: strcmp($a->id, $b->id);
        })->values();
    }

    /**
     * Les durées de contrat proposées au filtre, sur TOUS les agents : bâti sur la liste
     * filtrée, le menu ne proposerait plus que la durée déjà choisie — le défaut du Blade.
     *
     * @param  Collection<int, AdminDriverLeaveSummaryData>|null  $unfiltered  la liste, si elle n'est pas filtrée
     * @return array<int, int>
     */
    private function contractMonthsOptions(?Collection $unfiltered): array
    {
        $rows = $unfiltered ?? ($this->lister)([])->map(fn (Driver $driver) => AdminDriverLeaveSummaryData::fromModel($driver));

        return $rows->pluck('contractMonths')->filter()->unique()->sort()->values()->all();
    }

    private function invalid(): ApiException
    {
        return new ApiException(400, 'INVALID_LIST_QUERY', 'Ce tri ou ce filtre n\'est pas proposé par cette liste.');
    }
}
