<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->message)) {
            $trimmed = trim($this->message);
            $this->merge(['message' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:2000'],
            'attachment' => [
                'nullable',
                'file',
                'max:5120',
                'mimes:jpg,jpeg,png,gif,webp,pdf,txt,csv,doc,docx,xls,xlsx,zip',
            ],
            'guest_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('message') && ! $this->hasFile('attachment')) {
                $validator->errors()->add('message', 'A message or an attachment is required.');
            }
        });
    }
}
