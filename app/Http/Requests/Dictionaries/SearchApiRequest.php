<?php

namespace App\Http\Requests\Dictionaries;

use App\Services\Dictionaries\DictionaryLookupRequestPolicy;
use Illuminate\Foundation\Http\FormRequest;

class SearchApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'language' => ['nullable', 'string', 'max:64'],
            'term' => app(DictionaryLookupRequestPolicy::class)->validationRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('term')) {
            $this->merge(['term' => trim((string) $this->input('term'))]);
        }
    }
}
