<?php

namespace App\Exceptions;

/**
 * Erreur métier liée aux invitations, dont le message est toujours sûr à
 * afficher tel quel à l'utilisateur (contrairement aux exceptions techniques
 * — ex. échec SMTP — qui ne doivent jamais remonter telles quelles à l'UI).
 */
class InvitationException extends \RuntimeException {}
