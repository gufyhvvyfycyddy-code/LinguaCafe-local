<?php

namespace Tests\Unit;

use App\Models\EncounteredWord;
use App\Models\Phrase;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

// Require the source under test, even when an isolated worktree borrows another checkout's vendor.
require_once dirname(__DIR__, 2).'/app/Models/Setting.php';
require_once dirname(__DIR__, 2).'/app/Models/Phrase.php';
require_once dirname(__DIR__, 2).'/app/Models/EncounteredWord.php';

class LegacyReviewIntervalMissingTest extends TestCase
{
    private Capsule $db;
    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', 'UTC'));
        $this->previousResolver = Model::getConnectionResolver();

        $this->db = new Capsule();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->bootEloquent();

        $this->db->getConnection()->getSchemaBuilder()->create('settings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->text('value');
            $table->timestamps();
        });

        foreach (['phrases', 'encountered_words'] as $tableName) {
            $this->db->getConnection()->getSchemaBuilder()->create($tableName, function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('user_id');
                $table->date('next_review')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->previousResolver);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function legacyItem(string $class): Phrase|EncounteredWord
    {
        $item = new $class();
        $item->stage = -6;
        $item->user_id = 17;
        $item->language = 'english';
        $item->added_to_srs = null;
        return $item;
    }

    private function setNegativeStage(Phrase|EncounteredWord $item): void
    {
        if ($item instanceof Phrase) {
            $item->setStage(-7);
        } else {
            $item->setStage(-7, true);
        }
    }

    public function test_missing_intervals_do_not_crash_or_seed_unrelated_settings(): void
    {
        $this->assertSame([
            '-7' => [0], '-6' => [1], '-5' => [2, 3], '-4' => [6, 7, 8],
            '-3' => [15, 16, 17, 18], '-2' => [37, 38, 39, 40, 41, 42],
            '-1' => [94, 95, 96, 97, 98, 99, 100, 101],
        ], Setting::DEFAULT_REVIEW_INTERVALS);
        foreach ([Phrase::class, EncounteredWord::class] as $class) {
            $item = $this->legacyItem($class);
            $this->setNegativeStage($item);
            $this->assertSame(Carbon::now()->toDateString(), $item->next_review);
            $this->assertSame(Carbon::now()->toDateString(), $item->added_to_srs);
            $this->assertSame(0, Setting::query()->count());
        }
    }

    public function test_existing_custom_intervals_take_precedence(): void
    {
        $this->db->getConnection()->table('settings')->insert([
            'name' => 'reviewIntervals',
            'value' => json_encode(['-7' => [5]]),
        ]);

        foreach ([Phrase::class, EncounteredWord::class] as $class) {
            $item = $this->legacyItem($class);
            $this->setNegativeStage($item);
            $this->assertSame(Carbon::now()->addDays(5)->toDateString(), $item->next_review);
            $this->assertSame(1, Setting::query()->count());
        }
    }

    public function test_non_review_stage_never_queries_legacy_settings(): void
    {
        $connection = $this->db->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        foreach ([Phrase::class, EncounteredWord::class] as $class) {
            $item = $this->legacyItem($class);
            $item->next_review = '2027-01-01';
            if ($item instanceof Phrase) {
                $item->setStage(2);
            } else {
                $item->setStage(2, true);
            }
            $this->assertSame(2, $item->stage);
            $this->assertNull($item->next_review);
            $this->assertFalse($item->relearning);
        }
        $this->assertSame([], $connection->getQueryLog());
    }
}
