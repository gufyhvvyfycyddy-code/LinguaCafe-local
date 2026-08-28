<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'current_password:web'],
            'password' => 'required|string|confirmed|min:8|max:32',
            'password_confirmation' => 'required|string'
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => '请输入当前密码。',
            'current_password.current_password' => '当前密码不正确。',
        ];
    }
}
