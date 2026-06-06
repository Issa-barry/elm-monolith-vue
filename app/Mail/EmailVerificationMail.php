<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EmailVerificationMail extends Mailable
{
    public string $verificationUrl;

    public function __construct(
        public User $user,
        string $token,
    ) {
        $this->verificationUrl = url('/api/auth/verify-email/'.$token);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Validez votre adresse email – '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify',
        );
    }
}
