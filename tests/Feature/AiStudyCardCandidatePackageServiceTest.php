<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use App\Services\AiStudyCardCandidatePackageService;
use App\Services\AiStudyCardPendingLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiStudyCardCandidatePackageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_owner_builds_exact_safe_preview_package_for_current_pending_items(): void
    {
        [$user, $chapter] = $this->fixture();
        $item = $this->pending($user, $chapter, 'landscape', 'landscape');
        $result = (new AiStudyCardCandidatePackageService())->buildPreviewPackage($user, [$item->id]);

        $this->assertTrue($result['success']);
        $this->assertSame('ai-study-card-preview-package-v1', $result['package']['schema_version']);
        $this->assertSame($item->id, $result['package']['selected_items'][0]['item_id']);
        $this->assertSame('landscape', $result['package']['selected_items'][0]['lemma']);
        $this->assertTrue($result['package']['safety_flags']['no_ai_called']);
        $this->assertTrue($result['package']['generation_rules']['user_confirmation_required_before_generation']);
        $this->assertSame('已生成安全预览包（未调用 AI，未生成复习卡）。', $result['message']);
    }

    public function test_direct_owner_preserves_final_package_normalization_and_dedupe(): void
    {
        [$user, $chapter] = $this->fixture();
        $item = $this->pending($user, $chapter, 'landscape', 'landscape');
        $result = (new AiStudyCardCandidatePackageService())->buildFinalCandidatesPackage($user, [
            'selected_item_ids' => [$item->id],
            'selected_ai_recommendations' => [
                ['word' => 'Landscape', 'lemma' => 'landscape', 'reason' => 'duplicate'],
                ['word' => 'Agency', 'lemma' => 'agency', 'reason' => ' context '],
                ['word' => 'agency', 'lemma' => 'agency', 'reason' => 'duplicate'],
            ],
            'unselected_ai_recommendations' => [
                ['word' => 'Mediation', 'lemma' => 'mediation'],
                ['word' => 'agency', 'lemma' => 'agency'],
            ],
            'source_preview_package' => ['schema_version' => 'ai-study-card-preview-package-v1'],
        ]);

        $package = $result['package'];
        $this->assertTrue($result['success']);
        $this->assertSame('ai-study-card-final-candidates-v1', $package['schema_version']);
        $this->assertSame('ai-study-card-preview-package-v1', $package['source_preview_package_schema_version']);
        $this->assertSame(['agency'], array_column($package['ai_recommended_selected_items'], 'lemma'));
        $this->assertSame(['mediation'], array_column($package['ai_recommended_unselected_items'], 'lemma'));
        $this->assertSame('context', $package['ai_recommended_selected_items'][0]['reason']);
        $this->assertSame(1, $package['dedupe_summary']['dropped_duplicate_with_user']);
        $this->assertSame(1, $package['dedupe_summary']['dropped_ai_internal_duplicate']);
        $this->assertTrue($package['dedupe_summary']['backend_deduplication_applied']);
    }

    public function test_direct_owner_keeps_isolation_limits_and_coordinator_delegation(): void
    {
        [$user, $chapter] = $this->fixture();
        [$otherUser] = $this->fixture('candidate-package-other@example.test');
        $item = $this->pending($user, $chapter, 'landscape', 'landscape');
        $service = new AiStudyCardCandidatePackageService();

        $this->assertSame(404, $service->buildPreviewPackage($otherUser, [$item->id])['status']);
        $this->assertSame(422, $service->buildPreviewPackage($user, [])['status']);
        $this->assertSame(422, $service->buildPreviewPackage($user, range(1, 101))['status']);

        $source = file_get_contents(app_path('Services/AiStudyCardPendingItemService.php'));
        $this->assertStringContainsString(
            'private AiStudyCardCandidatePackageService $candidatePackageService;',
            $source,
        );
        $this->assertStringContainsString(
            'return $this->candidatePackageService->buildPreviewPackage($user, $itemIds);',
            $source,
        );
        $this->assertStringContainsString(
            'return $this->candidatePackageService->buildFinalCandidatesPackage($user, $payload);',
            $source,
        );
        $this->assertStringNotContainsString('ai-study-card-preview-package-v1', $source);
        $this->assertStringNotContainsString('private function dedupeKey(', $source);
    }

    private function fixture(string $email = 'candidate-package-owner@example.test'): array
    {
        $user = User::forceCreate([
            'name' => 'Candidate Package Owner',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Candidate Package Book',
            'language' => 'english',
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Candidate Package Chapter',
            'language' => 'english',
            'raw_text' => 'The landscape changed.',
            'word_count' => 3,
            'read_count' => 0,
            'unique_words' => '["the","landscape","changed"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        return [$user, $chapter];
    }

    private function pending(User $user, Chapter $chapter, string $word, string $lemma)
    {
        return (new AiStudyCardPendingLifecycleService())->createOrGetPending($user, [
            'chapter_id' => $chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'sentence_id' => '0',
            'word' => $word,
            'surface' => $word,
            'lemma' => $lemma,
            'sentence_text' => 'The landscape changed.',
            'source_payload' => [],
        ])['item'];
    }
}
