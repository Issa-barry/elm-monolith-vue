<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountValidatedMail extends Mailable
{
    public string $loginUrl;

    public function __construct(public User $user)
    {
        $this->loginUrl = url(route('login', [], false));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre compte a été validé — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-validated',
        );
    }
}
