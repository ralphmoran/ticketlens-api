<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmail extends BaseVerifyEmail
{
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your TicketLens email address')
            ->greeting('Welcome to TicketLens!')
            ->line('Click the button below to verify your email address and activate your account.')
            ->action('Verify email address', $url)
            ->line('This link expires in 60 minutes.')
            ->line('If you did not create a TicketLens account, no action is required.');
    }
}
