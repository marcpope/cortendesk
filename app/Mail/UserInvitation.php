<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitation to create a console account (PLAN D1). Not queued — see TestMessage. */
class UserInvitation extends Mailable
{
    public function __construct(
        public Invitation $invitation,
        public string $acceptUrl,
        public string $invitedBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.you_have_been_invited_to', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invitation');
    }
}
