<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ConfirmationEmailProfilMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $confirmationUrl,
        public int $validiteHeures,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirmez votre nouvelle adresse e-mail – '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.confirm-email-profil',
        );
    }
}
