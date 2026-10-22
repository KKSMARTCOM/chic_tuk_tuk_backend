<?php

namespace App\Domains\Content\Application\Actions;

use App\Domains\Announcement\Domain\AnnouncementBody;
use App\Domains\Content\Application\Data\OwnerFaqEntryData;
use App\Domains\Content\Application\Data\OwnerFaqSectionData;
use App\Models\FaqSection;

/**
 * La FAQ telle que la lit le propriétaire (spec 2026-10-22-faq-proprietaire, §3) : les seules
 * questions visibles, et les seules rubriques qui en ont une. Vide tant que tout est masqué.
 */
final class ShowOwnerFaq
{
    /** @return list<OwnerFaqSectionData> */
    public function __invoke(): array
    {
        return FaqSection::query()
            ->with(['entries' => fn ($q) => $q->where('is_visible', true)])
            ->orderBy('position')->get()
            ->filter(fn (FaqSection $s) => $s->entries->isNotEmpty())
            ->map(fn (FaqSection $s) => new OwnerFaqSectionData($s->title, $s->entries
                ->map(fn ($e) => new OwnerFaqEntryData($e->id, $e->question, AnnouncementBody::toHtml($e->answer)))->all()))
            ->values()->all();
    }
}
