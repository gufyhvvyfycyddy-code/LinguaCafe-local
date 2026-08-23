<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\LearningHistoryExportService;
use App\Services\LearningHistoryQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningHistoryExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));
        $this->user = User::forceCreate([
            'name' => 'Export User', 'email' => 'history-export@example.test',
            'password' => Hash::make('password'), 'selected_language' => 'english',
            'password_changed' => true, 'uuid' => (string) Str::uuid(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_csv_txt_and_pdf_render_the_same_complete_ordered_rowset_with_utf8(): void
    {
        [$learningKey, $reviewKey] = $this->events();
        $query = 'date_from=2026-07-14&date_to=2026-07-14';

        $csvResponse = $this->actingAs($this->user)->get('/learning-history/export/csv?'.$query)
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment; filename="learning-history-2026-07-14-to-2026-07-14.csv"', $csvResponse->headers->get('content-disposition'));
        $csv = $csvResponse->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $csvRows = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', substr($csv, 3), -1, PREG_SPLIT_NO_EMPTY));
        $headers = array_shift($csvRows);
        $eventKeyIndex = array_search('event_key', $headers, true);
        $lemmaIndex = array_search('lemma', $headers, true);
        $senseZhIndex = array_search('sense_zh', $headers, true);
        $this->assertSame([$reviewKey, $learningKey], array_column($csvRows, $eventKeyIndex));
        $this->assertSame("'=2+2", $csvRows[0][$lemmaIndex]);
        $this->assertSame('中文释义', $csvRows[0][$senseZhIndex]);

        $txt = $this->actingAs($this->user)->get('/learning-history/export/txt?'.$query)
            ->assertOk()->assertHeader('content-type', 'text/plain; charset=UTF-8')->getContent();
        $this->assertTrue(mb_check_encoding($txt, 'UTF-8'));
        $this->assertStringContainsString('中文释义', $txt);
        $this->assertLessThan(strpos($txt, $learningKey), strpos($txt, $reviewKey));

        $pdf = $this->actingAs($this->user)->get('/learning-history/export/pdf?'.$query)
            ->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(10000, strlen($pdf));

        $rowset = app(LearningHistoryQueryService::class)->all(
            $this->user->id, 'english', '2026-07-14', '2026-07-14'
        );
        $html = view('exports.learning-history', [
            'rows' => $rowset['data'], 'meta' => $rowset['meta'],
        ])->render();
        $this->assertStringContainsString('中文释义', $html);
        $this->assertLessThan(strpos($html, $learningKey), strpos($html, $reviewKey));
    }

    public function test_export_filter_validation_authentication_and_format_boundary(): void
    {
        $this->get('/learning-history/export/csv')->assertRedirect('/login');
        $this->actingAs($this->user)->getJson('/learning-history/export/csv?filter=unsupported')
            ->assertUnprocessable()->assertJsonValidationErrors(['filter']);
        $this->actingAs($this->user)->get('/learning-history/export/json')->assertNotFound();
    }

    public function test_export_renderer_has_no_business_database_owner(): void
    {
        $source = file_get_contents(app_path('Services/LearningHistoryExportService.php'));

        $this->assertStringNotContainsString('use App\\Models\\', $source);
        $this->assertStringNotContainsString('Facades\\DB', $source);
        $this->assertStringNotContainsString('LearningHistoryQueryService', $source);
        $this->assertSame(['csv', 'txt', 'pdf'], LearningHistoryExportService::FORMATS);
    }

    private function events(): array
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english',
            'lemma' => '=2+2', 'surface_form' => '=2+2', 'pos' => 'noun',
            'sense_key' => 'export-'.Str::uuid(), 'sense_zh' => '中文释义',
            'sense_en' => 'export meaning', 'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $sense->forceFill([
            'learning_started_at' => now()->setTime(9, 0),
            'learning_started_origin' => WordSense::LEARNING_ORIGIN_NON_READING,
        ])->save();
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id, 'language_id' => 'english', 'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE, 'target_id' => $sense->id,
            'fsrs_state' => 'review', 'fsrs_due_at' => now()->addDay(), 'fsrs_reps' => 2,
            'fsrs_lapses' => 0, 'fsrs_enabled' => true, 'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);
        $log = ReviewLog::forceCreate([
            'user_id' => $this->user->id, 'language_id' => 'english', 'language' => 'english',
            'review_card_id' => $card->id, 'rating' => 'good', 'reviewed_at' => now()->setTime(10, 0),
            'previous_state' => 'review', 'new_state' => 'review', 'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);

        return ['learning:'.$sense->id, 'review:'.$log->id];
    }
}
