<?php

namespace App\Enums;

/**
 * Workflow : BROUILLON → ENVOYE → RECU (nominal), ou BROUILLON → ANNULE.
 *
 * Depuis ENVOYE, le site destinataire peut CONTESTER (« je n'ai rien reçu »)
 * sans qu'aucune contrepassation ne soit appliquée à ce stade — une
 * contestation n'est pas une preuve que l'argent est physiquement revenu à
 * l'origine (revue Codex du 2026-08-22, corrige la V1 qui contrepassait dès
 * le rejet). Depuis CONTESTE, deux issues possibles après investigation :
 *   - RECU : il s'agissait d'une erreur, les fonds avaient bien été reçus ;
 *   - RETOURNE : les fonds ont été physiquement rapportés à l'origine —
 *     seule cette transition déclenche la contrepassation de la pièce
 *     d'émission (cf. MouvementFondsService::confirmerRetour()).
 *
 * Aucune transition n'est possible depuis RECU/ANNULE/RETOURNE (terminaux).
 */
enum StatutMouvementFonds: string
{
    case BROUILLON = 'brouillon';
    case ENVOYE = 'envoye';
    case CONTESTE = 'conteste';
    case RECU = 'recu';
    case RETOURNE = 'retourne';
    case ANNULE = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::ENVOYE => 'Envoyé',
            self::CONTESTE => 'Contesté',
            self::RECU => 'Reçu',
            self::RETOURNE => 'Retourné',
            self::ANNULE => 'Annulé',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::RECU, self::ANNULE, self::RETOURNE], true);
    }

    /**
     * Fonds ni disponibles à l'origine (déjà décaissés) ni encore confirmés à
     * la destination — inclut CONTESTE : un litige non résolu ne rend pas les
     * fonds disponibles quelque part, cf. TresorerieDisponibiliteService.
     */
    public function isEnTransit(): bool
    {
        return in_array($this, [self::ENVOYE, self::CONTESTE], true);
    }
}
