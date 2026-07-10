<?php

namespace Tests\Feature;

use App\Services\SenseReviewRatingContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SenseReviewRatingContractTest
 *
 * SenseReview-RatingContract-1000-1
 *
 * Verifies the single source of truth for sense-review rating metadata:
 * allowed ratings, Chinese labels, numeric scores, and fail-closed
 * handling of invalid input.
 *
 * Contract:
 *  - allowedRatings(): ['again','hard','good','easy'] (order stable).
 *  - labelFor(): again→忘了, hard→勉强, good→记得, easy→很熟.
 *  - scoreFor(): again=1, hard=2, good=3, easy=4.
 *  - isAllowed(): true for the four, false otherwise.
 *  - Invalid / null / wrong-case ratings are NOT silently accepted.
 *  - The contract is a pure value object: no DB, no Auth, no config.
 */
class SenseReviewRatingContractTest extends TestCase
{
    use RefreshDatabase;

    private SenseReviewRatingContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contract = app(SenseReviewRatingContract::class);
    }

    /**
     * 1. allowedRatings returns the four stable ratings in order.
     */
    public function test_allowed_ratings(): void
    {
        $this->assertSame(
            ['again', 'hard', 'good', 'easy'],
            $this->contract->allowedRatings(),
        );
    }

    /**
     * 2. Chinese labels for each rating.
     */
    public function test_labels(): void
    {
        $this->assertSame('忘了', $this->contract->labelFor('again'));
        $this->assertSame('勉强', $this->contract->labelFor('hard'));
        $this->assertSame('记得', $this->contract->labelFor('good'));
        $this->assertSame('很熟', $this->contract->labelFor('easy'));
    }

    /**
     * 3. Numeric scores for each rating.
     */
    public function test_scores(): void
    {
        $this->assertSame(1, $this->contract->scoreFor('again'));
        $this->assertSame(2, $this->contract->scoreFor('hard'));
        $this->assertSame(3, $this->contract->scoreFor('good'));
        $this->assertSame(4, $this->contract->scoreFor('easy'));
    }

    /**
     * 4. isAllowed true for the four, false for invalid.
     */
    public function test_is_allowed(): void
    {
        $this->assertTrue($this->contract->isAllowed('again'));
        $this->assertTrue($this->contract->isAllowed('hard'));
        $this->assertTrue($this->contract->isAllowed('good'));
        $this->assertTrue($this->contract->isAllowed('easy'));
        $this->assertFalse($this->contract->isAllowed('reset'));
        $this->assertFalse($this->contract->isAllowed('medium'));
        $this->assertFalse($this->contract->isAllowed(''));
    }

    /**
     * 5. labelFor invalid rating returns null (fail-closed, not 'good').
     */
    public function test_label_for_invalid_returns_null(): void
    {
        $this->assertNull($this->contract->labelFor('reset'));
        $this->assertNull($this->contract->labelFor('medium'));
        $this->assertNull($this->contract->labelFor(''));
    }

    /**
     * 6. scoreFor invalid rating returns null (fail-closed, not 3).
     */
    public function test_score_for_invalid_returns_null(): void
    {
        $this->assertNull($this->contract->scoreFor('reset'));
        $this->assertNull($this->contract->scoreFor('medium'));
        $this->assertNull($this->contract->scoreFor(''));
    }

    /**
     * 7. null rating is not allowed and yields null label / null score.
     */
    public function test_null_rating(): void
    {
        $this->assertFalse($this->contract->isAllowed(null));
        $this->assertNull($this->contract->labelFor(null));
        $this->assertNull($this->contract->scoreFor(null));
    }

    /**
     * 8. Wrong-case ratings are NOT silently accepted.
     */
    public function test_case_sensitivity(): void
    {
        $this->assertFalse($this->contract->isAllowed('Again'));
        $this->assertFalse($this->contract->isAllowed('GOOD'));
        $this->assertNull($this->contract->labelFor('Again'));
        $this->assertNull($this->contract->scoreFor('GOOD'));
    }

    /**
     * 9. The contract does not access the database.
     *
     * Instantiating and calling every method must issue zero SQL queries.
     */
    public function test_contract_does_not_access_database(): void
    {
        \DB::flushQueryLog();
        \DB::enableQueryLog();

        $c = new SenseReviewRatingContract();
        $c->allowedRatings();
        $c->isAllowed('again');
        $c->isAllowed('reset');
        $c->labelFor('again');
        $c->labelFor('reset');
        $c->scoreFor('again');
        $c->scoreFor('reset');

        $this->assertSame(0, count(\DB::getQueryLog()));
        \DB::disableQueryLog();
    }
}
