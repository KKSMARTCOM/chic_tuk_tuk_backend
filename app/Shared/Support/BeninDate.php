<?php

namespace App\Shared\Support;

/**
 * La date du jour au Bénin. L'application tourne en UTC, le Bénin est à UTC+1 : entre minuit et
 * 1 h, `today` en UTC est encore la veille, et une date du jour saisie serait refusée comme future
 * (mineur M-1 de la relecture de la préparation, 2026-10-23).
 */
final class BeninDate
{
    public const TIMEZONE = 'Africa/Porto-Novo';

    /** `Y-m-d`, pour une règle `before_or_equal:` qui refuse une date future. */
    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }
}
