<?php

namespace App\Domains\Content\Application\Actions;

use App\Domains\Content\Domain\FaqPositions;
use App\Models\FaqEntry;
use Illuminate\Support\Facades\DB;

/**
 * Créer ou modifier une question de la FAQ (spec 2026-10-22-faq-proprietaire, §3). Changer de
 * rubrique la place en dernier dans la nouvelle, et renumérote l'ancienne.
 */
final class SaveFaqEntry
{
    /**
     * @param  array{faq_section_id?: string, question?: string, answer?: string, is_visible?: bool}  $data
     * @return array{entry: FaqEntry, content: bool, visibility: ?bool, moved: bool} ce qui a changé, pour le journal
     */
    public function __invoke(?FaqEntry $entry, array $data): array
    {
        return DB::transaction(function () use ($entry, $data) {
            if (! $entry) {
                $entry = FaqEntry::create([
                    ...$data,
                    'position' => FaqPositions::next(FaqEntry::where('faq_section_id', $data['faq_section_id'])),
                ]);

                return ['entry' => $entry, 'content' => true, 'visibility' => null, 'moved' => false];
            }

            $previousSection = $entry->faq_section_id;
            $wasVisible = $entry->is_visible;
            if (isset($data['faq_section_id'])) {
                // Un uuid se compare sans tenir compte de la casse : le même, en capitales, ne déplace rien.
                $data['faq_section_id'] = strtolower($data['faq_section_id']);
            }
            $entry->fill($data);
            $moved = $entry->faq_section_id !== $previousSection;
            if ($moved) {
                $entry->position = FaqPositions::next(FaqEntry::where('faq_section_id', $entry->faq_section_id));
            }
            $content = $entry->isDirty(['question', 'answer']);
            $entry->save();
            if ($moved) {
                FaqPositions::compact(FaqEntry::where('faq_section_id', $previousSection));
            }

            return ['entry' => $entry, 'content' => $content, 'visibility' => $entry->is_visible !== $wasVisible ? $entry->is_visible : null, 'moved' => $moved];
        });
    }
}
