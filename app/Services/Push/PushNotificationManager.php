<?php

namespace App\Services\Push;

use App\Contracts\PushProviderInterface;
use InvalidArgumentException;

class PushNotificationManager
{
    /** @var array<string, PushProviderInterface> */
    protected array $drivers = [];

    public function registerDriver(string $name, PushProviderInterface $driver): self
    {
        $this->drivers[$name] = $driver;

        return $this;
    }

    public function driver(string $name): PushProviderInterface
    {
        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException("Push driver [{$name}] is not registered.");
        }

        return $this->drivers[$name];
    }
}
