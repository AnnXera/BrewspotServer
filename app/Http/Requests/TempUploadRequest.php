<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithFirstJsonError;
use Illuminate\Foundation\Http\FormRequest;

class TempUploadRequest extends FormRequest
{
    use RespondsWithFirstJsonError;

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ];
    }
}
