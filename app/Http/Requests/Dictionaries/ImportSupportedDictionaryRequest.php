<?php

namespace App\Http\Requests\Dictionaries;

use Illuminate\Foundation\Http\FormRequest;

class ImportSupportedDictionaryRequest extends FormRequest
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
            'dictionaryName' => ['required', 'string', 'max:255'],
            'dictionaryFileName' => [
                'required',
                'string',
                'max:255',
                'regex:/\A(?!.*\.\.)(?!.*[\/\\\\\x00])[^\/\\\\\x00]+\z/u',
            ],
            'dictionarySourceLanguage' => ['required', 'string', 'max:64'],
            'dictionaryTargetLanguage' => ['required', 'string', 'max:64'],
            'dictionaryDatabaseName' => [
                'required',
                'string',
                'max:40',
                'regex:/\Adict_[a-z0-9_]+\z/',
            ],
        ];
    }
}
