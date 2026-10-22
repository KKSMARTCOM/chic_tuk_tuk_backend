<?php

namespace App\Domains\Content\Domain;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * L'ordre des rubriques et des questions de la FAQ (spec 2026-10-22-faq-proprietaire, §3) :
 * des positions de 0 à n−1, sans trou, et un échange avec la voisine pour monter ou descendre.
 */
final class FaqPositions
{
    /** Renumérote de 0 à n−1, dans l'ordre actuel. */
    public static function compact(Builder $siblings): void
    {
        foreach ($siblings->orderBy('position')->get() as $position => $item) {
            if ($item->position !== $position) {
                $item->forceFill(['position' => $position])->save();
            }
        }
    }

    /** Échange avec la voisine ; aux bornes, rien ne bouge. Rend vrai si l'ordre a changé. */
    public static function move(Model $item, Builder $siblings, string $direction): bool
    {
        $neighbour = $direction === 'up'
            ? (clone $siblings)->where('position', '<', $item->position)->orderByDesc('position')->first()
            : (clone $siblings)->where('position', '>', $item->position)->orderBy('position')->first();
        if (! $neighbour) {
            return false;
        }

        [$a, $b] = [$item->position, $neighbour->position];
        $item->forceFill(['position' => $b])->save();
        $neighbour->forceFill(['position' => $a])->save();

        return true;
    }

    public static function next(Builder $siblings): int
    {
        return ((int) $siblings->max('position')) + ($siblings->exists() ? 1 : 0);
    }
}
