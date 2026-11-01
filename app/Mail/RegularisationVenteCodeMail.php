<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Code de confirmation d'une régularisation de vente après encaissement (ADR 0043), envoyé à la
 * seule adresse de l'utilisateur authentifié, de façon synchrone (même raison que
 * AnnulationExceptionnelleCodeMail).
 */
class RegularisationVenteCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $referenceCommande,
        public readonly int $dureeMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Code de confirmation — Régularisation de vente');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.regularisation-vente-code',
            with: [
                'code' => $this->code,
                'reference' => $this->referenceCommande,
                'dureeMinutes' => $this->dureeMinutes,
            ],
        );
    }
}
