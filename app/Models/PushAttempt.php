<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushAttempt extends Model
{
    protected $table = 'push_attempts';

    public $timestamps = false;

    protected $fillable = [
        'notification_id',
        'attempt',
        'status',
        'ticket_id',
        'error_code',
        'error_message',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(PushNotification::class, 'notification_id');
    }
}
