<?php

namespace App\Http\Requests\Chapters;

use Illuminate\Foundation\Http\FormRequest;

class FinishChapterRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'chapterId' => 'required|numeric|gte:0',
            'uniqueWords' => 'required|json',
            'autoLevelUpWords' => 'required|boolean',
            'leveledUpWords' => 'required|json',
            'leveledUpPhrases' => 'required|json',
            'autoMoveWordsToKnown' => 'required|boolean',
            'reading_session_id' => 'nullable|string|uuid',
            'settlement_mode' => 'nullable|in:preflight,commit',
        ];
    }
}
