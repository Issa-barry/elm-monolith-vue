<?php

namespace App\Mail;

use App\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable générique pour les NOUVEAUX usages OTP agnostiques du canal
 * (cf. App\Services\Otp\Channels\EmailOtpChannel) — un seul gabarit, le texte
 * s'adapte au `purpose`. N'a jamais vocation à remplacer les Mailables
 * dédiés déjà en place (OtpPasswordResetMail, OtpInvitationMail,
 * InstallEmailVerificationMail) : ceux-ci ont leur propre habillage/texte
 * déjà éprouvé, aucun bénéfice à les fusionner ici.
 */
class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectFor($this->purpose));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp-code',
            with: [
                'code' => $this->code,
                'intro' => $this->introFor($this->purpose),
            ],
        );
    }

    private function subjectFor(OtpPurpose $purpose): string
    {
        return match ($purpose) {
            OtpPurpose::LOGIN => 'Votre code de connexion',
            OtpPurpose::PHONE_VERIFICATION => 'Votre code de vérification',
            OtpPurpose::EMAIL_VERIFICATION => 'Votre code de vérification',
            OtpPurpose::PASSWORD_RESET => 'Votre code de réinitialisation de mot de passe',
            OtpPurpose::INVITATION => 'Votre code de confirmation',
        };
    }

    private function introFor(OtpPurpose $purpose): string
    {
        return match ($purpose) {
            OtpPurpose::LOGIN => 'Vous avez demandé à vous connecter. Entrez ce code dans l\'application pour continuer.',
            OtpPurpose::PHONE_VERIFICATION => 'Entrez ce code dans l\'application pour continuer votre inscription.',
            OtpPurpose::EMAIL_VERIFICATION => 'Entrez ce code pour confirmer votre adresse email.',
            OtpPurpose::PASSWORD_RESET => 'Vous avez demandé la réinitialisation de votre mot de passe. Entrez ce code pour continuer.',
            OtpPurpose::INVITATION => 'Entrez ce code pour confirmer votre invitation.',
        };
    }
}
