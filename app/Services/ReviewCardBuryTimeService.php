<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Computes the buried_until timestamp for review card bury actions (ADR-0010).
 *
 * Bury is temporary: the card auto-reverts to Active at the user's local
 * next natural-day 00:00. No scheduled job is required — the queue query
 * treats buried_until <= now as Active.
 *
 * The frontend never computes buried_until. It always sends action=bury
 * and the backend computes the timestamp via this service.
 */
class ReviewCardBuryTimeService
{
    /**
     * Compute the UTC instant of the user's next local midnight.
     *
     * @param  string   $timezone IANA timezone (e.g., 'Asia/Shanghai')
     * @param  Carbon   $now      Current UTC time
     * @return Carbon   UTC Carbon representing the user's next local 00:00
     *
     * @throws InvalidArgumentException if the timezone is invalid
     */
    public function nextLocalDayBoundary(string $timezone, Carbon $now): Carbon
    {
        if (!$this->isValidTimezone($timezone)) {
            throw new InvalidArgumentException("Invalid timezone: {$timezone}");
        }

        // Normalize empty string and 'Z' to 'UTC' so Carbon tz() doesn't throw.
        if ($timezone === '' || $timezone === 'Z') {
            $timezone = 'UTC';
        }

        // Convert the current UTC time to the user's local time.
        $localNow = $now->copy()->tz($timezone);

        // Compute the next local midnight (start of next day).
        $localNextMidnight = $localNow->copy()->startOfDay()->addDay();

        // If it's already exactly midnight, we still go to the NEXT midnight
        // (bury always hides until at least the next day boundary).
        if ($localNow->isStartOfDay()) {
            $localNextMidnight = $localNow->copy()->addDay()->startOfDay();
        }

        // Convert back to UTC and return as a Carbon instance (not immutable)
        // to match the rest of the codebase's expectations.
        return Carbon::instance($localNextMidnight)->tz('UTC');
    }

    /**
     * Validate that a timezone string is a recognized IANA timezone.
     */
    public function isValidTimezone(string $timezone): bool
    {
        if ($timezone === '' || $timezone === 'UTC' || $timezone === 'Z') {
            return true;
        }

        try {
            new \DateTimeZone($timezone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Convenience: compute buried_until for the current request.
     *
     * @param  string   $timezone
     * @param  Carbon|null $now  defaults to now
     * @return Carbon
     */
    public function buryUntil(string $timezone, ?Carbon $now = null): Carbon
    {
        return $this->nextLocalDayBoundary($timezone, $now ?? Carbon::now('UTC'));
    }
}
