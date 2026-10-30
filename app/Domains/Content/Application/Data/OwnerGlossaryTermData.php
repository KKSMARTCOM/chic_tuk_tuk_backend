<?php

namespace App\Domains\Content\Application\Data;

use App\Domains\Announcement\Domain\AnnouncementBody;
use App\Models\GlossaryTerm;
use App\Shared\Data\BaseData;

/** Un terme vu par le propriétaire : le rendu sûr seulement, jamais le Markdown. */
final class OwnerGlossaryTermData extends BaseData
{
    public function __construct(
        public string $id,
        public string $term,
        public string $definitionHtml,
        public ?string $formula,
    ) {}

    public static function fromModel(GlossaryTerm $term): self
    {
        return new self($term->id, $term->term, AnnouncementBody::toHtml($term->definition), $term->formula);
    }
}
