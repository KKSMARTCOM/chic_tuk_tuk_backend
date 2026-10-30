<?php

namespace App\Domains\Content\Application\Data;

use App\Domains\Announcement\Domain\AnnouncementBody;
use App\Models\GlossaryTerm;
use App\Shared\Data\BaseData;

/** Un terme côté admin : le Markdown pour l'éditeur, et son rendu sûr. */
final class AdminGlossaryTermData extends BaseData
{
    public function __construct(
        public string $id,
        public string $term,
        public string $definition,
        public string $definitionHtml,
        public ?string $formula,
        public bool $isVisible,
    ) {}

    public static function fromModel(GlossaryTerm $term): self
    {
        return new self($term->id, $term->term, $term->definition, AnnouncementBody::toHtml($term->definition), $term->formula, $term->is_visible);
    }
}
