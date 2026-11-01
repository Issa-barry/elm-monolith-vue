<?php

namespace App\Enums;

/**
 * Statut d'une version générée d'un contrat de travail (ADR 0044) : à signer → signé (figé) ; une
 * version à signer devient « remplacée » quand une nouvelle version est générée. Une version signée
 * n'est jamais remplacée ni modifiée : une nouvelle génération crée une version de plus.
 */
enum StatutDocumentContrat: string
{
    case A_SIGNER = 'a_signer';
    case SIGNE = 'signe';
    case REMPLACE = 'remplace';

    public function label(): string
    {
        return match ($this) {
            self::A_SIGNER => 'À signer',
            self::SIGNE => 'Signé',
            self::REMPLACE => 'Remplacé',
        };
    }
}
