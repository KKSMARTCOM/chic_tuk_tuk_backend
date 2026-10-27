<?php

namespace App\Domains\Booking\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Booking\Application\Data\AdminPopularPlacesData;
use App\Domains\Booking\Application\PopularPlacesCache;
use App\Domains\Booking\Domain\OsmPlaceFilter;
use App\Domains\Booking\Domain\PlaceText;
use App\Models\PopularPlace;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Les lieux populaires de la recherche de lieux, tenus par l'administrateur seul (spec
 * 2026-10-27-recherche-de-lieux, §5.1). Chaque route rend la liste entière ; chaque
 * écriture vide le cache de la recherche et trace au journal.
 */
final class PopularPlaceController
{
    /** Les champs comparés pour le journal d'une modification. */
    private const TRACKED = ['name', 'landmark', 'aliases', 'lat', 'lng', 'is_active'];

    public function __construct(private readonly ActivityJournal $journal) {}

    public function index(Request $request): JsonResponse
    {
        return $this->guard($request, 'la lecture des lieux populaires', fn () => null);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guard($request, 'l\'ajout d\'un lieu populaire', function () use ($request) {
            $place = PopularPlace::create([
                ...$this->validated($request),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            $this->journal->popularPlaceAdded($place);
        });
    }

    public function update(Request $request, string $place): JsonResponse
    {
        return $this->guard($request, 'la modification d\'un lieu populaire', function () use ($request, $place) {
            $model = PopularPlace::findOrFail($place);
            $before = $model->only(self::TRACKED);
            $model->fill($this->validated($request, $model));

            $changes = [];
            foreach (self::TRACKED as $field) {
                if ($before[$field] !== $model->{$field}) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $model->{$field}];
                }
            }
            if (! $changes) {
                return;
            }

            $model->updated_by = $request->user()->id;
            $model->save();
            $this->journal->popularPlaceUpdated($model, $changes);
        });
    }

    public function destroy(Request $request, string $place): JsonResponse
    {
        return $this->guard($request, 'la suppression d\'un lieu populaire', function () use ($place) {
            $model = PopularPlace::findOrFail($place);
            $model->delete();
            $this->journal->popularPlaceRemoved($model->name);
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PopularPlace $place = null): array
    {
        $zone = 'Le lieu est en dehors de la zone desservie.';
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'landmark' => ['nullable', 'string', 'max:120'],
            'aliases' => ['nullable', 'array', 'max:10'],
            'aliases.*' => ['required', 'string', 'max:120', 'distinct'],
            'lat' => ['required', 'numeric', 'between:'.OsmPlaceFilter::MIN_LAT.','.OsmPlaceFilter::MAX_LAT],
            'lng' => ['required', 'numeric', 'between:'.OsmPlaceFilter::MIN_LNG.','.OsmPlaceFilter::MAX_LNG],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Le nom du lieu est obligatoire.',
            'name.max' => 'Le nom ne dépasse pas 120 caractères.',
            'landmark.max' => 'Le repère ne dépasse pas 120 caractères.',
            'aliases.max' => 'Dix autres noms au plus.',
            'aliases.*.required' => 'Un autre nom ne peut pas être vide.',
            'aliases.*.max' => 'Un autre nom ne dépasse pas 120 caractères.',
            'aliases.*.distinct' => 'Cet autre nom est répété.',
            'lat.required' => 'Placez le lieu sur la carte.',
            'lng.required' => 'Placez le lieu sur la carte.',
            'lat.between' => $zone,
            'lng.between' => $zone,
        ])->after(function ($validator) use ($request, $place) {
            $name = PlaceText::normalize((string) $request->input('name'));
            // Unique sans casse ni accents : la liste est petite, elle se lit entière.
            $taken = PopularPlace::query()->when($place, fn ($q) => $q->whereKeyNot($place->id))->pluck('name')
                ->contains(fn (string $other) => PlaceText::normalize($other) === $name);
            if ($name !== '' && $taken) {
                $validator->errors()->add('name', 'Ce lieu existe déjà');
            }
        })->validate();

        return [
            'name' => trim($data['name']),
            'landmark' => isset($data['landmark']) && trim($data['landmark']) !== '' ? trim($data['landmark']) : null,
            'aliases' => array_values(array_map('trim', $data['aliases'] ?? [])),
            'lat' => (float) $data['lat'],
            'lng' => (float) $data['lng'],
            'is_active' => (bool) ($data['is_active'] ?? $place?->is_active ?? true),
        ];
    }

    /** @param  callable(): mixed  $run  l'écriture ; la réponse est toujours la liste à jour */
    private function guard(Request $request, string $what, callable $run): JsonResponse
    {
        try {
            $run();
            PopularPlacesCache::forget();

            return response()->json(AdminPopularPlacesData::current());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("Erreur lors de {$what} : ".$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

            return response()->json(['message' => 'Le lieu n\'a pas pu être enregistré. Réessayez.', 'code' => 'ADMIN_POPULAR_PLACES_WRITE_FAILED'], 500);
        }
    }
}
