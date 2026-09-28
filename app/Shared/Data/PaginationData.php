<?php

namespace App\Shared\Data;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Où l'on en est dans une liste paginée (2026-09-28).
 *
 * Quatre compteurs, et rien d'autre : le format de pagination par défaut de Laravel
 * embarque une quinzaine de champs, dont des URL absolues vers le domaine de l'API,
 * inutiles à un front qui construit ses propres liens.
 */
final class PaginationData extends BaseData
{
    public function __construct(
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}

    public static function fromPaginator(LengthAwarePaginator $page): self
    {
        return new self(
            currentPage: $page->currentPage(),
            lastPage: $page->lastPage(),
            perPage: $page->perPage(),
            total: $page->total(),
        );
    }
}
