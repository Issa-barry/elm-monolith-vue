<?php

namespace App\Exceptions;

/**
 * Erreur de transport SMS via Nimba (config manquante, réponse non-2xx,
 * timeout/erreur réseau) — cf. App\Services\Sms\NimbaSmsGateway. Le message
 * ne contient jamais le Secret Token, l'en-tête Authorization ni le contenu
 * du SMS (donc jamais le code OTP en clair).
 */
class NimbaSmsException extends \RuntimeException {}
