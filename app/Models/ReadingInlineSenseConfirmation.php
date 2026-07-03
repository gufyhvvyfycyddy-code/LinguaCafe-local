<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists user-initiated "是这个意思 / 不是这个意思" choices made on the
 * reading-page inline sense preview panel (ADR-0003).
 *
 * SAFETY CONTRACT (ADR-0003):
 *  - This model is the ONLY writer-side model for inline confirmations.
 *  - It MUST NOT be referenced by ReviewLog, FSRS, ReviewCard, or
 *    WordSense creation logic.
 *  - Its only intended writer is `ReadingInlineSenseConfirmationService`.
 *  - `choice` is 'match' or 'not_match'. It is NOT an FSRS rating.
 *  - `not_match` only negates THIS occurrence + THIS candidate. It does
 *    NOT globally reject the WordSense.
 */
class ReadingInlineSenseConfirmation extends Model
{
    use HasFactory;

    public const CHOICE_MATCH = 'match';
    public const CHOICE_NOT_MATCH = 'not_match';

    public const SOURCE_READING_INLINE_PREVIEW = 'reading_inline_preview';

    protected $table = 'reading_inline_sense_confirmations';

    protected $fillable = [
        'user_id',
        'language',
        'chapter_id',
        'sentence_index',
        'sentence_hash',
        'sentence_text',
        'surface',
        'lemma',
        'word_sense_id',
        'choice',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'sentence_index' => 'integer',
        ];
    }

    public function wordSense()
    {
        return $this->belongsTo(WordSense::class, 'word_sense_id');
    }
}
