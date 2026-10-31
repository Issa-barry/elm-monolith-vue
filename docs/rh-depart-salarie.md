# Départ d'un salarié et contrats successifs

Décision : [ADR 0045](adr/0045-dates-contrat-depart-salarie.md) (31/10/2026). Voir aussi
[rh-employes-identite.md](rh-employes-identite.md), [paie.md](paie.md).

## 1. Quatre dates distinctes

| Date | Où | Rôle |
|---|---|---|
| Date d'embauche | Dossier (`employes.date_embauche`) | Début de la relation de travail (ancienneté). Renseignée par le premier contrat si vide, corrigeable dans la fiche. |
| Début du contrat | Contrat (`contrats.date_debut`) | Premier jour prévu d'exécution de ce contrat. |
| Fin prévue du contrat | Contrat (`contrats.date_fin`) | Obligatoire pour un CDD, vide pour un CDI. |
| Date de sortie effective | Dossier (`employes.date_sortie`) + motif | Départ réel, par « Enregistrer le départ ». |

La fin prévue d'un CDD ne fait jamais sortir le salarié : le contrat peut être suivi d'un autre.

## 2. Contrats successifs

- Un salarié peut avoir plusieurs contrats au fil du temps (CDD, renouvellement, CDI…), un seul
  **actif** à la fois (règle existante). Chaque contrat garde ses propres dates et son salaire ;
  l'historique est sur la fiche employé.
- **Deux contrats ne peuvent pas couvrir les mêmes jours** (création et modification refusées :
  « Ces dates chevauchent un autre contrat de cet employé… »). Un contrat terminé ou rompu sans date
  de fin n'a pas de période connue : il est ignoré, comme dans la paie.
- La paie prend, pour chaque mois, le ou les contrats qui le couvrent (découpage par contrat) et
  signale toujours deux contrats chevauchants comme anomalie bloquante.

## 3. Enregistrer le départ

Bouton sur la fiche employé. Saisie : **date de sortie effective** et **motif** (obligatoires).

Contrôles serveur :
- salarié pas déjà sorti ; date pas dans le futur ; pas avant la date d'embauche ni avant le début
  du contrat en cours ;
- la date ne peut pas toucher un mois de paie figé (transmission RH validée ou paie validée :
  `CalendrierPaieService`).

Effets, en une transaction (salarié verrouillé) :
1. le salarié passe en « Sorti » avec sa date et son motif de sortie ;
2. son contrat actif est **terminé** à la date de sortie (un CDD déjà échu garde sa date de fin) ;
   sa date de fin d'origine est conservée (`date_fin_avant_depart`, `termine_par_depart`) ;
3. la paie s'arrête à la date de sortie (période d'emploi bornée, inchangé).

Les primes et avantages en cours ne sont pas modifiés : la paie ne les reprend plus au-delà de la
date de sortie.

## 4. Annuler le départ

Bouton sur la fiche d'un salarié sorti. Refusé si le mois de la date de sortie est figé, ou si un
autre contrat est actif. Sinon : salarié de nouveau « Actif », date et motif de sortie effacés,
contrat terminé par le départ rétabli tel qu'il était (statut actif, date de fin d'origine).

Un salarié sorti avant le 31/10/2026 par le formulaire (sans contrat marqué) peut aussi être
rétabli : seul son statut est concerné.

## 5. Accès

| Action | Règle |
|---|---|
| Enregistrer / annuler le départ | `rh-employes.update` + `rh-contrats.update`, même organisation, salarié d'une agence de rattachement (sauf administrateur ou superviseur) |

Les boutons ne s'affichent que si le serveur accepterait l'action (`peut_gerer_depart`). Le statut
« Sorti » ne se choisit plus dans le formulaire de l'employé (création : Actif ou Suspendu ; un
salarié sorti garde son statut).

## 6. Données

Migration `2026_10_31_100000_add_depart_to_contrats_table` : deux colonnes nouvelles sur
`contrats` (`termine_par_depart` faux par défaut, `date_fin_avant_depart` vide). Aucun contrat ni
salarié existant n'est modifié.
