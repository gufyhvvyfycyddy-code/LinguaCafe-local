<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TIMESTAMP_MIN = '1970-01-01 00:00:01';
    private const TIMESTAMP_MAX = '2038-01-19 03:14:07';

    public function up(): void
    {
        $this->withUtcSession(function () {
            Schema::table('review_cards', function (Blueprint $table) {
                $table->dateTime('fsrs_due_at')->nullable()->change();
            });

            Schema::table('review_logs', function (Blueprint $table) {
                $table->dateTime('previous_due_at')->nullable()->change();
                $table->dateTime('new_due_at')->nullable()->change();
            });
        });
    }

    public function down(): void
    {
        $this->withUtcSession(function () {
            if ($this->hasOutOfTimestampRangeDueDate()) {
                throw new LogicException('Cannot narrow FSRS due dates back to TIMESTAMP while values exist outside the MySQL TIMESTAMP range.');
            }

            Schema::table('review_cards', function (Blueprint $table) {
                $table->timestamp('fsrs_due_at')->nullable()->change();
            });

            Schema::table('review_logs', function (Blueprint $table) {
                $table->timestamp('previous_due_at')->nullable()->change();
                $table->timestamp('new_due_at')->nullable()->change();
            });
        });
    }

    private function withUtcSession(\Closure $operation): void
    {
        $originalTimeZone = (string) (DB::selectOne('SELECT @@SESSION.time_zone AS time_zone')->time_zone ?? 'SYSTEM');
        DB::statement("SET SESSION time_zone = '+00:00'");

        try {
            $operation();
        } finally {
            DB::statement('SET SESSION time_zone = ?', [$originalTimeZone]);
        }
    }

    private function hasOutOfTimestampRangeDueDate(): bool
    {
        return DB::table('review_cards')
            ->whereNotNull('fsrs_due_at')
            ->where(function ($query) {
                $query->where('fsrs_due_at', '<', self::TIMESTAMP_MIN)
                    ->orWhere('fsrs_due_at', '>', self::TIMESTAMP_MAX);
            })
            ->exists()
            || DB::table('review_logs')
                ->where(function ($query) {
                    $query->where(function ($due) {
                        $due->whereNotNull('previous_due_at')
                            ->where(function ($range) {
                                $range->where('previous_due_at', '<', self::TIMESTAMP_MIN)
                                    ->orWhere('previous_due_at', '>', self::TIMESTAMP_MAX);
                            });
                    })->orWhere(function ($due) {
                        $due->whereNotNull('new_due_at')
                            ->where(function ($range) {
                                $range->where('new_due_at', '<', self::TIMESTAMP_MIN)
                                    ->orWhere('new_due_at', '>', self::TIMESTAMP_MAX);
                            });
                    });
                })
                ->exists();
    }
};
