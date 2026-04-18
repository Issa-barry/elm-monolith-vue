<?php

namespace App\Mail;

use App\Models\UserInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UserInvitationMail extends Mailable
{
    public string $acceptUrl;

    public function __construct(
        public UserInvitation $invitation,
        string $token,
    ) {
        $this->acceptUrl = url(route('invitations.accept', ['token' => $token], false));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitation à rejoindre '.$this->invitation->site->type_label.' '.$this->invitation->site->nom,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invitation',
        );
    }
}
