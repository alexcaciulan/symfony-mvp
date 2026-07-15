<?php

declare(strict_types=1);

namespace App\Service\Notification;

interface NotificationDispatcherInterface
{
    /**
     * Fan a notification out to the channels declared on the request (email, in-app).
     * Must be headless-safe and never let one channel failure escape.
     */
    public function dispatch(NotificationDispatch $request): void;
}
