<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendPushNotificationRequest;
use App\Services\Push\PushNotificationService;
use Illuminate\Http\JsonResponse;

class PushNotificationController extends Controller
{
    public function __construct(private PushNotificationService $service) {}

    /**
     * Постановка push-уведомления в очередь для одной подписки (по endpoint) или всех.
     */
    public function send(SendPushNotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $subscriptions = $this->service->queueForEndpoint(
            $validated['title'],
            $validated['body'],
            $validated['endpoint'] ?? null,
            $validated['extra'] ?? [],
        );

        if ($subscriptions->isEmpty()) {
            return response()->json(['message' => 'Подписки не найдены.'], 404);
        }

        return response()->json([
            'message' => 'Уведомления поставлены в очередь.',
            'queued' => $subscriptions->count(),
        ]);
    }
}
