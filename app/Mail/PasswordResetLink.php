<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Password reset link. Not queued — see TestMessage. */
class PasswordResetLink extends Mailable
{
    public function __construct(
        public User $user,
        public string $resetUrl,
        public int $ttlMinutes,
        public ?string $requestedIp = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.reset_your_app_password', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset');
    }
}
