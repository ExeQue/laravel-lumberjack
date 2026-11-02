<?php

declare(strict_types=1);

namespace Workbench\App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderShipped extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())->subject('Order shipped')->line('An order went out.');
    }
}
