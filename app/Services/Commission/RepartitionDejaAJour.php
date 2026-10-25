<?php

namespace App\Services\Commission;

use RuntimeException;

/**
 * Signal interne du recalcul rétroactif (PARRAIN-023) : la commission recalculée est identique,
 * le point de sauvegarde est restauré — ni nouvelle enveloppe, ni réouverture de période.
 */
final class RepartitionDejaAJour extends RuntimeException {}
