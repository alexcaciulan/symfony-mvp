<?php

namespace App\Enum;

enum NotificationChannel: string
{
    case EMAIL = 'email';
    case IN_APP = 'in_app';

    public function label(): string
    {
        return 'enum.notification_channel.' . $this->value;
    }
}
