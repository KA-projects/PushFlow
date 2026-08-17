<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscribePushRequest;
use App\Http\Requests\UnsubscribePushRequest;
use App\Services\Push\PushSubscriptionService;
use Illuminate\Http\JsonResponse;

class PushSubscriptionController extends Controller
{
    public function __construct(private PushSubscriptionService $service) {}

    /**
     * Сохранение/обновление подписки клиента на push-уведомления.
     */
    public function subscribe(SubscribePushRequest $request): JsonResponse
    {
        $subscription = $this->service->upsert($request->toData());

        return response()->json($subscription, $subscription->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Отписка от push-уведомлений по endpoint.
     */
    public function unsubscribe(UnsubscribePushRequest $request): JsonResponse
    {
        $deactivated = $this->service->unsubscribe($request->validated('endpoint'));

        return response()->json([
            'message' => $deactivated ? 'Подписка отключена.' : 'Подписка не найдена.',
            'unsubscribed' => $deactivated,
        ], $deactivated ? 200 : 404);
    }
}
