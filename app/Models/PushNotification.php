<?php

namespace App\Models;

use App\Enums\PushNotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PushNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id',
        'push_subscription_id',
        'title',
        'body',
        'payload',
        'status',
        'provider',
        'ticket_id',
        'attempts',
        'error_code',
        'error_message',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => PushNotificationStatus::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PushSubscription::class, 'push_subscription_id');
    }

    public function pushAttempts(): HasMany
    {
        return $this->hasMany(PushAttempt::class, 'notification_id');
    }
}
