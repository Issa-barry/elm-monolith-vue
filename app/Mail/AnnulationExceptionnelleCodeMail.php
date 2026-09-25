<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Code de confirmation d'une annulation exceptionnelle de commande (cf.
 * AnnulationExceptionnelleService::demanderCode()). Envoyé uniquement à l'adresse de
 * l'utilisateur authentifié qui demande l'annulation, de façon synchrone (même raison que les
 * autres e-mails OTP : un code de 10 minutes doit arriver au plus vite, et un échec d'envoi doit
 * remonter immédiatement à l'écran).
 */
class AnnulationExceptionnelleCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $referenceCommande,
        public readonly int $dureeMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Code de confirmation — Annulation exceptionnelle');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.annulation-exceptionnelle-code',
            with: [
                'code' => $this->code,
                'reference' => $this->referenceCommande,
                'dureeMinutes' => $this->dureeMinutes,
            ],
        );
    }
}
