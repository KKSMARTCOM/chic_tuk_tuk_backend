<?php

namespace App\Domains\Identity\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/**
 * GET /admin/users — la liste des administrateurs, ses compteurs, et les rôles que le
 * formulaire propose.
 *
 * `assignableRoles` ne liste que les rôles d'administration : ni `driver`, ni
 * `proprietaire`, ni `client`, qui n'ont pas de sens sur un compte `profil=admin`.
 */
final class AdminUserPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminUserListItemData> */
        public array $users,
        public PaginationData $pagination,
        public AdminUserStatsData $stats,
        /** @var array<int, AdminUserRoleData> */
        public array $assignableRoles,
    ) {}
}
