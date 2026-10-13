<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;

/** Le décompte en direct de l'éditeur : « 43 destinataires, dont 38 avec e-mail ». */
final class RecipientPreviewData extends BaseData
{
    public function __construct(
        public int $total,
        public int $withEmail,
    ) {}
}
