<?php

namespace Tests\Feature\Api;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ApiException;
use App\Shared\Http\ListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\QueryBuilder\QueryBuilder;
use Tests\TestCase;

/**
 * Le socle des listes paginées de l'administration (2026-09-28) :
 * spatie/laravel-query-builder, `?filter[x]=…&sort=-col&page=n&per_page=n`.
 */
class ListQueryTest extends TestCase
{
    use RefreshDatabase;

    private function build(array $params): QueryBuilder
    {
        return ListQuery::build(User::query(), $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                ListQuery::exact('profil'),
                ListQuery::boolean('is_active'),
                ListQuery::search(fn ($query, string $term) => $query->where('name', 'ilike', "%{$term}%")),
            ])
            ->allowedSorts(['name', 'created_at'])
            ->defaultSort('-created_at'));
    }

    public function test_it_paginates_by_25_by_default(): void
    {
        User::factory()->count(30)->create();

        $page = ListQuery::paginate($this->build([]), []);

        $this->assertCount(25, $page->items());
        $this->assertSame(30, $page->total());
        $this->assertSame(2, $page->lastPage());
    }

    public function test_per_page_is_capped(): void
    {
        User::factory()->count(3)->create();

        $this->assertSame(100, ListQuery::paginate($this->build([]), ['per_page' => '100000'])->perPage());
        $this->assertSame(1, ListQuery::paginate($this->build([]), ['per_page' => '0'])->perPage());
    }

    public function test_it_sorts_and_filters_by_the_query_builder_convention(): void
    {
        User::factory()->create(['name' => 'Bertin', 'profil' => Profil::Driver->value]);
        User::factory()->create(['name' => 'Abla', 'profil' => Profil::Driver->value]);
        User::factory()->create(['name' => 'Célestin', 'profil' => Profil::Admin->value]);

        $page = ListQuery::paginate($this->build(['sort' => '-name', 'filter' => ['profil' => 'driver']]), []);

        $this->assertSame(['Bertin', 'Abla'], collect($page->items())->pluck('name')->all());
    }

    /** `created_at` n'est pas unique : sans second critère, une ligne peut sauter de page. */
    public function test_pages_do_not_overlap_when_the_sort_column_ties(): void
    {
        User::factory()->count(6)->create(['created_at' => now()]);

        $seen = collect([1, 2, 3])
            ->flatMap(fn (int $n) => collect(ListQuery::paginate($this->build([]), ['page' => $n, 'per_page' => 2])->items())->pluck('id'));

        $this->assertCount(6, $seen->unique());
    }

    public function test_an_unknown_sort_is_a_400_with_a_code(): void
    {
        try {
            $this->build(['sort' => 'password']);
            $this->fail('un tri hors liste blanche doit être refusé');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('INVALID_LIST_QUERY', $e->errorCode);
        }
    }

    public function test_an_unknown_filter_is_a_400_with_a_code(): void
    {
        $this->expectException(ApiException::class);

        $this->build(['filter' => ['password' => 'x']]);
    }

    /** Le paquet découpe toute valeur de filtre sur la virgule : la recherche doit arriver entière. */
    public function test_a_search_keeps_its_commas(): void
    {
        User::factory()->create(['name' => 'Cotonou, Akpakpa']);
        User::factory()->create(['name' => 'Cotonou']);

        $page = ListQuery::paginate($this->build(['filter' => ['search' => 'Cotonou, Akpakpa']]), []);

        $this->assertSame(['Cotonou, Akpakpa'], collect($page->items())->pluck('name')->all());
    }

    /** `filter[x]=` vaut « pas de filtre » — sur une colonne uuid, `= ''` serait une erreur SQL. */
    public function test_an_empty_filter_value_filters_nothing(): void
    {
        User::factory()->count(2)->create();

        $page = ListQuery::paginate($this->build(['filter' => ['profil' => '', 'is_active' => '', 'search' => '']]), []);

        $this->assertSame(2, $page->total());
    }

    public function test_a_boolean_filter_reads_1_0_true_and_false(): void
    {
        User::factory()->create(['is_active' => true]);
        User::factory()->create(['is_active' => false]);

        foreach (['1' => 1, '0' => 1, 'true' => 1, 'false' => 1] as $value => $expected) {
            $page = ListQuery::paginate($this->build(['filter' => ['is_active' => $value]]), []);
            $this->assertSame($expected, $page->total(), "is_active={$value}");
        }
        $this->assertFalse(collect(ListQuery::paginate($this->build(['filter' => ['is_active' => 'false']]), [])->items())->first()->is_active);
    }

    public function test_the_pagination_data_carries_the_four_counters(): void
    {
        User::factory()->count(3)->create();

        $data = PaginationData::fromPaginator(ListQuery::paginate($this->build([]), ['per_page' => 2]))->toArray();

        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3], $data);
    }
}
