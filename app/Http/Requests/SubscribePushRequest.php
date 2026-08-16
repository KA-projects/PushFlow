<?php

namespace App\Http\Requests;

use App\Dto\PushSubscriptionData;
use Illuminate\Foundation\Http\FormRequest;

class SubscribePushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function toData(): PushSubscriptionData
    {
        $validated = $this->validated();

        return new PushSubscriptionData(
            endpoint: $validated['endpoint'],
            p256dh: $validated['keys']['p256dh'],
            auth: $validated['keys']['auth'],
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:512'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ];
    }
}
