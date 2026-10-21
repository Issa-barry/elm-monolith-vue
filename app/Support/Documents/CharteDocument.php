<?php

namespace App\Support\Documents;

/**
 * Seules couleurs ajoutées au design d'origine du modèle officiel (facture d'achat) : les deux tons
 * du nom de l'organisation, repris du logo « EAU LA MAMAN » — bleu foncé de « EAU LA » pour le
 * début du nom, bleu clair de « MAMAN » pour son dernier mot. Tout le reste garde les couleurs
 * d'origine.
 */
final class CharteDocument
{
    public const NOM_FONCE = '#2B5196';

    public const NOM_CLAIR = '#56ADE2';
}
