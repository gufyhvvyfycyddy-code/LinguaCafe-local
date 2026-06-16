<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class RateReviewCardRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'reviewCardId' => 'required|integer|min:1',
            'rating' => 'required|string|in:again,hard,good,easy',
        ];
    }
}
