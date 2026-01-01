<?php

namespace App\Notifications\Channels;

use App\Services\InforuEmailService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class InforuMailChannel
{
    public function __construct(private InforuEmailService $emailService)
    {
    }

    public function send($notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toInforuMail')) {
            return;
        }

        $message = $notification->toInforuMail($notifiable);
        if (!is_array($message)) {
            return;
        }

        $subject = $message['subject'] ?? null;
        $body = $message['body'] ?? null;
        if (!$subject || $body === null) {
            return;
        }

        $recipients = $message['recipients'] ?? null;
        if (!is_array($recipients) || empty($recipients)) {
            $recipientEmail = $notifiable->routeNotificationFor('mail', $notification)
                ?? $notifiable->email
                ?? null;
            if (!$recipientEmail) {
                Log::warning('Inforu notification skipped: no recipient email', [
                    'notification' => get_class($notification),
                ]);
                return;
            }

            $recipients = [[
                'email' => $recipientEmail,
                'name' => $notifiable->name ?? null,
            ]];
        }

        $options = is_array($message['options'] ?? null) ? $message['options'] : [];

        try {
            $this->emailService->sendEmail($recipients, (string) $subject, (string) $body, $options);
        } catch (Throwable $exception) {
            Log::error('Inforu notification send failed', [
                'notification' => get_class($notification),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
