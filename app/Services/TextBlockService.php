<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\Phrase;
use App\Services\VocabularyTokenFilter;

/*
    This class contains functions to transfrom plain text
    into format that can be handled by TextBlockGroup vue 
    component or saved to the database.

    Example for function order to turn raw text into interactive text:
    $textBlock = new TextBlockService();
    $textBlock->rawText = $rawText;
    $textBlock->tokenizeRawText();
    $textBlock->processTokenizedWords();
    $textBlock->updateAllPhraseIds();
    $textBlock->collectUniqueWords();
    $textBlock->createNewEncounteredWords();
    $textBlock->prepareTextForReader();
    $textBlock->indexPhrases();
    $textBlockDataForVueComponent = $textBlock->getReaderData();
*/
class TextBlockService
{
    use HasFactory;

    public $language = '';
    public $userId = -1;

    private ReaderDataService $readerDataService;
    private EnglishFallbackTokenizerService $englishFallbackTokenizer;

    /*
        This variable contains raw untokenized text. 
    */
    public $rawText = '';

    /*
        This variable contains unprocessed tokenized words coming from 
        python tokenizer service. It will require further processing
        by processTokenizedWords() function before it can be saved into
        the database.
    */
    public $tokenizedWords = [];

    /*
        This variable contains words after they were processed by
        processTokenizedWords() function. This function mostly just
        combines multiple tokens into one in specific languages
        like japanese where the tokenizer doesn't separate 
        words as expected.
    */
    public $processedWords = [];

    /*
        These variables are in a form required by TextBlockGroup vue 
        component. They are created by prepareTextForReader() function,
        and can be directly passed through as props for the TextBlockGroup 
        component to be displayed as interactive text. They can be 
        retrieved as on object by getReaderData() function.
    */
    public $words = [];
    public $uniqueWords = [];
    public $phrases = [];

    // stores the python service container's name
    private $pythonService;

    function __construct($userId, $language) {
        $this->userId = $userId;
        $this->language = $language;
        $this->readerDataService = new ReaderDataService($userId, $language);
        $this->englishFallbackTokenizer = new EnglishFallbackTokenizerService();
        $this->pythonService = env('PYTHON_CONTAINER_NAME', 'http://127.0.0.1:8678');
    }

    /* 
        A setter function for $processedWords. It also checks
        and decodes phrase ids if they are still in json format.
    */
    public function setProcessedWords($processedWords) {
        $this->processedWords = $processedWords;

        if (count($processedWords) > 0 && gettype($processedWords[0]->phrase_ids) == 'string') {
            foreach ($this->processedWords as $word) {
                $word->phrase_ids = json_decode($word->phrase_ids);
            }
        }
    }

    /*
        Returns word count excluding words which should
        be skipped (specialc characters mostly).
    */
    public function getWordCount() {
        $wordsToSkip = config('linguacafe.words_to_skip');      
        $wordCount = 0;
        foreach ($this->processedWords as $word) {
            if (!in_array($word->word, $wordsToSkip, true) && !VocabularyTokenFilter::shouldSkip($word->word, $this->language)) {
                $wordCount ++;
            }
        }

        return $wordCount;
    }

    /* 
        Sends the raw text to python tokenizer service, and stores the result.
    */
    /*
     * 使用不会被任何 tokenizer 拆散的标记代替段落/换行结构，
     * tokenize 之后再统一替换为结构 token（type=STRUCT）。
     * 标记全大写字母，不含下划线或标点，spaCy/fallback 都视为单个 token。
     */
    private const MARKER_PARA = 'ZZPARAZZ';
    private const MARKER_NEWL = 'ZZNEWLZZ';
    private const MARKER_SECT_PREFIX = 'ZZSECT';

    public function tokenizeRawText() {
        $text = $this->rawText;

        // 1. 换行 → tokenizer 安全标记
        $text = str_replace(["\r\n\r\n", "\n\n", "\r\n", "\r", "\n"],
            [' ' . self::MARKER_PARA . ' ', ' ' . self::MARKER_PARA . ' ',
             ' ' . self::MARKER_NEWL . ' ', ' ' . self::MARKER_NEWL . ' ', ' ' . self::MARKER_NEWL . ' '],
            $text);
        $text = preg_replace('/ {2,}/', ' ', $text);

        // 2. [A]-[Z] 段落标记 → 安全标记，并确保前方有段落分隔
        $text = preg_replace_callback(
            '/\s*\[([A-Z])\]\s*/u',
            fn ($m) => ' ' . self::MARKER_SECT_PREFIX . $m[1] . 'Z ',
            $text
        );
        // 紧跟在普通词后方的 section marker 前插入段落分隔
        $text = preg_replace(
            '/(\S)\s+(' . self::MARKER_SECT_PREFIX . '[A-Z]Z)/u',
            '$1 ' . self::MARKER_PARA . ' $2',
            $text
        );
        // 合并连续段落标记
        $text = preg_replace('/(' . self::MARKER_PARA . '\s+)+/', self::MARKER_PARA . ' ', $text);

        // 3. Tokenize
        try {
            $tokens = $this->postTokenizer('/tokenizer', [
                'raw_text' => $text,
                'language' => $this->language,
            ]);
        } catch (\Throwable $exception) {
            if ($this->language !== 'english') {
                throw $exception;
            }

            Log::critical('Python tokenizer unavailable; English import proceeding WITHOUT lemmatization. Start the Python tokenizer service to restore normal lemmatization.', [
                'user_id' => $this->userId,
                'error' => $exception->getMessage(),
            ]);
            $this->tokenizerDegraded = true;
            $tokens = $this->fallbackEnglishTokenize($text);
        }

        // 4. 后处理：将安全标记替换为结构 token（不再依赖前端字符串匹配）
        $this->tokenizedWords = $this->mapStructuralTokens($tokens);
    }

    /**
     * 将 tokenizer 返回的安全标记 ZZPARAZZ / ZZNEWLZZ / ZZSECTxZ
     * 替换为显式结构 token（pos = 'STRUCT'），前端/过滤层据此识别。
     */
    private function mapStructuralTokens(array $tokens): array
    {
        $result = [];
        foreach ($tokens as $token) {
            $w = $token->w ?? '';

            if ($w === self::MARKER_PARA) {
                $st = clone $token;
                $st->w = 'PARAGRAPH_BREAK';
                $st->l = 'PARAGRAPH_BREAK';
                $st->pos = 'STRUCT';
                $result[] = $st;
            } elseif ($w === self::MARKER_NEWL) {
                $st = clone $token;
                $st->w = 'NEWLINE';
                $st->l = 'NEWLINE';
                $st->pos = 'STRUCT';
                $result[] = $st;
            } elseif (preg_match('/^' . self::MARKER_SECT_PREFIX . '([A-Z])Z$/', $w, $m)) {
                $st = clone $token;
                $st->w = '[' . $m[1] . ']';
                $st->l = '[' . $m[1] . ']';
                $st->pos = 'STRUCT';
                $result[] = $st;
            } else {
                $result[] = $token;
            }
        }
        return $result;
    }

    public function tokenizeRawSubtitles() {
        $tokenizerResponse = $this->postTokenizer('/tokenizer/subtitle', [
            'subtitles' => $this->rawText,
            'language' => $this->language,
        ]);

        $this->tokenizedWords = $tokenizerResponse->tokenizedText;
        return $tokenizerResponse->timeStamps;
    }

    /* 
        Loops through the list of words returned by python tokenizer
        and creates a list of processed words in a format that can 
        be saved into the database. This function can be skipped, if
        data is already coming from database and has already been 
        processed.
    */
    public function processTokenizedWords() {
        $this->processedWords = [];
        $processedWordCount = 0;
        $wordCount = count($this->tokenizedWords);
        $wordsToSkip = config('linguacafe.words_to_skip');

        for ($wordIndex = 0; $wordIndex < $wordCount; $wordIndex++) {
            $word = new \stdClass();
            $word->user_id = $this->userId;
            $word->word_index = $wordIndex;
            $word->sentence_index = $this->tokenizedWords[$wordIndex]->si;
            $word->word = $this->tokenizedWords[$wordIndex]->w;
            $word->lemma = $this->tokenizedWords[$wordIndex]->l;
            if ($this->language == 'japanese' || $this->language == 'chinese') {
                $word->reading = $this->tokenizedWords[$wordIndex]->r;
                $word->lemma_reading = $this->tokenizedWords[$wordIndex]->lr;
            } else {
                $word->reading = '';
                $word->lemma_reading = '';
            }
            
            $word->pos = $this->tokenizedWords[$wordIndex]->pos;
            $word->is_structure = ($this->tokenizedWords[$wordIndex]->pos === 'STRUCT');
            $word->phrase_ids = [];

            // japanese post processing
            if ($this->language == 'japanese' && $wordIndex < $wordCount - 1 && !in_array($word->word, $wordsToSkip, true) && !in_array($this->tokenizedWords[$wordIndex + 1]->w, $wordsToSkip, true)) {
                // combine 2 verbs after eachother into one word
                if ($this->tokenizedWords[$wordIndex]->pos == 'VERB' && $this->tokenizedWords[$wordIndex + 1]->pos == 'VERB') {
                    $wordIndex ++;
                    $word->word .= $this->tokenizedWords[$wordIndex]->w;
                    $word->reading .= $this->tokenizedWords[$wordIndex]->r;
                    $word->lemma_reading = $this->tokenizedWords[$wordIndex - 1]->r . $this->tokenizedWords[$wordIndex]->lr;
                    $word->lemma = $this->tokenizedWords[$wordIndex - 1]->w . $this->tokenizedWords[$wordIndex]->l;
                }
                
                // Combine VERB + AUX and VERB + SCONJ. It's more logical for the user.
                if ($this->tokenizedWords[$wordIndex]->pos == 'VERB' && $this->tokenizedWords[$wordIndex]->w !== $this->tokenizedWords[$wordIndex]->l && $this->tokenizedWords[$wordIndex + 1]->pos == 'AUX') {
                    do {
                        $wordIndex ++;

                        if ($wordIndex === $wordCount) {
                            break;
                        }

                        if ($this->tokenizedWords[$wordIndex]->pos == 'AUX') {
                            $word->word .= $this->tokenizedWords[$wordIndex]->w;
                            $word->reading .= $this->tokenizedWords[$wordIndex]->r;
                        } else {
                            $wordIndex --; break;
                        }
                    } while($this->tokenizedWords[$wordIndex]->pos == 'AUX');
                } else if ($this->tokenizedWords[$wordIndex]->pos == 'VERB' && $this->tokenizedWords[$wordIndex]->w !== $this->tokenizedWords[$wordIndex]->l && $this->tokenizedWords[$wordIndex + 1]->pos == 'SCONJ') {
                    do {
                        $wordIndex ++;
                        
                        if ($wordIndex === $wordCount) {
                            break;
                        }

                        if ($this->tokenizedWords[$wordIndex]->pos == 'SCONJ') {
                            $word->word .= $this->tokenizedWords[$wordIndex]->w;
                            $word->reading .= $this->tokenizedWords[$wordIndex]->r;
                        } else {
                            $wordIndex --; break;
                        }
                    } while($this->tokenizedWords[$wordIndex]->pos == 'SCONJ');
                }
            }

            // norwegian post processing
            if ($this->language == 'norwegian') { 
                // only verbs, nouns and adjenctives need lemma
                if ($this->tokenizedWords[$wordIndex]->pos !== 'VERB' && 
                    $this->tokenizedWords[$wordIndex]->pos !== 'NOUN' &&
                    $this->tokenizedWords[$wordIndex]->pos !== 'ADJ') {
                         $word->lemma = '';
                }

                // verbs' lemma needs an å character before them
                if ($this->tokenizedWords[$wordIndex]->pos == 'VERB' && $this->tokenizedWords[$wordIndex]->l !== '') {
                    $word->lemma = 'å ' . $word->lemma;
                }

                // nouns' lemma needs ei/en/et before them
                if ($this->tokenizedWords[$wordIndex]->pos == 'NOUN' && $this->tokenizedWords[$wordIndex]->l !== '') {
                    if (count($this->tokenizedWords[$wordIndex]->g) && $this->tokenizedWords[$wordIndex]->g[0] =='Fem') {
                        $word->lemma = 'ei ' . $word->lemma;
                    }

                    if (count($this->tokenizedWords[$wordIndex]->g) && $this->tokenizedWords[$wordIndex]->g[0] == 'Masc') {
                        $word->lemma = 'en ' . $word->lemma;
                    }

                    if (count($this->tokenizedWords[$wordIndex]->g) && $this->tokenizedWords[$wordIndex]->g[0] == 'Neut') {
                        $word->lemma = 'et ' . $word->lemma;
                    }
                    
                }
            }

            // german post processing
            if ($this->language == 'german') { 
                // nouns' lemma needs der/die/das before them
                if ($this->tokenizedWords[$wordIndex]->pos == 'NOUN' && $this->tokenizedWords[$wordIndex]->l !== '') {
                    if (count($this->tokenizedWords[$wordIndex]->g) && $this->tokenizedWords[$wordIndex]->g[0] =='Fem') {
                        $word->lemma = 'die ' . $word->lemma;
                    }

                    if (count($this->tokenizedWords[$wordIndex]->g) && $this->tokenizedWords[$wordIndex]->g[0] == 'Masc') {
                        $word->lemma = 'der ' . $word->lemma;
                    }

                    if (count($this->tokenizedWords[$wordIndex]->g) && $this->tokenizedWords[$wordIndex]->g[0] == 'Neut') {
                        $word->lemma = 'das ' . $word->lemma;
                    }
                    
                }
            }

            // german post processing
            if ($this->language == 'korean') { 
                // nouns' lemma needs der/die/das before them
                $word->lemma = str_replace('+', '', $word->lemma);
            }

            // limit text length
            if (mb_strlen($word->word) > 255) {
                continue;
            }

            $word->lemma = mb_strlen($word->lemma) > 255 ? mb_substr($word->lemma, 0, 255) : $word->lemma;
            $word->reading = mb_strlen($word->reading) > 255 ? mb_substr($word->reading, 0, 255) : $word->reading;
            $word->lemma_reading = mb_strlen($word->lemma_reading) > 255 ? mb_substr($word->lemma_reading, 0, 255) : $word->lemma_reading;
            
            $this->processedWords[$processedWordCount] = $word; 
            $processedWordCount ++;
        }
    }

    /*
        This function creates records in encountered_words 
        database table for each new word that the user 
        encounters for the first time.

        Delegates to EncounteredWordCreationService for the
        actual write logic. The public facade signature and
        external behaviour are unchanged.
    */
    public function createNewEncounteredWords() {
        app(EncounteredWordCreationService::class)->create(
            $this->userId,
            $this->language,
            $this->processedWords,
            $this->uniqueWords
        );
    }

    public function collectUniqueWords() {
        $this->uniqueWords = $this->readerDataService->collectUniqueWords($this->processedWords);
    }

    function updateAllPhraseIds() {
        $phrases = Phrase
            ::where('user_id', $this->userId)
            ->where('language', $this->language)
            ->get();
        
        foreach($phrases as $phrase) {
            $this->updatePhraseIds($phrase);
        }
    }

    /* 
        This function loops through the words of the TextBlock
        and tags them if they are part of the phrase given
        as an argument.
    */
    function updatePhraseIds($phrase) {
        // decode phrase words array
        if (gettype($phrase->words) == 'string') {
            $phrase->words = json_decode($phrase->words);
        }

        // check if the chapter contains the phrase
        // otherwise skip the algorithm. 
        foreach ($phrase->words as $phraseWord) {
            if (!in_array($phraseWord, $this->uniqueWords, true)) {
                return false;
            }
        }
        
        $phraseLength = count($phrase->words);
        $phraseOccurences = [];
        foreach($this->processedWords as $wordIndex => $word) {
            $lowercaseWord = mb_strtolower($word->word, 'UTF-8');
            
            // Check if the current word is the start of the phrase.
            if ($lowercaseWord == $phrase->words[0]) {
                $phraseOccurence = new \stdClass();
                $phraseOccurence->word = $lowercaseWord;
                $phraseOccurence->wordIndex = $wordIndex;
                $phraseOccurence->newLineCount = 0;
                array_push($phraseOccurences, array($phraseOccurence));
            }

            // Check if the current word is the continuation of a phrase.
            for ($p = 0 ; $p < count($phraseOccurences); $p++) {
                $phraseOccurenceLength = count($phraseOccurences[$p]);
                
                // If the phrase occurance length equals with phrase length
                // then it means it's an exact match match. There is no need 
                // for further comparison, so the loop can be skipped.
                if ($phraseOccurenceLength === $phraseLength) {
                    continue;
                }

                if ($wordIndex - 1 === $phraseOccurences[$p][$phraseOccurenceLength - 1]->wordIndex + $phraseOccurences[$p][$phraseOccurenceLength - 1]->newLineCount 
                    && $phrase->words[$phraseOccurenceLength] === $lowercaseWord) {
                    
                    $phraseOccurence = new \stdClass();
                    $phraseOccurence->word = $lowercaseWord;
                    $phraseOccurence->wordIndex = $wordIndex;
                    $phraseOccurence->newLineCount = 0;
                    array_push($phraseOccurences[$p], $phraseOccurence);
                }
 
                // Count 'NEWLINE' words. This is needed because phrases doesn't 
                // have them, so it must be skipped when comparing them with text. 
                if ($word->word === 'NEWLINE') {
                    $phraseOccurences[$p][$phraseOccurenceLength - 1]->newLineCount ++;
                }
            }
        }
        
        // Mark all instance of the phrase in text.
        for ($p = 0 ; $p < count($phraseOccurences); $p++) {
            // Skip partial phrase matches. 
            if (count($phraseOccurences[$p]) < count($phrase->words)) {
                continue;
            }

            for ($i = 0; $i < count($phraseOccurences[$p]); $i++) {
                $tempArray = $this->processedWords[$phraseOccurences[$p][$i]->wordIndex]->phrase_ids;

                // add phrase id to word if it's not already added
                if (!in_array($phrase->id, $tempArray, true)) {
                    array_push($tempArray, $phrase->id);
                }

                $this->processedWords[$phraseOccurences[$p][$i]->wordIndex]->phrase_ids = $tempArray;
            }
        }

        return true;
    }
    
    /*
        Collects all phrases in the text, then replaces 
        phrase_ids with phraseIndexes. This is required
        for TextBlock vue object for better search speeds.
    */
    public function indexPhrases() {
        $this->phrases = $this->readerDataService->loadPhrases($this->words);
        $this->readerDataService->indexPhraseIndexes($this->words, $this->phrases);
    }

    /*
        This function adds additional variables for words
        which are required for TextBlockGroup vue component 
        to work.
    */
    public function prepareTextForReader() {
        $encounteredWords = DB::table('encountered_words')
            ->where('user_id', $this->userId)
            ->where('language', $this->language)
            ->whereIn('word', $this->uniqueWords)
            ->get();

        $fwLookup = $this->readerDataService->loadFsrsFamiliarityLookup();
        $this->words = $this->readerDataService->prepareTextForReader(
            $this->processedWords,
            $encounteredWords,
            $this->uniqueWords,
            $fwLookup,
        );
        $this->uniqueWords = $this->readerDataService->enrichUniqueWords($encounteredWords, $fwLookup);
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

    private function fallbackEnglishTokenize(string $text): array
    {
        return $this->englishFallbackTokenizer->tokenize(
            $text,
            fn (): bool => $this->ecdictAvailable(),
            fn (string $word): bool => $this->lemmaInEcdict($word),
        );
    }

    /**
     * English lemma suggestion for DOCTOR / OFFLINE USE ONLY.
     *
     * NOT used during import. The import path uses:
     *   - spaCy (primary, via Python tokenizer)
     *   - conservativeFallbackLemma() (Python-down fallback)
     *
     * This method uses ECDICT-gated morphological heuristics (-ed, -ing, -s, -es,
     * -ies, -ves) and an irregular lookup table. These heuristics can produce
     * wrong lemmas for obscure ECDICT entries (e.g., opened→opene, called→cal).
     * They are suitable for doctor commands where results are reviewed by a human,
     * but NOT for unsupervised import.
     *
     * Do NOT call this from tokenizeRawText(), fallbackEnglishTokenize(),
     * or makeFallbackToken().
     */
    private function suggestEnglishLemmaForDoctorOnly(string $surface): string
    {
        $lower = mb_strtolower($surface, 'UTF-8');

        // Don't lemmatize structural markers
        if (preg_match('/^zz(para|newl|sect)/i', $lower)) {
            return $lower;
        }

        // 1. Irregular lookups (verb conjugations, noun plurals) — check first,
        //    even for short words (e.g., "is" → "be", "am" → "be")
        $irregular = $this->englishIrregularLemma($lower);
        if ($irregular !== null) {
            return $this->ecdictSafeLemma($irregular, $lower);
        }

        // Very short words (length < 3) that aren't irregular: keep as-is
        if (mb_strlen($lower) < 3) {
            return $lower;
        }

        // 2. -ies → -y (stories → story, but not "ties" → "ty" universally)
        if (preg_match('/^(.+)ies$/u', $lower, $m) && mb_strlen($m[1]) >= 2) {
            $candidate = $m[1] . 'y';
            return $this->ecdictSafeLemma($candidate, $lower);
        }

        // 3. -ves → -f (wives → wife, knives → knife)
        if (preg_match('/^(.+)ves$/u', $lower, $m) && mb_strlen($m[1]) >= 1) {
            $candidate = $m[1] . 'f';
            if ($this->lemmaInEcdict($candidate)) {
                return $candidate;
            }
            $candidate2 = $m[1] . 'fe';
            if ($this->lemmaInEcdict($candidate2)) {
                return $candidate2;
            }
        }

        // 4. -ses, -xes, -zes, -ches, -shes → remove -es (boxes, watches)
        if (preg_match('/^(.+)([sxz]|[cs]h)es$/u', $lower, $m) && mb_strlen($m[1]) >= 1) {
            $candidate = $m[1] . $m[2];
            return $this->ecdictSafeLemma($candidate, $lower);
        }

        // 5. -es → remove -s (stores → store, makes → make)
        if (preg_match('/^(.+)es$/u', $lower, $m) && mb_strlen($m[1]) >= 2) {
            // Try adding 'e' back (stores → store, makes → make)
            $candidate = $m[1] . 'e';
            if ($this->lemmaInEcdict($candidate)) {
                return $candidate;
            }
            // Try removing just -s
            $candidate2 = $m[1];
            if ($this->lemmaInEcdict($candidate2)) {
                return $candidate2;
            }
            // When ECDICT unavailable: word+"e" is the more likely correct form
            if (!$this->ecdictAvailable()) {
                return $candidate;
            }
        }

        // 6. -s → remove -s (facts → fact, retailers → retailer)
        // Conservative: only if removing 's' gives a known dictionary word
        if (preg_match('/^(.+)s$/u', $lower, $m) && mb_strlen($m[1]) >= 3) {
            $candidate = $m[1];
            if ($this->lemmaInEcdict($candidate)) {
                return $candidate;
            }
            // When ECDICT unavailable: tentatively return the candidate
            // (this makes fallback tokenizer useful before ECDICT import)
            if (!$this->ecdictAvailable()) {
                return $candidate;
            }
        }

        // 7. -(n)ing → base (dropping → drop, running → run, making → make)
        if (preg_match('/^(.+)(ing|ING)$/u', $lower, $m)) {
            $stem = $m[1];
            // Double consonant removal: dropping→drop, running→run
            if (mb_strlen($stem) >= 3 && mb_substr($stem, -1) === mb_substr($stem, -2, 1)) {
                $lastChar = mb_substr($stem, -1);
                if ($this->ecdictAvailable()) {
                    // ECDICT available: try bare stem first (falling→fall), then de-double (running→run), then +e
                    if ($this->lemmaInEcdict($stem)) {
                        return $stem;
                    }
                    $deDouble = mb_substr($stem, 0, -1);
                    if ($this->lemmaInEcdict($deDouble)) {
                        return $deDouble;
                    }
                    $candidate = $stem . 'e';
                    if ($this->lemmaInEcdict($candidate)) {
                        return $candidate;
                    }
                } else {
                    // ECDICT unavailable: ll/ss/zz keep bare stem, other doubles de-double
                    if (in_array($lastChar, ['l', 's', 'z'], true)) {
                        return $stem;
                    }
                    return mb_substr($stem, 0, -1);
                }
            }
            // No double consonant
            if ($this->ecdictAvailable()) {
                // Try bare stem FIRST (opening→open), then +e (making→make)
                // Bare stem is safer: +e can produce false matches for obscure
                // ECDICT entries (e.g., reading→reade if "reade" exists in ECDICT).
                if ($this->lemmaInEcdict($stem)) {
                    return $stem;
                }
                $candidate = $stem . 'e';
                if ($this->lemmaInEcdict($candidate)) {
                    return $candidate;
                }
            } else {
                // No ECDICT: try +e then stem (same as old behavior).
                // +e is correct for most common -ing forms (coming→come, making→make, taking→take)
                // and when wrong (walking→walke), the form is still recognizable.
                // The alternative (bare stem) would break common verbs (com, mak, tak are unrecognizable).
                $candidate = $stem . 'e';
                return $candidate;
            }
        }

        // 8. -ed → base (surged → surge, stopped → stop)
        if (preg_match('/^(.+)(ed|ED)$/u', $lower, $m) && mb_strlen($m[1]) >= 2) {
            $stem = $m[1];
            // Double consonant removal: stopped→stop, dropped→drop
            if (mb_strlen($stem) >= 3 && mb_substr($stem, -1) === mb_substr($stem, -2, 1)) {
                $lastChar = mb_substr($stem, -1);
                if ($this->ecdictAvailable()) {
                    // ECDICT available: try bare stem first (called→call), then de-double (stopped→stop), then +e
                    if ($this->lemmaInEcdict($stem)) {
                        return $stem;
                    }
                    $deDouble = mb_substr($stem, 0, -1);
                    if ($this->lemmaInEcdict($deDouble)) {
                        return $deDouble;
                    }
                    $candidate = $stem . 'e';
                    if ($this->lemmaInEcdict($candidate)) {
                        return $candidate;
                    }
                } else {
                    // ECDICT unavailable: ll/ss/zz keep bare stem, other doubles de-double
                    if (in_array($lastChar, ['l', 's', 'z'], true)) {
                        return $stem;
                    }
                    return mb_substr($stem, 0, -1);
                }
            }
            // No double consonant
            if ($this->ecdictAvailable()) {
                // Try bare stem FIRST (opened→open, walked→walk), then +e (liked→like)
                // Bare stem is safer: +e can produce false matches for obscure
                // ECDICT entries (e.g., opened→opene if "opene" exists in ECDICT).
                if ($this->lemmaInEcdict($stem)) {
                    return $stem;
                }
                // -ied → -y (tried → try)
                if (preg_match('/^(.+)i$/u', $stem, $m2)) {
                    $candidate2 = $m2[1] . 'y';
                    if ($this->lemmaInEcdict($candidate2)) {
                        return $candidate2;
                    }
                }
                // Try +e (liked → like, surged → surge)
                $candidate = $stem . 'e';
                if ($this->lemmaInEcdict($candidate)) {
                    return $candidate;
                }
            } else {
                // No ECDICT: conservative — no blind +e
                // -ied → -y is a high-confidence rule (tried → try, studied → study)
                if (preg_match('/^(.+)i$/u', $stem, $m2)) {
                    return $m2[1] . 'y';
                }
                // Return bare stem (walked→walk, looked→look)
                return $stem;
            }
        }

        // Default: lowercase surface (conservative — no wild guesses)
        return $lower;
    }

    /**
     * Irregular English verb/noun mapping used by the doctor-only helper.
     */
    private function englishIrregularLemma(string $word): ?string
    {
        return $this->englishFallbackTokenizer->irregularLemma($word);
    }

    /**
     * Check if a word exists in ECDICT.
     * When ECDICT is not available, returns false — downstream rules
     * should handle the unavailable case gracefully with heuristics.
     *
     * Protected so test subclasses can override the dictionary lookup and
     * exercise the ECDICT-gated suffix rules in conservativeFallbackLemma()
     * without a real dictionary table.
     */
    protected function lemmaInEcdict(string $word): bool
    {
        static $cache = [];
        static $available = null;
        $key = mb_strtolower($word, 'UTF-8');
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if ($available === null) {
            try {
                $available = \Illuminate\Support\Facades\DB::table('dict_en_ecdict_full')->exists();
            } catch (\Throwable $e) {
                $available = false;
            }
        }
        if (!$available) {
            $cache[$key] = false;
            return false;
        }
        try {
            $exists = \Illuminate\Support\Facades\DB::table('dict_en_ecdict_full')
                ->where('word', $key)
                ->exists();
            $cache[$key] = $exists;
            return $exists;
        } catch (\Throwable $e) {
            $cache[$key] = false;
            return false;
        }
    }

    /**
     * Returns the candidate lemma if it exists in ECDICT, otherwise returns the fallback.
     * When ECDICT is not available, trusts the candidate (morphological rules are more
     * likely correct than the surface form, even without dictionary verification).
     */
    private function ecdictSafeLemma(string $candidate, string $fallback): string
    {
        if (!$this->ecdictAvailable()) {
            return $candidate;
        }
        return $this->lemmaInEcdict($candidate) ? $candidate : $fallback;
    }

    /**
     * Check if ECDICT is available (table exists and has data).
     *
     * Protected so test subclasses can override the availability check and
     * exercise the ECDICT-gated suffix rules in conservativeFallbackLemma()
     * without a real dictionary table.
     */
    protected function ecdictAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            try {
                $available = \Illuminate\Support\Facades\DB::table('dict_en_ecdict_full')->exists();
            } catch (\Throwable $e) {
                $available = false;
            }
        }
        return $available;
    }

    private function makeFallbackTokenWithPos(string $surface, string $pos, int $sentenceIndex): \stdClass
    {
        $token = new \stdClass();
        $token->w = $surface;
        $token->r = '';
        $token->l = $surface;
        $token->lr = '';
        $token->pos = $pos;
        $token->si = $sentenceIndex;
        $token->g = '';
        return $token;
    }

    /*
        This function returns an object that only
        contains variables which are required by
        TextBlockGroup vue component.
    */
    public function getReaderData() {
        $textBlock = new \stdClass();
        $textBlock->words = $this->words;
        $textBlock->uniqueWords = $this->uniqueWords;
        $textBlock->phrases = $this->phrases;
        return $textBlock;
    }
}
