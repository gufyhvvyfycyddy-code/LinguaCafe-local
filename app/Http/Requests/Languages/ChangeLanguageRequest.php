<?php

namespace App\Http\Requests\Languages;

use Illuminate\Foundation\Http\FormRequest;

class ChangeLanguageRequest extends FormRequest
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
            'language' => 'required|string|max:64',
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'language' => mb_strtolower(trim((string) $this->route('language')), 'UTF-8'),
        ]);
    }
}
