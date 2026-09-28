<?php

namespace App\Shared\Http;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Le socle des listes paginées de l'administration (2026-09-28).
 *
 * Une seule convention pour toutes les listes, celle de spatie/laravel-query-builder :
 * `?filter[status]=pending&sort=-created_at&page=2&per_page=25`. Chaque action déclare ses
 * filtres et ses tris autorisés ; tout le reste est refusé.
 *
 * Les actions reçoivent un TABLEAU de paramètres et non la requête HTTP : elles restent
 * appelables et testables sans elle. D'où le `new Request($params)`.
 */
final class ListQuery
{
    /** Vingt-cinq lignes, comme la liste des réservations. */
    public const PER_PAGE = 25;

    /** Plafond de sécurité : `per_page=100000` ne doit pas pouvoir tout charger. */
    public const MAX_PER_PAGE = 100;

    /**
     * Prépare la requête : filtres, tris, tri par défaut, déclarés par `$configure`.
     *
     * ⚠️ Un tri ou un filtre hors liste blanche lève `InvalidQuery`, un 400 du paquet. Il est
     * converti ici en `ApiException` : les contrôleurs ne relancent intactes que
     * celles-ci, et le `catch (\Throwable)` de leur `guard()` en ferait sinon un 500.
     *
     * @param  array<string, mixed>  $params
     * @param  Closure(QueryBuilder): QueryBuilder  $configure
     */
    public static function build(Builder|string $subject, array $params, Closure $configure): QueryBuilder
    {
        try {
            return $configure(QueryBuilder::for($subject, new Request($params)));
        } catch (InvalidQuery $e) {
            throw new ApiException(
                400,
                'INVALID_LIST_QUERY',
                'Ce tri ou ce filtre n\'est pas proposé par cette liste.',
                previous: $e,
            );
        }
    }

    /**
     * Un filtre d'égalité, qui ignore la valeur vide.
     *
     * ⚠️ Le paquet applique `filter[x]=` tel quel : `where x = ''`, qui sur une colonne
     * `uuid` (un agent, un propriétaire) est une erreur SQL, donc un 500.
     */
    public static function exact(string $name, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::exact($name, $column)->ignore('');
    }

    /**
     * Un filtre booléen : `1`, `0`, `true` ou `false` ; la valeur vide ne filtre rien.
     *
     * Pas de `->ignore('')` ici : le paquet compare les valeurs ignorées sans rigueur de
     * type, et `false == ''` écarterait le filtre « inactifs ».
     *
     * @param  (Closure(Builder, bool): mixed)|null  $apply  par défaut `where($name, …)`
     */
    public static function boolean(string $name, ?Closure $apply = null): AllowedFilter
    {
        return AllowedFilter::callback($name, function (Builder $query, $value) use ($name, $apply) {
            if ($value === '' || $value === null) {
                return;
            }

            $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $apply ? $apply($query, $flag) : $query->where($name, $flag);
        });
    }

    /**
     * La recherche libre, `filter[search]`.
     *
     * ⚠️ Le paquet découpe toute valeur de filtre sur la VIRGULE : « Cotonou, Akpakpa »
     * arriverait en tableau de deux termes. On la recolle, pour chercher ce qui a été tapé.
     *
     * @param  Closure(Builder, string): mixed  $apply  reçoit le terme ; à grouper dans un
     *                                                  `where(fn …)` s'il porte des `or`
     */
    public static function search(Closure $apply): AllowedFilter
    {
        return AllowedFilter::callback('search', function (Builder $query, $value) use ($apply) {
            $term = is_array($value) ? implode(',', $value) : (string) $value;
            if ($term === '') {
                return;
            }

            $apply($query, $term);
        });
    }

    /**
     * Découpe en pages.
     *
     * ⚠️ Un second critère de tri, sur la clé primaire, UNIQUE. Les colonnes de tri ne le
     * sont pas (`created_at`, un nom, un statut), et PostgreSQL ne garantit alors aucun
     * ordre stable entre deux requêtes : une même ligne pourrait paraître sur deux pages,
     * ou sur aucune.
     *
     * @param  array<string, mixed>  $params
     */
    public static function paginate(Builder|QueryBuilder $query, array $params): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($params['per_page'] ?? self::PER_PAGE), self::MAX_PER_PAGE));
        $page = max(1, (int) ($params['page'] ?? 1));

        return $query
            ->orderBy($query->getModel()->getQualifiedKeyName())
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
