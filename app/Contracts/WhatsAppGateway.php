<?php

namespace App\Contracts;

/**
 * Contrat FOURNISSEUR WhatsApp — même niveau d'abstraction que
 * `App\Contracts\SmsGateway` (jamais mélangé avec lui : SMS et WhatsApp sont
 * deux canaux indépendants, cf. rapport notifications de commande,
 * 07/09/2026, point "SMS et WhatsApp sont deux canaux indépendants" — aucun
 * fallback automatique entre les deux).
 *
 * Aucun fournisseur réel n'est câblé à ce jour : `App\Providers\AppServiceProvider`
 * lie ce contrat à `App\Services\Communications\NullWhatsAppGateway`, qui
 * déclare toujours `isConfigured() === false`. `isConfigured()` est LE point de
 * contrôle qui empêche toute règle WhatsApp de s'activer tant qu'aucun
 * fournisseur réel n'est branché — jamais un envoi silencieusement ignoré une
 * fois une règle activée (cf. App\Services\Communications\CommunicationRuleResolver
 * et App\Http\Controllers\Settings\CommunicationRuleController::update(), qui
 * vérifient tous deux ce même flag). Le jour où un fournisseur WhatsApp réel
 * est intégré, il implémente ce contrat et remplace ce binding — sans toucher
 * au moteur de règles ni aux contrôleurs métier (même principe que
 * NimbaSmsGateway pour SmsGateway).
 */
interface WhatsAppGateway
{
    public function isConfigured(): bool;

    /**
     * @return string|null Identifiant fournisseur de l'envoi, si disponible.
     *
     * @throws \RuntimeException Fournisseur non configuré, réponse en échec,
     *                           ou erreur réseau/timeout.
     */
    public function send(string $phoneNumber, string $message): ?string;
}
