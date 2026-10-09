<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\InternalAssignment;

final class TakeOverResult
{
    public function __construct(
        public readonly ?InternalAssignment $endedAssignment,
        public readonly bool $activated,
    ) {}
}
