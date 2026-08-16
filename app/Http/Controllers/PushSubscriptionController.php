<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscribePushRequest;
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
}
