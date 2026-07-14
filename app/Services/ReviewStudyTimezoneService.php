<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Unified study timezone boundary service (DEV-QO-2, ADR-0015 V2).
 *
 * Single, explicit, testable source for the learning timezone, local date,
 * and local day boundary. Replaces scattered config('app.timezone') reads
 * and Carbon::today() calls throughout the review pipeline.
 *
 * V1 contract:
 *   - Returns config('app.timezone', 'UTC'). Does NOT invent a user field.
 *   - Validates the timezone.
 *   - Does not call the DB.
 *   - Provides a single replacement point for future user-level timezone.
 *
 * Future V2 may accept a userId and read a real users.timezone column if
 * one is added. Until then, every caller gets the same global learning
 * timezone from this service.
 */
class ReviewStudyTimezoneService
{
    /**
     * Return the learning timezone.
     *
     * V1: config('app.timezone', 'UTC').
     * V2: may consult a per-user field — but only after a real column exists.
     */
    public function getStudyTimezone(): string
    {
        $tz = config('app.timezone');

        // Treat null/empty as UTC — never crash on misconfiguration.
        if (!is_string($tz) || $tz === '') {
            return 'UTC';
        }

        // Validate that this is a real IANA timezone.
        try {
            new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            return 'UTC';
        }

        return $tz;
    }

    /**
     * Return the local date (Y-m-d) for a given Carbon instant.
     *
     * The Carbon may be stored in any timezone (commonly UTC); the returned
     * date is computed in the learning timezone.
     */
    public function localDate(Carbon $now): string
    {
        return $now->copy()->tz($this->getStudyTimezone())->format('Y-m-d');
    }

    /**
     * Return the start of the local day (00:00 in learning timezone) as a Carbon.
     *
     * Useful for ReviewLog "today" boundaries that must match the Queue Order
     * local date. The returned Carbon is in the learning timezone.
     */
    public function dayStart(Carbon $now): Carbon
    {
        $tz = $this->getStudyTimezone();
        $localNow = $now->copy()->tz($tz);
        return Carbon::create(
            (int) $localNow->format('Y'),
            (int) $localNow->format('m'),
            (int) $localNow->format('d'),
            0,
            0,
            0,
            $tz
        );
    }

    /** @return array{timezone:string, study_date:string, day_start:Carbon, next_day_start:Carbon} */
    public function dayBounds(Carbon $now): array
    {
        $dayStart = $this->dayStart($now);

        return [
            'timezone' => $this->getStudyTimezone(),
            'study_date' => $dayStart->format('Y-m-d'),
            'day_start' => $dayStart,
            'next_day_start' => $dayStart->copy()->addDay()->startOfDay(),
        ];
    }
}
