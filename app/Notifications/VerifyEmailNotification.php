<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;

class VerifyEmailNotification extends VerifyEmail
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Send the notification.
     *
     * @param  mixed  $notifiable
     * @return void
     */
    public function toMail($notifiable)
    {
        $verificationUrl = $this->verificationUrl($notifiable);
        
        // כתיבה ללוג למידע נוסף
        Log::info('Email verification link generated', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'verification_url' => $verificationUrl,
            'expires_at' => Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60))
        ]);
        
        // שליחת מייל אמיתי
        return $this->buildMailMessage($verificationUrl);
    }

    /**
     * Get the verification email notification mail message for the given URL.
     *
     * @param  string  $url
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    protected function buildMailMessage($url)
    {
        return (new MailMessage)
            ->subject('Verify Email Address')
            ->line('Please click the button below to verify your email address.')
            ->action('Verify Email Address', $url)
            ->line('If you did not create an account, no further action is required.');
    }

    /**
     * Get the verification URL for the given notifiable.
     *
     * @param  mixed  $notifiable
     * @return string
     */
    protected function verificationUrl($notifiable)
    {
        $frontendUrl = config('app.verification_url');
        
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable);
        }

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // Extract the query parameters from the Laravel URL
        $queryString = parse_url($verificationUrl, PHP_URL_QUERY);
        
        // Add the user ID to the query string
        $queryString .= '&id=' . $notifiable->getKey();
        
        // Construct the frontend URL with the verification parameters
        return $frontendUrl . '/api/verify-email?' . $queryString;
    }
} 