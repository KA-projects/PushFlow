<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $message = 'Test job executed')
    {
    }

    public function handle(): void
    {
        Redis::set('test:job:last', $this->message);
        Log::info("{$this->message} at ".now()->toDateTimeString());
    }
}
