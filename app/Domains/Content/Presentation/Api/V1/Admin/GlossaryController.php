<?php

namespace App\Domains\Content\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Content\Application\Data\AdminGlossaryData;
use App\Models\GlossaryTerm;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Le glossaire des propriétaires, écrit par l'administrateur seul (spec
 * 2026-10-30-glossaire-proprietaire). Chaque route rend le glossaire entier ; le contrôleur trace.
 */
final class GlossaryController
{
    public function __construct(private readonly ActivityJournal $journal) {}

    public function show(Request $request): JsonResponse
    {
        return $this->guard($request, 'la lecture du glossaire', fn () => null);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guard($request, 'l\'ajout d\'un terme', function () use ($request) {
            $term = GlossaryTerm::create($this->validated($request, null));
            $this->journal->glossaryTermCreated($term);
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->guard($request, 'la modification d\'un terme', function () use ($request, $id) {
            $term = GlossaryTerm::findOrFail($id);
            $term->fill($this->validated($request, $term));
            $content = $term->isDirty(['term', 'definition', 'formula']);
            $visibility = $term->isDirty('is_visible');
            $term->save();
            if ($content) {
                $this->journal->glossaryTermUpdated($term);
            }
            if ($visibility) {
                $this->journal->glossaryTermVisibility($term);
            }
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->guard($request, 'la suppression d\'un terme', function () use ($id) {
            $term = GlossaryTerm::findOrFail($id);
            $term->delete();
            $this->journal->glossaryTermDeleted($term->term);
        });
    }

    /** @return array<string, mixed> les seuls champs envoyés à la modification (« Tout montrer ») */
    private function validated(Request $request, ?GlossaryTerm $current): array
    {
        $required = $current ? ['sometimes', 'required'] : ['required'];

        $data = Validator::make($request->all(), [
            'term' => [...$required, 'string', 'max:255', function ($attribute, $value, $fail) use ($current) {
                if (is_string($value) && GlossaryTerm::where('term_key', GlossaryTerm::keyOf($value))->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
                    $fail('Ce terme existe déjà.');
                }
            }],
            'definition' => [...$required, 'string', 'max:20000'],
            'formula' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_visible' => ['sometimes', 'boolean'],
        ], [
            'term.required' => 'Le terme est obligatoire.',
            'term.max' => 'Le terme ne dépasse pas 255 caractères.',
            'definition.required' => 'La définition est obligatoire.',
            'formula.max' => 'La formule ne dépasse pas 500 caractères.',
        ])->validate();

        if (array_key_exists('term', $data)) {
            $data['term'] = trim($data['term']);
        }
        if (array_key_exists('formula', $data)) {
            $data['formula'] = trim((string) $data['formula']) ?: null;
        }

        return $data;
    }

    /** @param  callable(): mixed  $run  l'écriture ; la réponse est toujours le glossaire à jour */
    private function guard(Request $request, string $what, callable $run): JsonResponse
    {
        try {
            $run();

            return response()->json(AdminGlossaryData::current());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("Erreur lors de {$what} : ".$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

            return response()->json(['message' => 'Le glossaire n\'a pas pu être enregistré. Réessayez.', 'code' => 'ADMIN_GLOSSARY_WRITE_FAILED'], 500);
        }
    }
}
