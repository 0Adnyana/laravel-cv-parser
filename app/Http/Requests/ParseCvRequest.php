<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParseCvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'cv' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }
}
