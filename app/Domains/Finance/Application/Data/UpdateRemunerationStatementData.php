<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\LaravelData\Optional;

/** PATCH d'un brouillon : les prélèvements, le reste d'ouverture, la note. */
final class UpdateRemunerationStatementData extends BaseData
{
    public function __construct(
        public ?float $deductedInternet = null,
        public ?float $deductedSpotify = null,
        public ?float $deductedManager = null,
        public ?float $openingInternet = null,
        public ?float $openingSpotify = null,
        public ?float $openingManager = null,
        public ?string $note = null,
        /**
         * La date d'établissement, saisie pour une fiche reconstituée (2026-10-01). ABSENTE :
         * inchangée ; `null` : effacée — la fiche prendra le jour de sa validation.
         */
        public string|Optional|null $issuedOn = new Optional(),
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'deducted_internet' => ['nullable', 'numeric', 'min:0'],
            'deducted_spotify' => ['nullable', 'numeric', 'min:0'],
            'deducted_manager' => ['nullable', 'numeric', 'min:0'],
            'opening_internet' => ['nullable', 'numeric', 'min:0'],
            'opening_spotify' => ['nullable', 'numeric', 'min:0'],
            'opening_manager' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
