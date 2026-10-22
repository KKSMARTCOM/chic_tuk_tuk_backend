<?php

namespace App\Domains\Content\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Content\Application\Actions\SaveFaqEntry;
use App\Domains\Content\Application\Data\AdminFaqData;
use App\Domains\Content\Domain\FaqPositions;
use App\Models\FaqEntry;
use App\Models\FaqSection;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * La FAQ des propriétaires, écrite par l'administrateur seul (spec 2026-10-22-faq-proprietaire,
 * §4.1). Chaque route rend la FAQ entière à jour ; le contrôleur trace au journal.
 */
final class FaqController
{
    public function __construct(private readonly ActivityJournal $journal) {}

    public function show(Request $request): JsonResponse
    {
        return $this->guard($request, 'la lecture de la FAQ', fn () => null);
    }

    public function storeSection(Request $request): JsonResponse
    {
        return $this->guard($request, 'l\'ajout d\'une rubrique', function () use ($request) {
            $data = $this->validateSection($request);
            $section = FaqSection::create(['title' => $data['title'], 'position' => FaqPositions::next(FaqSection::query())]);
            $this->journal->faqSectionCreated($section);
        });
    }

    public function updateSection(Request $request, string $id): JsonResponse
    {
        return $this->guard($request, 'le renommage d\'une rubrique', function () use ($request, $id) {
            $section = FaqSection::findOrFail($id);
            $data = $this->validateSection($request, $section);
            $previous = $section->title;
            $section->update(['title' => $data['title']]);
            if ($previous !== $section->title) {
                $this->journal->faqSectionRenamed($section, $previous);
            }
        });
    }

    public function destroySection(Request $request, string $id): JsonResponse
    {
        return $this->guard($request, 'la suppression d\'une rubrique', function () use ($id) {
            $section = FaqSection::withCount('entries')->findOrFail($id);
            DB::transaction(function () use ($section) {
                $section->delete();
                FaqPositions::compact(FaqSection::query());
            });
            $this->journal->faqSectionDeleted($section->title, $section->entries_count);
        });
    }

    public function moveSection(Request $request, string $id): JsonResponse
    {
        return $this->guard($request, 'le déplacement d\'une rubrique', function () use ($request, $id) {
            $section = FaqSection::findOrFail($id);
            if (DB::transaction(fn () => FaqPositions::move($section, FaqSection::query(), $this->direction($request)))) {
                $this->journal->faqSectionMoved($section);
            }
        });
    }

    public function storeEntry(Request $request, SaveFaqEntry $save): JsonResponse
    {
        return $this->guard($request, 'l\'ajout d\'une question', function () use ($request, $save) {
            $result = $save(null, $this->validateEntry($request, partial: false));
            $this->journal->faqEntryCreated($result['entry']);
        });
    }

    public function updateEntry(Request $request, string $id, SaveFaqEntry $save): JsonResponse
    {
        return $this->guard($request, 'la modification d\'une question', function () use ($request, $id, $save) {
            $entry = FaqEntry::findOrFail($id);
            $answerBefore = $entry->answer;
            $result = $save($entry, $this->validateEntry($request, partial: true));
            if ($result['content'] || $result['moved']) {
                $this->journal->faqEntryUpdated($result['entry'], answerChanged: $result['entry']->answer !== $answerBefore);
            }
            if ($result['visibility'] !== null) {
                $this->journal->faqEntryVisibility($result['entry'], $result['visibility']);
            }
        });
    }

    public function destroyEntry(Request $request, string $id): JsonResponse
    {
        return $this->guard($request, 'la suppression d\'une question', function () use ($id) {
            $entry = FaqEntry::findOrFail($id);
            DB::transaction(function () use ($entry) {
                $entry->delete();
                FaqPositions::compact(FaqEntry::where('faq_section_id', $entry->faq_section_id));
            });
            $this->journal->faqEntryDeleted($entry->question);
        });
    }

    public function moveEntry(Request $request, string $id): JsonResponse
    {
        return $this->guard($request, 'le déplacement d\'une question', function () use ($request, $id) {
            $entry = FaqEntry::findOrFail($id);
            if (DB::transaction(fn () => FaqPositions::move($entry, FaqEntry::where('faq_section_id', $entry->faq_section_id), $this->direction($request)))) {
                $this->journal->faqEntryMoved($entry);
            }
        });
    }

    /** @return array{title: string} */
    private function validateSection(Request $request, ?FaqSection $section = null): array
    {
        return Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:120', Rule::unique('faq_sections', 'title')->ignore($section?->id)],
        ], [
            'title.required' => 'Le titre de la rubrique est obligatoire.',
            'title.max' => 'Le titre ne dépasse pas 120 caractères.',
            'title.unique' => 'Une rubrique porte déjà ce titre.',
        ])->validate();
    }

    /** @return array<string, mixed> */
    private function validateEntry(Request $request, bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return Validator::make($request->all(), [
            'faq_section_id' => [...$required, 'uuid', Rule::exists('faq_sections', 'id')],
            'question' => [...$required, 'string', 'max:255'],
            'answer' => [...$required, 'string', 'max:20000'],
            'is_visible' => ['sometimes', 'boolean'],
        ], [
            'faq_section_id.required' => 'Choisissez la rubrique.',
            'faq_section_id.exists' => 'Cette rubrique n\'existe plus.',
            'question.required' => 'La question est obligatoire.',
            'question.max' => 'La question ne dépasse pas 255 caractères.',
            'answer.required' => 'La réponse est obligatoire.',
        ])->validate();
    }

    private function direction(Request $request): string
    {
        return Validator::make($request->all(), ['direction' => ['required', Rule::in(['up', 'down'])]])->validate()['direction'];
    }

    /** @param  callable(): mixed  $run  l'écriture ; la réponse est toujours la FAQ à jour */
    private function guard(Request $request, string $what, callable $run): JsonResponse
    {
        try {
            $run();

            return response()->json(AdminFaqData::current());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("Erreur lors de {$what} : ".$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

            return response()->json(['message' => 'La FAQ n\'a pas pu être enregistrée. Réessayez.', 'code' => 'ADMIN_FAQ_WRITE_FAILED'], 500);
        }
    }
}
