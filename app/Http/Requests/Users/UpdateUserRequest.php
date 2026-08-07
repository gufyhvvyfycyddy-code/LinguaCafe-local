<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
            'userId' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'name' => 'required|string|min:2|max:64',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($this->integer('userId')),
            ],
            'isAdmin' => 'required|boolean',
        ];
    }
}
