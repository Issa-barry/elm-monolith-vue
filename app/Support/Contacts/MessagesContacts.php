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

    /** Lot 5 (D6) : NIF d'une entreprise que le lecteur ne peut pas consulter. */
    public const NIF_NON_VISIBLE = 'Ce NIF est déjà enregistré dans les contacts, sur une fiche que vous ne pouvez pas consulter. Adressez-vous à un administrateur.';

    /** Lot 9b (décision 3) : numéro secondaire d'une fiche, reconnu mais jamais rattaché automatiquement. */
    public const NUMERO_SECONDAIRE = "Ce numéro est le numéro secondaire de la fiche ci-dessous. Il n'est rattaché automatiquement à aucune fiche et aucune nouvelle fiche ne peut être créée avec lui : ouvrez la fiche pour vérifier.";

    public const NUMERO_SECONDAIRE_CONNU = "Ce numéro est déjà le numéro secondaire d'une fiche des contacts : aucune nouvelle fiche ne peut être créée avec lui. Recherchez-le depuis Nouveau contact.";

    /** CONTACT-063 : tout formulaire de l'application. %s = nom et fonctions de la fiche visible. */
    public const NUMERO_SECONDAIRE_FICHE = "Ce numéro est le numéro secondaire de la fiche « %s ». Il ne peut pas servir à une autre identité : utilisez cette fiche depuis Contacts, ou retirez-lui d'abord ce numéro si le numéro a changé de titulaire.";

    public const NUMERO_SECONDAIRE_NON_VISIBLE = "Ce numéro est le numéro secondaire d'une fiche des contacts que vous ne pouvez pas consulter : il ne peut pas servir à une autre identité. Adressez-vous à un administrateur.";

    public const AUTRE_PERSONNE = 'Ce numéro appartient déjà à une autre fiche. Un même numéro ne peut pas être enregistré deux fois : vérifiez le numéro saisi. Le retrait d\'un numéro réattribué n\'est pas encore disponible.';
}
