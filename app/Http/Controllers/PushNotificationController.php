<?php

namespace App\Http\Controllers;

use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushNotificationController extends Controller
{
    /**
     * Постановка push-уведомления в очередь для одной подписки (по endpoint) или всех.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:1000'],
            'endpoint' => ['nullable', 'url', 'max:512'],
            'extra' => ['nullable', 'array'],
        ]);

        $subscriptions = isset($validated['endpoint'])
            ? PushSubscription::where('endpoint', $validated['endpoint'])->get()
            : PushSubscription::all();

        if ($subscriptions->isEmpty()) {
            return response()->json(['message' => 'Подписки не найдены.'], 404);
        }

        $extra = $validated['extra'] ?? [];

        foreach ($subscriptions as $subscription) {
            SendPushNotification::dispatch($subscription->id, $validated['title'], $validated['body'], $extra);
        }

        return response()->json([
            'message' => 'Уведомления поставлены в очередь.',
            'queued' => $subscriptions->count(),
        ]);
    }
}
