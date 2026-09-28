<?php

namespace App\Shared\Http;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
