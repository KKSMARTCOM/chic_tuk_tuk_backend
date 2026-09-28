<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /admin/leaves — une page de la liste des pauses (2026-09-28). */
final class AdminDriverLeavePageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminDriverLeaveSummaryData> */
        public array $drivers,
        public PaginationData $pagination,
        // Les durées de contrat du filtre, sur tous les agents.
        /** @var int[] */
        public array $contractMonthsOptions,
        /** Les demandes en attente de tous les agents, quel que soit le filtre. */
        public int $pendingRequestsTotal,
    ) {}
}
