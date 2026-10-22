<?php

namespace App\Domains\Content\Application\Data;

use App\Models\FaqSection;
use App\Shared\Data\BaseData;

/** La FAQ entière, rendue par chaque route d'administration : elle est petite (§4.1). */
final class AdminFaqData extends BaseData
{
    public function __construct(
        /** @var AdminFaqSectionData[] */
        public array $sections,
    ) {}

    public static function current(): self
    {
        return new self(FaqSection::with('entries')->orderBy('position')->get()
            ->map(fn (FaqSection $s) => new AdminFaqSectionData($s->id, $s->title,
                $s->entries->map(fn ($e) => AdminFaqEntryData::fromModel($e))->all()))
            ->all());
    }
}
