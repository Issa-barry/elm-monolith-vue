<?php

namespace App\Services\Otp;

use App\Enums\OtpChannel;

/**
 * Masque une destination OTP (email ou téléphone) pour affichage côté
 * client — jamais la valeur complète, qui ne doit jamais quitter le
 * backend pour cet usage (cf. rapport du 27/08/2026, section "destination
 * masquée"). Extrait de PasswordReset\LookupController::maskEmail() (déjà
 * en production pour la réinitialisation de mot de passe) pour être
 * réutilisé par OtpLogin\RequestController sans dupliquer la logique.
 */
final class OtpDestinationMasker
{
    public function mask(OtpChannel $channel, string $destination): string
    {
        return match ($channel) {
            OtpChannel::EMAIL => $this->maskEmail($destination),
            OtpChannel::SMS, OtpChannel::WHATSAPP => $this->maskPhone($destination),
        };
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        $visible = substr($local, 0, 1);
        $masked = $visible.str_repeat('*', max(1, strlen($local) - 1));

        return $masked.'@'.$domain;
    }

    /**
     * SMS/WhatsApp non câblés aujourd'hui (aucun fournisseur configuré, cf.
     * OtpChannelResolver) — ce chemin n'est donc pas encore emprunté en
     * pratique, préparé pour ne pas avoir à revenir sur le contrat API le
     * jour où un canal SMS/WhatsApp devient réellement disponible. Garde les
     * 3 premiers chiffres (indicatif pays approximatif) et les 2 derniers
     * visibles, masque le reste.
     */
    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) <= 6) {
            return str_repeat('•', strlen($digits));
        }

        $visibleStart = substr($digits, 0, 3);
        $visibleEnd = substr($digits, -2);
        $maskedLength = strlen($digits) - strlen($visibleStart) - strlen($visibleEnd);

        return '+'.$visibleStart.' '.str_repeat('•', $maskedLength).' '.$visibleEnd;
    }
}
