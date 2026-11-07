<?php

namespace App\Support\Contacts;

/**
 * Messages du référentiel Contacts partagés par plusieurs points d'entrée (ADR 0060, lot 3).
 * Le message « numéro connu » est volontairement neutre : il ne révèle rien de la fiche existante
 * (décision 1 du lot 3), et la renvoie vers Contacts, où la fonction s'ajoute après confirmation.
 */
final class MessagesContacts
{
    public const NUMERO_CONNU = 'Ce numéro est déjà enregistré dans les contacts. Retrouvez la fiche depuis Contacts (Personnes physiques ou Entreprises) pour lui ajouter cette fonction.';

    public const NUMERO_NON_VISIBLE = 'Ce numéro est déjà enregistré dans les contacts, sur une fiche que vous ne pouvez pas consulter. Adressez-vous à un administrateur.';

    public const AUTRE_PERSONNE = 'Ce numéro appartient déjà à une autre fiche. Un même numéro ne peut pas être enregistré deux fois : vérifiez le numéro saisi. Le retrait d\'un numéro réattribué n\'est pas encore disponible.';
}
