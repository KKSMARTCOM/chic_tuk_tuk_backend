<?php

namespace App\Domains\Audit\Application\Actions;

use App\Domains\Audit\Application\Data\ActivityEntryData;
use App\Domains\Audit\Application\Data\ActivityEventOptionData;
use App\Domains\Audit\Application\Data\ActivityLogPageData;
use App\Domains\Audit\Domain\ActivityEvent;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Le journal d'activité, du plus récent au plus ancien, selon la convention des listes
 * de l'administration (`ListQuery`).
 *
 * Filtres : `event` (un code, ou un préfixe de groupe comme `booking.`), `causer_id`
 * (tout ce qu'a fait une personne), `subject_id` (tout ce qui est arrivé à un objet),
 * `from` et `to` (dates, bornes incluses), `search` (dans la phrase et l'auteur).
 */
final class ListActivityLog
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __invoke(array $params = []): ActivityLogPageData
    {
        $page = ListQuery::paginate($this->query($params), $params);

        return new ActivityLogPageData(
            entries: collect($page->items())
                ->map(fn (Activity $activity) => ActivityEntryData::fromModel($activity))
                ->all(),
            pagination: PaginationData::fromPaginator($page),
            events: collect(ActivityEvent::cases())
                ->map(fn (ActivityEvent $event) => new ActivityEventOptionData($event->value, $event->label(), $event->group()))
                ->all(),
        );
    }

    private function query(array $params): QueryBuilder
    {
        return ListQuery::build(Activity::query(), $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                AllowedFilter::callback('event', function (Builder $q, $value) {
                    $value = (string) $value;
                    if ($value === '') {
                        return;
                    }
                    // Un préfixe de groupe (`booking.`) filtre toute la famille.
                    str_ends_with($value, '.')
                        ? $q->where('event', 'like', $value.'%')
                        : $q->where('event', $value);
                }),
                ListQuery::exact('causer_id'),
                ListQuery::exact('subject_id'),
                AllowedFilter::callback('from', fn (Builder $q, $value) => $this->date($value)
                    ? $q->where('created_at', '>=', $this->date($value)->startOfDay()) : null),
                AllowedFilter::callback('to', fn (Builder $q, $value) => $this->date($value)
                    ? $q->where('created_at', '<=', $this->date($value)->endOfDay()) : null),
                ListQuery::search(fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                    ->where('description', 'ILIKE', "%{$search}%")
                    ->orWhereRaw("properties->>'actor' ILIKE ?", ["%{$search}%"]))),
            ])
            ->allowedSorts(['created_at', 'id'])
            // Deux lignes de la même seconde se départagent par leur ordre d'écriture.
            ->defaultSort('-created_at', '-id'));
    }

    /** Une date mal formée n'est pas une erreur : le filtre est ignoré. */
    private function date(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::createFromFormat('Y-m-d', $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
