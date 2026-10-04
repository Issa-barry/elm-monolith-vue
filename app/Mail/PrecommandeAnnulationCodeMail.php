<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Code de confirmation de l'annulation d'une précommande en préparation ou préparée (procédure
 * renforcée, ADR 0019 décision D1 — cf. PrecommandeService::demanderCodeAnnulation()). Envoyé à
 * l'utilisateur authentifié, de façon synchrone (même raison que les autres e-mails OTP).
 */
class PrecommandeAnnulationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $referenceCommande,
        public readonly int $dureeMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Code de confirmation — Annulation de précommande');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.precommande-annulation-code',
            with: [
                'code' => $this->code,
                'reference' => $this->referenceCommande,
                'dureeMinutes' => $this->dureeMinutes,
            ],
        );
    }
}
