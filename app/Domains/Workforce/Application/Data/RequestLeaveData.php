<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/** Le corps de POST /driver/leaves. */
final class RequestLeaveData extends BaseData
{
    public function __construct(
        public string $startDate,
        public int $requestedDays,
    ) {}

    /**
     * @return array<string, array<int, string>>
     *
     * Les deux règles du formulaire Blade. Les trois autres refus dépendent de l'état
     * de l'agent et vivent dans `RequestLeave` : une règle de validation ne sait pas
     * si un contrat est actif.
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'start_date' => ['required', 'date', 'after_or_equal:tomorrow'],
            'requested_days' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'start_date.after_or_equal' => "La pause doit être demandée au moins 24 heures à l'avance.",
            'requested_days.min' => 'Une pause dure au moins un jour.',
        ];
    }
}
