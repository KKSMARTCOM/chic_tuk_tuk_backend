<?php

namespace Tests\Unit\Booking;

use App\Domains\Booking\Domain\PlaceText;
use PHPUnit\Framework\TestCase;

/** La comparaison des noms de lieux (spec 2026-10-27-recherche-de-lieux, §4.2). */
class PlaceTextTest extends TestCase
{
    public function test_normalize_drops_case_accents_and_extra_spaces(): void
    {
        $this->assertSame('fidjrosse plage', PlaceText::normalize('  FIDJROSSÈ   Plage '));
    }

    public function test_match_rank_prefers_the_start_of_the_name(): void
    {
        $this->assertSame(0, PlaceText::matchRank('compl', 'Complexe scolaire Les Cracks'));
        $this->assertSame(1, PlaceText::matchRank('crac', 'Complexe scolaire Les Cracks'));
        $this->assertNull(PlaceText::matchRank('racks', 'Complexe scolaire Les Cracks'));
    }

    public function test_match_rank_splits_on_punctuation(): void
    {
        $this->assertSame(1, PlaceText::matchRank('calavi', 'Abomey-Calavi'));
        $this->assertSame(0, PlaceText::matchRank('Abomey Cal', 'Abomey-Calavi'));
    }

    public function test_distance_meters(): void
    {
        $d = PlaceText::distanceMeters(6.3703, 2.3912, 6.3730, 2.3912);
        $this->assertGreaterThan(290, $d);
        $this->assertLessThan(310, $d);
    }
}
