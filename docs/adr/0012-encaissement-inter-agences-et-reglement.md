# ADR 0012 — Encaissement dans une autre agence et règlement inter-agences

- **Date** : 2026-09-29
- **Statut** : accepté — lot 1 (modèle, encaissement, comptabilité, dette, règlement côté service)
  livré le 2026-09-29 ; lot 2 (écran Inter-agences, règlement dans l'interface) développé le
  2026-09-29 ; lot 3 (rapports, Situation, financement, E2E) à venir
- **Périmètre** : encaissements de vente, trésorerie, mouvements de fonds, comptabilité générale —
  cf. [tresorerie-inter-agences.md](../tresorerie-inter-agences.md)

## Contexte

Le métier veut qu'une commande créée dans l'agence A puisse être payée dans n'importe quelle agence
B. Jusqu'ici, l'agence d'encaissement était confondue avec celle de la facture à trois endroits
(moyens proposés, caisse dédiée des espèces, site de la pièce comptable) : B ne pouvait ni retrouver
la commande (listes limitées à ses agences), ni utiliser ses supports (refus 422), ni recevoir
l'argent dans sa trésorerie (pièce posée sur le site A, solde des supports calculé par compte + site).

## Décision

1. **Deux agences distinctes sur un encaissement.** La vente, le chiffre d'affaires, la créance
   client, les commissions, le cashback et le stock restent à l'agence de la **commande**
   (`factures_ventes.site_id`, inchangé). L'argent est reçu par l'agence **d'encaissement**
   (`encaissements_ventes.site_encaissement_id`) : ses moyens, sa caisse dédiée, sa trésorerie.
2. **L'agence d'encaissement est toujours l'une des agences de l'utilisateur** (`user_sites`),
   jamais un choix libre, sans passe-droit de rôle. Encaisser ailleurs que dans l'agence de la
   commande exige la permission `factures.encaisser_autre_agence` (accordée par défaut à
   `admin_entreprise` ; `super_admin` l'a d'office). L'agence de la commande est présélectionnée
   quand l'utilisateur y est affecté. Sans agence demandée, rien ne change : agence de la facture.
3. **Deux pièces mono-site reliées par un compte de liaison (181000)**, comme les mouvements de
   fonds : sur le site B, débit trésorerie / crédit liaison [tiers A] ; sur le site A, débit
   liaison [tiers B] / crédit client. La liaison se solde à 0 au niveau de l'organisation ; par
   agence et contrepartie, elle porte exactement la dette.
4. **La dette est dérivée des encaissements**, jamais saisie ni stockée à part : un encaissement dont
   l'agence d'encaissement diffère de celle de la commande est une ligne de dette, de son montant.
   Un paiement partiel ne crée de dette que pour la part encaissée ailleurs.
5. **Seul un règlement inter-agences solde une dette.** C'est un mouvement de fonds existant
   (workflow, contrôle de solde, contestation, retour inchangés) de nature `reglement_agences`, lié
   aux encaissements précis qu'il reverse (`mouvement_fonds_encaissements`). Son montant est la somme
   de ces encaissements, calculée par le serveur. Ses écritures ont la liaison pour contrepartie
   (au lieu du transit 588000) : envoi = dette de B soldée, réception = créance de A soldée. Un
   mouvement « Entre agences » ordinaire (remise au siège, financement) ne solde jamais rien.
6. **Jamais de double rapprochement** : un encaissement n'appartient qu'à un règlement actif —
   contrôle sous verrou, et index unique `encaissement_actif_id` en base. Un règlement annulé
   (brouillon) ou retourné libère ses encaissements.
7. **Pas de compensation automatique** : les dettes A → B et B → A restent séparées.
8. **« Régler » crée ET envoie le règlement** en une seule opération et une seule transaction
   (décision du 29/09/2026) : si l'envoi échoue (solde insuffisant…), aucun règlement ni brouillon
   ne reste. Autorisation entièrement serveur (`MouvementFondsPolicy::regler` : `tresorerie.create`
   + `tresorerie.envoyer` + affectation à l'agence qui verse, admin : toute l'organisation).
9. **Annulation** : un encaissement engagé dans un règlement actif (même en brouillon) ne peut plus
   être supprimé, ni par la suppression unitaire, ni par l'annulation exceptionnelle. Avant tout
   règlement, sa suppression contrepasse ses deux pièces et la dette disparaît. Le retour d'argent
   après règlement relèvera d'un futur mécanisme de remboursement/régularisation.

## Conséquences

- Une nouvelle recherche « Encaisser une commande d'une autre agence » (référence exacte) permet à B
  de retrouver la commande sans ouvrir les listes des autres agences.
- Les sorties d'argent de B (paiements, mouvements) restent contrôlées sur le solde physique du
  support : pas de blocage lié à la dette dans ce chantier (décision du 29/09/2026). Le financement
  (lot 3) déduira en revanche du disponible de B ce qu'il doit aux autres agences.
- Les rapports distingueront deux axes (lot 3) : vente/créance → agence de la commande ;
  encaissement/trésorerie → agence d'encaissement.
- Historique : chaque encaissement existant reçoit l'agence de sa facture (valeur exacte). Aucune
  pièce comptable existante n'est modifiée.
