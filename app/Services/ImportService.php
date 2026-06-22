<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Chapter;
use App\Enums\ChapterProcessingStatusEnum;

// models
use Illuminate\Support\Facades\DB;

// services
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportService {

    // stores the python service container's name
    private $pythonService = '';
    private bool $lastImportUsedFallback = false;

    public function __construct() {
        $this->pythonService = env('PYTHON_CONTAINER_NAME', 'http://127.0.0.1:8678');
    }

    public function importBook($userId, $userUuid, $chunkSize, $eBookChapterSortMethod, $textProcessingMethod, $file, $bookId, $bookName, $chapterName) {
        DB::disableQueryLog();
        $selectedLanguage = Auth::user()->selected_language;

        $chunks = $this->postTokenizer('/tokenizer/import-book', [
            'language' => $selectedLanguage,
            'chapterSortMethod' => $eBookChapterSortMethod,
            'importFile' => $file,
            'chunkSize' => $chunkSize
        ]);

        $this->importChunks($chunks, $userId, $userUuid, $selectedLanguage, $bookName, $bookId, $chapterName);        
        
        return 'tokenizer';
    }

    public function importText($userId, $userUuid, $chunkSize, $textProcessingMethod, $importText, $bookId, $bookName, $chapterName) {
        DB::disableQueryLog();
        $selectedLanguage = Auth::user()->selected_language;

        $this->lastImportUsedFallback = false;
        try {
            $chunks = $this->postTokenizer('/tokenizer/import-text', [
                'language' => $selectedLanguage,
                'importText' => $importText,
                'chunkSize' => $chunkSize
            ]);
        } catch (\Throwable $exception) {
            if ($selectedLanguage !== 'english') {
                throw $exception;
            }

            Log::critical('Python tokenizer import-text failed; using English fallback chunking. Start the Python tokenizer service to restore normal chunking.', [
                'error' => $exception->getMessage(),
            ]);
            $chunks = $this->fallbackEnglishChunks($importText, $chunkSize);
            $this->lastImportUsedFallback = true;
        }

        $this->importChunks($chunks, $userId, $userUuid, $selectedLanguage, $bookName, $bookId, $chapterName, false, $this->lastImportUsedFallback ? 'fallback' : 'tokenizer');        

        return $this->lastImportUsedFallback ? 'fallback' : 'tokenizer';
    }

    public function importSubtitles($userId, $userUuid, $chunkSize, $textProcessingMethod, $importSubtitles, $bookId, $bookName, $chapterName) {
        DB::disableQueryLog();
        $selectedLanguage = Auth::user()->selected_language;

        $subtitles = $this->postTokenizer('/tokenizer/import-subtitles', [
            'language' => $selectedLanguage,
            'subtitles' => $importSubtitles,
            'chunkSize' => $chunkSize
        ]);

        $this->importChunks($subtitles, $userId, $userUuid, $selectedLanguage, $bookName, $bookId, $chapterName, true);

        return 'tokenizer';
    }

    /*
    
        Imports chunks fo raw and tokenized texts. This function
        is used by other import functions to avoid code dupication.
    */
    private function importChunks($chunks, $userId, $userUuid, $language, $bookName, $bookId, $chapterName, $isSubtitle = false, $processingMode = 'tokenizer') {
        if (!is_array($chunks) || count($chunks) === 0) {
            throw new \Exception('文本处理服务没有返回可导入的章节内容。');
        }

        // retrieve or create book
        if ($bookId == -1) {
            $book = new Book();
            $book->user_id = $userId;
            $book->cover_image = null;
            $book->language = $language;
            $book->name = $bookName;
            $book->save();
        } else {
            $book = Book
                ::where('user_id', $userId)
                ->where('id', $bookId)
                ->first();
            
            if (!$book) {
                throw new \Exception('阅读材料不存在，或不属于当前用户。');
            }
        }

        // import each chunk as a chapter
        foreach ($chunks as $chunkIndex => $chunk) {
            $chapterNameCalculated = count($chunks) > 1 ? $chapterName . ' ' . ($chunkIndex + 1) : $chapterName;

            $chapter = new Chapter();
            $chapter->user_id = $userId;
            $chapter->name = $chapterNameCalculated;
            $chapter->processing_status = ChapterProcessingStatusEnum::UNPROCESSED->value;
            $chapter->read_count = 0;
            $chapter->word_count = 0;
            $chapter->book_id = $book->id;
            $chapter->language = $language;
            $chapter->unique_words = '';
            $chapter->subtitle_timestamps = '';
            $chapter->type = $isSubtitle ? 'subtitle' : 'text';
            $chapter->raw_text = $isSubtitle ? json_encode($chunk) : $chunk;
            if (property_exists($chapter, 'processing_mode') || \Illuminate\Support\Facades\Schema::hasColumn('chapters', 'processing_mode')) {
                $chapter->processing_mode = $processingMode;
            }
            $chapter->save();
            
            \App\Jobs\ProcessChapter::dispatch($userId, $userUuid, $chapter->id, $language);
        }

        return true;
    }

    public function getYoutubeSubtitles($url) {
        return $this->postTokenizer('/tokenizer/get-youtube-subtitle-list', [
            'url' => $url,
        ]);
    }

    public function getSubtitleFileContent($fileName) {
        return $this->postTokenizer('/tokenizer/get-subtitle-file-content', [
            'fileName' => $fileName,
        ]);
    }

    public function getWebsiteText($url) {
        return $this->postTokenizer('/tokenizer/get-website-text', [
            'url' => $url,
        ]);
    }

    private function postTokenizer(string $path, array $payload)
    {
        try {
            $response = Http::timeout(30)->post($this->pythonServiceUrl() . $path, $payload);
        } catch (\Throwable $exception) {
            throw new \Exception('文本处理服务不可用，请确认 Python tokenizer 服务已经启动。');
        }

        if (!$response->successful()) {
            throw new \Exception('文本处理服务返回错误：' . $response->status());
        }

        $decoded = json_decode($response->body());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('文本处理服务返回了无效 JSON。');
        }

        return $decoded;
    }

    private function pythonServiceUrl(): string
    {
        if (str_starts_with($this->pythonService, 'http://') || str_starts_with($this->pythonService, 'https://')) {
            return rtrim($this->pythonService, '/');
        }

        return 'http://' . rtrim($this->pythonService, '/') . ':8678';
    }

    private function fallbackEnglishChunks(string $text, int $chunkSize): array
    {
        $normalized = preg_replace("/\r\n|\r|\n/", " ", $text);
        $normalized = preg_replace('/\s+/', ' ', trim((string) $normalized));

        if ($normalized === '') {
            throw new \Exception('没有可导入的英文文本。');
        }

        preg_match_all('/[^.!?]+[.!?]?/u', $normalized, $matches);
        $sentences = array_values(array_filter(array_map('trim', $matches[0] ?? [])));

        if (count($sentences) === 0) {
            $sentences = [$normalized];
        }

        $chunks = [];
        foreach ($sentences as $sentence) {
            if ($sentence === '') {
                continue;
            }

            $candidate = count($chunks) ? trim($chunks[count($chunks) - 1] . ' ' . $sentence) : $sentence;
            if (count($chunks) === 0 || mb_strlen($candidate) > $chunkSize) {
                $chunks[] = $sentence;
            } else {
                $chunks[count($chunks) - 1] = $candidate;
            }
        }

        return $chunks;
    }
}
