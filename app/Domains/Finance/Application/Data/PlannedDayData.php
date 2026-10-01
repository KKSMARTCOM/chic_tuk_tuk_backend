<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Domain\PlannedDay;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** Un jour de l'aperçu d'une génération. */
final class PlannedDayData extends BaseData
{
    public function __construct(
        public string $date,
        #[LiteralTypeScriptType("'out_of_contract' | 'weekend' | 'agent_pause' | 'immobilized' | 'paid' | 'cancelled' | 'to_generate'")]
        public string $class,
        public ?string $paymentId,
    ) {}

    public static function fromDay(PlannedDay $day): self
    {
        return new self($day->date, $day->class, $day->paymentId);
    }
}
