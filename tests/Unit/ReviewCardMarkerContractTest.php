<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use PHPUnit\Framework\TestCase;

class ReviewCardMarkerContractTest extends TestCase
{
    public function test_marker_values_are_stable_and_contiguous(): void
    {
        $this->assertSame(0, ReviewCard::MARKER_NONE);
        $this->assertSame(1, ReviewCard::MARKER_RED);
        $this->assertSame(2, ReviewCard::MARKER_ORANGE);
        $this->assertSame(3, ReviewCard::MARKER_GREEN);
        $this->assertSame(4, ReviewCard::MARKER_BLUE);
        $this->assertSame(5, ReviewCard::MARKER_PINK);
        $this->assertSame(6, ReviewCard::MARKER_TURQUOISE);
        $this->assertSame(7, ReviewCard::MARKER_PURPLE);
        $this->assertSame(range(0, 7), ReviewCard::MARKERS);
    }

    public function test_marker_is_integer_cast_but_not_generically_fillable(): void
    {
        $card = new ReviewCard();

        $this->assertSame('integer', $card->getCasts()['marker']);
        $this->assertNotContains('marker', $card->getFillable());
    }
}
