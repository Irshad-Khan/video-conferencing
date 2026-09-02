<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string'],
            'guest_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
