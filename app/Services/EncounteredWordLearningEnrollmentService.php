<?php

namespace App\Services;

use App\Models\EncounteredWord;

class EncounteredWordLearningEnrollmentService
{
    public function enrollFromConfirmedSense(EncounteredWord $word, bool $keepNew): ?array
    {
        if ($word->stage === 0 || $word->stage === 1) {
            return null;
        }

        $stageChanged = false;
        if ($word->stage === 2 && !$keepNew) {
            $word->stage = -1;
            $word->relearning = false;
            $word->next_review = null;
            $word->added_to_srs = null;
            $word->save();

            $stageChanged = true;
        }

        return [
            'id' => $word->id,
            'stage' => $word->stage,
            'word' => $word->word,
            'base_word' => $word->base_word,
            'study_base' => $word->study_base,
            'stage_changed' => $stageChanged,
        ];
    }
}
