<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /admin/remuneration-statements — une page de la liste. */
final class AdminRemunerationStatementPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminRemunerationStatementListItemData> */
        public array $statements,
        public PaginationData $pagination,
    ) {}
}
