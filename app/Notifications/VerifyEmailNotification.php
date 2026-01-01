<?php

namespace App\Notifications;

use App\Notifications\Channels\InforuMailChannel;
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

    protected ?MailMessage $lastMailMessage = null;
    protected ?string $resolvedVerificationUrl = null;

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
    public function via($notifiable)
    {
        return [InforuMailChannel::class];
    }

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
        $mailMessage = $this->buildMailMessage($verificationUrl);
        $this->lastMailMessage = $mailMessage;

        return $mailMessage;
    }

    public function toInforuMail($notifiable): array
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        $intro = e('Please click the button below to verify your email address.');
        $actionText = e('Verify Email Address');
        $outro = e('If you did not create an account, no further action is required.');
        $url = e($verificationUrl);

        $body = '<p>' . $intro . '</p><p><a href="' . $url . '">' . $actionText . '</a></p><p>' . $outro . '</p>';

        return [
            'subject' => 'Verify Email Address',
            'body' => $body,
            'options' => [
                'event_key' => 'auth.verify_email',
            ],
        ];
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

    public function getMailMessage(): ?MailMessage
    {
        return $this->lastMailMessage;
    }

    public function previewFor($notifiable): array
    {
        $url = $this->verificationUrl($notifiable);
        $mailMessage = $this->buildMailMessage($url);
        $this->lastMailMessage = $mailMessage;

        return [$mailMessage, $url];
    }

    /**
     * Get the verification URL for the given notifiable.
     *
     * @param  mixed  $notifiable
     * @return string
     */
    protected function verificationUrl($notifiable)
    {
        if ($this->resolvedVerificationUrl !== null) {
            return $this->resolvedVerificationUrl;
        }

        $frontendUrl = config('app.verification_url');
        
        if (static::$createUrlCallback) {
            $this->resolvedVerificationUrl = call_user_func(static::$createUrlCallback, $notifiable);
            return $this->resolvedVerificationUrl;
        }

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        $queryString = parse_url($verificationUrl, PHP_URL_QUERY);
        $queryString .= '&id=' . $notifiable->getKey();

        $this->resolvedVerificationUrl = $frontendUrl . '/api/verify-email?' . $queryString;

        return $this->resolvedVerificationUrl;
    }
}
