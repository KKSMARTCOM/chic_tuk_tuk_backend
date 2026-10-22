<?php

namespace App\Domains\Content\Application\Data;

use App\Shared\Data\BaseData;

/** Une question vue par le propriétaire : le rendu sûr seulement, jamais le Markdown. */
final class OwnerFaqEntryData extends BaseData
{
    public function __construct(
        public string $id,
        public string $question,
        public string $answerHtml,
    ) {}
}
