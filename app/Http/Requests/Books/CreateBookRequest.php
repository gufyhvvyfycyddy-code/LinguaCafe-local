<?php

namespace App\Http\Requests\Books;

use App\Models\Book;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBookRequest extends FormRequest
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
            'bookName' => 'required|string|max:128',
            'bookCover' => 'file|mimes:jpg,jpeg,png,webp',
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
        ];
    }
}
