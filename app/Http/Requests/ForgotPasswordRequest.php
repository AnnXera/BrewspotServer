<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithFirstJsonError;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/auth/forgot-password
 */
class ForgotPasswordRequest extends FormRequest
{
    use RespondsWithFirstJsonError;

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
