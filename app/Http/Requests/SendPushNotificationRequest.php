<?php

namespace App\Http\Requests;

use App\Dto\SendPushNotificationData;
use Illuminate\Foundation\Http\FormRequest;

class SendPushNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function toData(): SendPushNotificationData
    {
        $validated = $this->validated();

        return new SendPushNotificationData(
            title: $validated['title'],
            body: $validated['body'],
            endpoint: $validated['endpoint'] ?? null,
            extra: $validated['extra'] ?? [],
        );
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
