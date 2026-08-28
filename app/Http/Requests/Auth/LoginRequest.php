<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials using the single
     * public-login rate-limit owner.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->accountKey(), 60);
            RateLimiter::hit($this->ipKey(), 60);

            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'The email or password is incorrect.',
                ],
            ], 401));
        }

        RateLimiter::clear($this->accountKey());
    }

    /**
     * Ensure the login request is not rate limited.
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->accountKey(), 5) &&
            ! RateLimiter::tooManyAttempts($this->ipKey(), 25)) {
            return;
        }

        event(new Lockout($this));

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'LOGIN_RATE_LIMITED',
                'message' => 'Too many login attempts. Please try again later.',
            ],
        ], 429));
    }

    /**
     * Get the per-account rate limiting key for the request.
     */
    public function accountKey(): string
    {
        return 'login:account:'.Str::transliterate(Str::lower($this->input('email')));
    }

    /**
     * Get the per-IP rate limiting key for the request.
     */
    public function ipKey(): string
    {
        return 'login:ip:'.$this->ip();
    }
}
