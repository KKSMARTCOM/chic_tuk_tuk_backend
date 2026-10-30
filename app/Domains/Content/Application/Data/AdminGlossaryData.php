<?php

namespace App\Domains\Content\Application\Data;

use App\Models\GlossaryTerm;
use App\Shared\Data\BaseData;

/** Le glossaire entier, par ordre alphabétique, rendu par chaque route d'administration. */
final class AdminGlossaryData extends BaseData
{
    public function __construct(
        /** @var AdminGlossaryTermData[] */
        public array $terms,
    ) {}

    public static function current(): self
    {
        return new self(GlossaryTerm::orderBy('term_key')->get()->map(fn (GlossaryTerm $t) => AdminGlossaryTermData::fromModel($t))->all());
    }
}
