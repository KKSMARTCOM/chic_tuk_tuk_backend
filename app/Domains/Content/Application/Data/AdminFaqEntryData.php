<?php

namespace App\Domains\Content\Application\Data;

use App\Domains\Announcement\Domain\AnnouncementBody;
use App\Models\FaqEntry;
use App\Shared\Data\BaseData;

/** Une question de la FAQ côté admin : le Markdown pour l'éditeur, et son rendu sûr. */
final class AdminFaqEntryData extends BaseData
{
    public function __construct(
        public string $id,
        public string $question,
        public string $answer,
        public string $answerHtml,
        public bool $isVisible,
    ) {}

    public static function fromModel(FaqEntry $entry): self
    {
        return new self($entry->id, $entry->question, $entry->answer, AnnouncementBody::toHtml($entry->answer), $entry->is_visible);
    }
}
