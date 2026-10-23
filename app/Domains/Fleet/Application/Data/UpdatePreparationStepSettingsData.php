<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * La liste COMPLÈTE envoyée par les réglages (spec 2026-10-20, §5.1) : une étape absente est
 * supprimée, `id` nul en crée une, l'ordre du tableau fait la position.
 */
final class UpdatePreparationStepSettingsData extends BaseData
{
    public function __construct(
        /** @var array<int, array<string, mixed>> */
        #[LiteralTypeScriptType('Array<{ id: string | null; label: string }>')]
        public array $steps,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:1', 'max:30'],
            'steps.*.id' => ['nullable', 'uuid', 'distinct', Rule::exists('preparation_step_templates', 'id')],
            'steps.*.label' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail) {
                // Deux libellés identiques à la casse et aux espaces près : le second est refusé.
                $index = (int) explode('.', $attribute)[1];
                $normalize = fn ($label) => mb_strtolower(trim((string) $label));
                foreach (array_slice((array) request()->input('steps'), 0, $index) as $previous) {
                    if ($normalize($previous['label'] ?? '') === $normalize($value)) {
                        $fail('Cette étape figure déjà dans la liste.');

                        return;
                    }
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'steps.required' => 'Gardez au moins une étape.',
            'steps.min' => 'Gardez au moins une étape.',
            'steps.max' => 'Trente étapes au plus.',
            'steps.*.id.exists' => 'Cette étape n\'existe plus : rechargez la page.',
            'steps.*.id.distinct' => 'Cette étape figure deux fois dans la liste : rechargez la page.',
            'steps.*.label.required' => 'Le libellé de l\'étape est obligatoire.',
            'steps.*.label.max' => 'Le libellé ne dépasse pas 120 caractères.',
        ];
    }
}
