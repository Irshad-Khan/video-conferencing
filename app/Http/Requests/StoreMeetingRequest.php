<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:4', 'max:255'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'waiting_room_enabled' => ['sometimes', 'boolean'],
            'allow_guests' => ['sometimes', 'boolean'],
        ];
    }
}
