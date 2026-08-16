<?php

namespace App\Http\Requests\Import;

use App\Models\Book;
use App\Models\Chapter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'importType' => ['required', 'string', Rule::in([
                'e-book',
                'jellyfin-subtitle',
                'subtitle-file',
                'plain-text',
                'text-file',
                'youtube',
                'website',
            ])],
            'eBookChapterSortMethod' => 'required|string',
            'textProcessingMethod' => 'required|string',
            'bookId' => 'required|integer|gte:-1',
            'bookName' => 'nullable|required_if:bookId,-1|string|max:128',
            'chapterName' => 'required|string|max:120',
            'maximumCharactersPerChapter' => 'required|integer|gte:200|lte:20000',
            'importText' => 'nullable|required_if:importType,plain-text,text-file,youtube,website|string',
            'importSubtitles' => 'nullable|required_if:importType,jellyfin-subtitle,subtitle-file|string',
            'importFile' => 'nullable|required_if:importType,e-book|file',
            'materialType' => [
                'nullable',
                'string',
                Rule::in(Book::MATERIAL_TYPES),
                'required_with:examYear,examSet',
            ],
            'examYear' => [
                'nullable',
                'integer',
                'between:1900,2100',
                'required_if:materialType,cet4,cet6,postgraduate_exam',
                'prohibited_if:materialType,personal',
            ],
            'examSet' => [
                'nullable',
                'integer',
                'between:1,99',
                'required_if:materialType,cet4,cet6,postgraduate_exam',
                'prohibited_if:materialType,personal',
            ],
            'questionType' => ['nullable', 'string', Rule::in(Chapter::QUESTION_TYPES)],
        ];
    }
}
