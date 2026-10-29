<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    /**
     * Optional custom verification URL.
     */
    public ?string $customVerificationUrl;

    /**
     * Optional custom 6-digit OTP code.
     */
    public ?string $customCode;

    /**
     * Create a new notification instance.
     */
    public function __construct(?string $customVerificationUrl = null, ?string $customCode = null)
    {
        $this->customVerificationUrl = $customVerificationUrl;
        $this->customCode = $customCode;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $expireMinutes = (int) Config::get('auth.verification.expire', 60);
        $verificationUrl = $this->customVerificationUrl ?? $this->verificationUrl($notifiable);

        if ($this->customCode) {
            $code = $this->customCode;
        } else {
            // Generate or retrieve a 6-digit OTP code valid for 15 minutes
            $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            Cache::put("auth:email_verify_code:{$notifiable->id}", $code, now()->addMinutes(15));
        }

        return (new MailMessage)
            ->subject('Verify Your Email Address - CodeBridges')
            ->view('emails.verify-email', [
                'user' => $notifiable,
                'verificationUrl' => $verificationUrl,
                'code' => $code,
                'expireMinutes' => $expireMinutes,
            ]);
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
