<?php

namespace App\Services\Communications;

use App\Contracts\WhatsAppGateway;

/**
 * Implémentation par défaut de `WhatsAppGateway` tant qu'aucun fournisseur
 * WhatsApp réel n'est intégré (cf. docblock du contrat) — `isConfigured()`
 * retourne toujours `false`, ce qui empêche structurellement toute règle
 * WhatsApp de s'activer (App\Services\Communications\CommunicationRuleResolver,
 * App\Http\Controllers\Settings\CommunicationRuleController::update()).
 * `send()` ne doit donc jamais être appelé en pratique — il lève une exception
 * plutôt que d'échouer silencieusement si ce garde-fou était contourné.
 */
class NullWhatsAppGateway implements WhatsAppGateway
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $phoneNumber, string $message): ?string
    {
        throw new \RuntimeException(
            'NullWhatsAppGateway::send() ne doit jamais être appelé — isConfigured() est toujours faux, '.
            'ce qui doit empêcher toute règle WhatsApp de déclencher un envoi.'
        );
    }
}
