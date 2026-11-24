# Décisions d'architecture et métier (ADR)

> Index des ADR au 15/11/2026, tiré de l'en-tête de chaque fichier. Le détail d'un statut (lots
> livrés, amendements) est dans l'ADR lui-même.

Un ADR enregistre une décision structurante : son contexte, la décision, ses conséquences. Il ne se
réécrit pas : une décision qui change fait l'objet d'un **nouvel** ADR, qui indique celui qu'il
amende ou remplace.

## Créer un ADR

1. Prendre le numéro suivant (le dernier est indiqué en bas du tableau) : `NNNN-titre-court.md`
   dans ce dossier.
2. Reprendre l'en-tête des ADR existants : `# ADR NNNN — Titre`, puis `Date`, `Statut`, et les liens
   (ADR amendés, documentation).
3. Statut : `proposé` tant que la décision n'est pas validée, puis `accepté` (avec la date), et
   éventuellement `livré`.
4. Ajouter la ligne au tableau ci-dessous et mettre à jour le document métier concerné dans la même
   pull request.

## Index

| ADR | Décision | Date | Statut |
|---|---|---|---|
| [0001](0001-caisse-dediee-agent-sous-compte.md) | Caisse dédiée à un agent : un sous-compte comptable par caisse | 2026-09-19 | Accepté |
| [0002](0002-cycle-de-vie-support-tresorerie.md) | Cycle de vie d'un support de trésorerie : brouillon → validé (actif) → désactivé | 2026-09-19 | Accepté et livré |
| [0003](0003-retour-de-livraison-avant-encaissement.md) | Retour de livraison avant encaissement : stock réintégré, commission réajustée | 2026-09-23 | Accepté et livré |
| [0004](0004-annulation-exceptionnelle-commande-saisie-par-erreur.md) | Annulation exceptionnelle d'une commande saisie par erreur, confirmation paramétrable | 2026-09-24 | Accepté et livré |
| [0005](0005-moyens-de-paiement-derives-des-supports.md) | Moyens de paiement dérivés des supports de trésorerie de l'agence | 2026-09-24 | Accepté et livré |
| [0006](0006-partage-livreur-conforme-et-regularisation.md) | Partage Livreur conforme obligatoire et régularisation des commissions partielles | 2026-09-24 | Accepté |
| [0007](0007-rapport-activite-perimetre-et-regles-de-calcul.md) | Rapport d'activité : un moteur, un périmètre serveur, des blocs indépendants | 2026-09-26 | Accepté |
| [0008](0008-validation-automatique-des-periodes-de-paiement.md) | Validation automatique des périodes de paiement et des commissions à bénéficiaire unique | 2026-09-27 | Accepté |
| [0009](0009-paiement-de-fiche-decaissement-tresorerie.md) | Payer une fiche = décaissement réel depuis un support de trésorerie | 2026-09-28 | Accepté et livré |
| [0010](0010-fiche-unite-de-validation-et-de-paiement.md) | La fiche de paiement, unité de validation et de paiement | 2026-09-28 | Accepté |
| [0011](0011-permissions-des-roles-systeme-non-ecrasees-au-deploiement.md) | Les permissions des rôles système ne sont plus écrasées au déploiement | 2026-09-28 | Accepté |
| [0012](0012-encaissement-inter-agences-et-reglement.md) | Encaissement dans une autre agence et règlement inter-agences | 2026-09-29 | Accepté |
| [0013](0013-monitoring-des-commissions-non-generees.md) | Monitoring des commissions non générées : une vue dérivée, pas un second registre | 2026-09-30 | Accepté |
| [0014](0014-unicite-des-references-mobile-money.md) | Unicité des références Mobile Money | 2026-10-01 | Accepté |
| [0015](0015-mot-de-passe-sous-controle-exclusif-du-titulaire.md) | Le mot de passe reste sous le contrôle exclusif de son titulaire | 2026-10-01 | Accepté |
| [0016](0016-remise-au-siege-et-position-des-agences.md) | Remise à la trésorerie principale et position de trésorerie des agences | 2026-10-02 | Accepté |
| [0017](0017-type-de-site-et-site-central-de-tresorerie.md) | Découplage du type de site et du rôle de site central de trésorerie | 2026-10-02 | Accepté |
| [0018](0018-approvisionnement-caisse-agent.md) | Approvisionnement de la caisse d'un agent depuis la caisse de l'agence | 2026-10-04 | Accepté |
| [0019](0019-precommande-vente-avec-acompte-et-reservation.md) | Précommande : vente marquée, acompte en avance client, réservation stricte | 2026-10-04 | Accepté |
| [0020](0020-agence-de-paiement-des-commissions-site-du-vehicule.md) | Agence qui paie une commission livreur/propriétaire : site actuel du véhicule | 2026-10-06 | Accepté |
| [0021](0021-achats-bon-de-commande-valide-par-plafond-de-role.md) | Achats : bon de commande direct, validation par plafond du rôle, réception dans Logistique | 2026-10-07 | Accepté |
| [0022](0022-factures-fournisseurs-dette-et-comptabilisation.md) | Factures fournisseurs : dette à la validation, comptabilisation par mapping | 2026-10-08 | Accepté (mécanisme) |
| [0023](0023-usage-grossiste-du-vehicule.md) | Usage « Grossiste » du véhicule, indépendant de la logistique | 2026-10-09 | Accepté |
| [0024](0024-paiement-des-factures-fournisseurs.md) | Paiement des factures fournisseurs : décaissement réel depuis l'agence de la facture | 2026-10-09 | Accepté |
| [0025](0025-vision-360-consultation-de-toutes-les-agences.md) | Vision 360° : consulter les données de toutes les agences, sans élargir l'écriture | 2026-10-10 | Accepté |
| [0026](0026-depot-en-banque-depuis-caisse-ou-mobile-money.md) | Dépôt en banque depuis une caisse ou un Mobile Money de l'agence | 2026-10-13 | Accepté |
| [0027](0027-compte-mobile-money-dedie-agent.md) | Compte Mobile Money dédié à l'agent et transfert vers l'agence | 2026-10-13 | Accepté |
| [0028](0028-suppression-brouillon-et-desactivation-controlee-des-supports.md) | Suppression d'un support en brouillon et désactivation contrôlée des supports | 2026-10-15 | Accepté et livré |
| [0029](0029-retour-au-site-source-sur-transfert-logistique.md) | Retour de marchandise au site source sur un transfert logistique | 2026-10-16 | Accepté |
| [0030](0030-suppression-du-numero-des-supports-de-tresorerie.md) | Suppression du numéro des supports de trésorerie (donnée sensible) | 2026-10-16 | Accepté et livré |
| [0031](0031-rattachement-depense-carburant-commande.md) | Rattachement d'une dépense de carburant à sa commande | 2026-10-16 | Accepté et livré |
| [0032](0032-signalement-ecarts-reception-transfert.md) | Signalement des écarts de réception d'un transfert logistique | 2026-10-18 | Accepté |
| [0033](0033-modele-officiel-factures-et-bons-pdf.md) | Modèle officiel partagé des factures et bons PDF | 2026-10-18 | Accepté |
| [0034](0034-superviseur-agit-sur-toutes-les-agences.md) | Le superviseur agit sur toutes les agences, comme un administrateur | 2026-10-19 | Accepté |
| [0035](0035-part-du-parrain-dans-lenveloppe-livreur.md) | La part du parrain est prise sur l'enveloppe Livreur | 2026-10-21 | Accepté |
| [0036](0036-controle-des-encaissements.md) | Contrôle des encaissements : détecter, alerter, régulariser sous contrôle humain | 2026-10-24 | Accepté |
| [0037](0037-paiement-des-salaires-par-la-comptabilite.md) | Paiement des salaires par la Comptabilité | 2026-10-26 | Accepté |
| [0038](0038-referentiel-primes-et-avantages.md) | Référentiel des primes et avantages | 2026-10-28 | Accepté |
| [0039](0039-affectations-primes-salaries.md) | Affectations datées des primes et avantages aux salariés | 2026-10-29 | Accepté |
| [0040](0040-transmission-paie-figee-versionnee.md) | Transmission mensuelle RH → Comptabilité, figée et versionnée | 2026-10-29 | Accepté |
| [0041](0041-reprise-transmission-paie-comptabilite.md) | Reprise de la transmission RH par la Comptabilité | 2026-10-29 | Accepté |
| [0042](0042-capacite-vehicule-obligatoire-par-categorie.md) | Capacité véhicule obligatoire pour vendre une catégorie | 2026-10-30 | Accepté |
| [0043](0043-regularisation-vente-apres-encaissement.md) | Régularisation d'une vente après encaissement | 2026-10-30 | Accepté |
| [0044](0044-contrat-de-travail-genere-modele-versions.md) | Contrat de travail généré : modèle par type, instantané figé, versions | 2026-10-30 | Accepté |
| [0045](0045-dates-contrat-depart-salarie.md) | Date d'embauche reprise du contrat, départ par une action dédiée | 2026-10-31 | Accepté |
| [0046](0046-prime-categorie-unique.md) | Une seule catégorie à la création des types : Prime | 2026-10-31 | Accepté |
| [0047](0047-validation-rh-met-a-disposition-comptabilite.md) | La validation RH met la paie à disposition de la Comptabilité | 2026-10-31 | Accepté |
| [0048](0048-contrat-genere-automatiquement-pdg-signataire.md) | Contrat de travail généré automatiquement, PDG signataire désigné | 2026-11-01 | Accepté |
| [0049](0049-paiement-salaire-decaissement-reel.md) | Payer un salaire = décaissement réel depuis un support de trésorerie | 2026-11-01 | Accepté |
| [0050](0050-rattachement-manuel-comptes-parrain-consultant.md) | Relier un compte à un parrain ou à un consultant : rattachement manuel, sans toucher à l'authentification | 2026-11-02 | Accepté |
| [0051](0051-modification-identite-espace-client.md) | Prénom, nom et e-mail modifiables depuis l'espace client | 2026-11-02 | Accepté |
| [0052](0052-primes-gerees-depuis-la-fiche-salarie.md) | Primes gérées depuis la fiche salarié, indépendantes de la durée du contrat | 2026-11-03 | Accepté |
| [0053](0053-filtre-periode-comptable-espace-client.md) | Filtre période comptable P1/P2 de l'espace client | 2026-11-03 | Accepté |
| [0054](0054-gains-multi-activites-espace-client.md) | Gains multi-activités de l'espace client | 2026-11-03 | Accepté |
| [0055](0055-compte-unique-back-office-espace-client.md) | Compte unique et front Nuxt ouvert à tous | 2026-11-04 | Accepté |
| [0056](0056-rattachement-automatique-meme-personne.md) | Rattachement automatique des activités d'une personne à son compte | 2026-11-04 | Accepté |
| [0057](0057-standard-unique-des-periodes.md) | Standard unique des filtres de période | 2026-11-04 | Accepté |
| [0058](0058-liste-unique-commandes-espace-client.md) | Liste unique « Commandes » de l'espace client | 2026-11-06 | Accepté |
| [0059](0059-autorisation-api-backoffice-mobile.md) | Autorisation de l'API back-office mobile et identité du livreur | 2026-11-05 | Accepté |
| [0060](0060-referentiel-contacts.md) | Référentiel Contacts : deux fiches d'identité, des fonctions qui gardent leurs ids | 2026-11-06 | Accepté |
| [0061](0061-agences-payeuses-des-commissions.md) | Agences payeuses des commissions : politique configurable | 2026-11-07 | Accepté |
| [0062](0062-garde-des-transactions-annulees.md) | Garde des transactions annulées par la base | 2026-11-09 | Accepté |
| [0063](0063-rapport-activite-par-agence.md) | Rapport d'activité par agence et remise à la trésorerie principale | 2026-11-13 | Accepté |
| [0064](0064-remise-agent-agence-et-harmonisation-rapports.md) | Remise de l'agent à son agence et harmonisation des rapports | 2026-11-14 | Accepté |
| [0065](0065-visibilite-des-depenses-par-agence.md) | Visibilité des dépenses par agence, configurable par rôle | 2026-11-15 | Accepté |
| [0066](0066-qualification-commerciale-des-clients-crees-a-l-inscription.md) | Qualification commerciale des clients créés à l'inscription | 2026-11-16 | Accepté |
| [0067](0067-colonne-primes-et-avantages-transmission-paie.md) | Colonne « Primes et avantages » de la transmission de paie | 2026-11-17 | Accepté |
| [0068](0068-paiement-groupe-des-fiches-de-commission.md) | Paiement groupé des fiches de commission | 2026-11-19 | Accepté |
| [0069](0069-idempotence-des-paiements-de-fiche.md) | Idempotence des paiements de fiche | 2026-11-20 | Accepté |
| [0070](0070-absences-des-salaries.md) | Absences des salariés, distinctes des congés | 2026-11-23 | Accepté |

Dernier numéro attribué : **0070**. Le prochain ADR sera le **0071**.
