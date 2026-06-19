<?php

namespace App\Console\Commands;

use App\Services\VocabularyService;
use Illuminate\Console\Command;

class CleanupVocabularyTokens extends Command
{
    protected $signature = 'vocabulary:cleanup-tokens
        {--user_id= : User id}
        {--language= : Study language}
        {--dry-run : Only show what would be ignored}';

    protected $description = 'Ignore invalid vocabulary tokens such as numbers, percentages, punctuation fragments, and contraction fragments.';

    public function handle(VocabularyService $vocabularyService): int
    {
        $userId = (int) $this->option('user_id');
        $language = (string) $this->option('language');
        $dryRun = (bool) $this->option('dry-run');

        if ($userId <= 0 || $language === '') {
            $this->error('必须提供 --user_id 和 --language。');
            return self::FAILURE;
        }

        $result = $vocabularyService->cleanupInvalidTokens($userId, $language, $dryRun);

        $this->info($dryRun ? 'Dry-run：不会写入数据库。' : '已将匹配词条标为忽略。');
        $this->line('匹配数量：' . $result['matched_count']);
        $this->line('示例：' . (count($result['examples']) ? implode(', ', $result['examples']) : '无'));

        return self::SUCCESS;
    }
}
