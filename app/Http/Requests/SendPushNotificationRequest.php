<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendPushNotificationRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:1000'],
            'endpoint' => ['nullable', 'url', 'max:512'],
            'extra' => ['nullable', 'array'],
        ];
    }
}
