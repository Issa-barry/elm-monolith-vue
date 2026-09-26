<?php

namespace App\Services\Otp;

use App\Enums\OtpChannel;

/**
 * Canal + destination de repli EXPLICITE, calculé UNE SEULE FOIS par
 * `OtpChannelResolver::fallbackFor()` au moment où le canal primaire est
 * choisi — jamais recalculé/deviné plus tard (ex: dans un job async après
 * l'échec réel de l'envoi). Cf. audit du 31/08/2026, point 2 : avant ce
 * correctif, un canal choisi "disponible" (identifiants présents) pouvait
 * échouer à l'envoi réel (panne fournisseur) sans qu'aucun repli ne soit
 * réellement déclenché — ceci comble ce trou, sans faire de
 * `OtpChannelResolver::firstAvailableFor()` une politique de fallback
 * d'ENVOI (ce qu'elle n'a jamais eu vocation à être, cf. son docblock).
 */
final readonly class OtpFallbackTarget
{
    public function __construct(
        public OtpChannel $channel,
        public string $destination,
    ) {}
}
