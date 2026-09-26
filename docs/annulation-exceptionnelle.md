# Annulation exceptionnelle d'une commande saisie par erreur

Décision produit du 24/09/2026 — cf. [ADR 0004](adr/0004-annulation-exceptionnelle-commande-saisie-par-erreur.md).

## Pourquoi

Des employés ont saisi en **production** des commandes qu'ils croyaient saisir sur l'environnement de
**formation** : commandes confirmées, chargées, facturées, parfois encaissées. Aucune vente n'a eu
lieu. L'annulation normale (`CommandeVenteService::annuler()`) n'est possible qu'avant le départ du
véhicule et sans encaissement ; le retour de livraison suppose une marchandise réellement partie et
revenue. Il fallait un moyen d'effacer proprement l'effet d'une saisie fictive.

## Règle

| Situation de la commande | Action disponible |
|---|---|
| Brouillon, À charger, À encaisser (vente directe) **sans encaissement** | Annulation normale (« Annuler ») |
| Chargement en cours, en livraison, livrée, clôturée, ou tout statut **avec encaissement** | **Annulation exceptionnelle** |
| Déjà annulée, annulée exceptionnellement ou retournée | Aucune |

L'annulation exceptionnelle est **refusée** si :

- la commission de la commande a déjà été validée, ajustée ou payée (même règle que le retour :
  `CommissionTriggerService::aDesCommissionsFigees()`) ;
- le cashback généré par la commande a déjà été validé ou versé ;
- les espèces encaissées ont déjà quitté la caisse dédiée de l'agent (versement à l'agence) :
  contrepasser l'encaissement rendrait la caisse négative. Annuler d'abord le versement ;
- un retour de livraison a déjà été enregistré sur la commande.

Ces refus sont affichés dans le récapitulatif et **revérifiés côté serveur** à la demande de code et
à la confirmation.

## Effets (une seule transaction)

1. **Encaissements** supprimés ; leur écriture `encaissement_vente_recu` est **contrepassée**, jamais
   effacée (hook `EncaissementVente::deleted`) — l'argent fictif sort de la caisse qui l'avait reçu.
2. **Facture** annulée ; écriture `vente_facturee` contrepassée.
3. **Stock** : réservations libérées, sorties (vente directe ou chargement) annulées par
   contre-mouvement — la marchandise n'a jamais réellement quitté le stock. (Si elle était réellement
   partie puis revenue, c'est un **retour**, pas cette procédure.)
4. **Commissions** non soldées annulées.
5. **Cashback** encore en attente : transaction de gain supprimée et compteurs du client
   (`cashback_en_attente`, `total_cashback_gagne`, `cumul_achats`) remis à leur état d'avant la vente.
6. **Commande** en statut `annulee_erreur_saisie` (« Annulée (erreur de saisie) »), motif, auteur et
   date renseignés. Statut terminal, distinct d'`annulee` ; une telle commande n'est jamais
   supprimable (sa trace doit rester consultable).
7. **Trace** : une ligne dans `annulations_exceptionnelles`, une entrée d'audit `cancelled`, une
   entrée `encaissement_deleted` par encaissement et une activité `annulee_erreur_saisie`.

Les statistiques de la liste des ventes, le CA par produit du tableau de bord et la situation
véhicule excluent ce statut comme `annulee`.

## Confirmation par code envoyé par e-mail

### Mode de confirmation — paramètre d'organisation

Paramètres → Ventes → **Confirmation des annulations exceptionnelles**
(`Parametre::CLE_VENTES_ANNULATION_EXCEPTIONNELLE_CONFIRMATION` =
`ventes_annulation_exceptionnelle_confirmation`, enum `ModeConfirmationAnnulationExceptionnelle`) :

| Valeur | Comportement |
|---|---|
| `email_code` (**défaut**) | Code à usage unique envoyé par e-mail, à saisir avant l'exécution. |
| `simple` | Confirmation directe (« Êtes-vous sûr… ? Cette opération ne peut pas être annulée. »), sans code. |

- Le mode est lu **par le serveur** au récapitulatif, à la demande de code et à la confirmation —
  jamais transmis par le frontend. En `email_code`, une confirmation sans code est refusée même par
  appel direct ; en `simple`, la demande de code est refusée. Si le réglage change entre l'affichage
  et la confirmation, c'est le réglage courant qui s'applique.
- **Modifier** ce réglage exige `parametres.update` **et** `ventes.annuler_exceptionnel` : administrer
  les paramètres de vente ne permet pas d'abaisser la protection d'une opération qu'on ne peut pas
  effectuer. Sans la seconde permission, le réglage est affiché en lecture seule et tout changement
  est refusé (403) avant qu'aucun autre paramètre ne soit écrit.
- Dans **les deux modes** restent obligatoires : permission, motif, contrôle d'organisation, refus
  métier, empreinte du récapitulatif et trace d'audit. Seul le code disparaît en `simple`.
- 2FA/TOTP : jamais requise, dans aucun mode.

### Parcours

Parcours (`Ventes/Show.vue` → `partials/AnnulationExceptionnelleDialog.vue`) :

1. Bouton **Annulation exceptionnelle** (visible avec `can_annuler_exceptionnel`).
2. Récapitulatif (commande, facture, encaissements et caisse concernée, commissions, cashback, stock
   à réintégrer, refus éventuels) + **motif obligatoire** (10 caractères minimum).
3. **Continuer vers la confirmation** :
   - `email_code` : le serveur vérifie que les données n'ont pas changé, génère un code à 6
     chiffres et l'envoie **à l'adresse e-mail de l'utilisateur authentifié** (jamais à une autre
     adresse). Objet : « Code de confirmation — Annulation exceptionnelle ». Saisie du code → le
     serveur vérifie le code et la correspondance motif/données ;
   - `simple` : écran de confirmation finale, sans code.
4. Le serveur revérifie tous les refus **sous verrou**, puis exécute.

Caractéristiques du code (`OtpService`, purpose `annulation_exceptionnelle`, contexte = commande) :

- valable 10 minutes, usage unique, invalidé par un nouveau code ;
- 5 tentatives au maximum, puis verrouillé ;
- 30 s entre deux envois, 5 par heure, 10 par jour ;
- conservé en cache **uniquement sous forme d'empreinte HMAC** (depuis le 24/09/2026, pour tous les
  usages de `OtpService`), jamais journalisé, jamais stocké dans `annulations_exceptionnelles`.

Le code prouve que **l'utilisateur authentifié** confirme lui-même l'opération (réauthentification).
Ce n'est **pas** une validation par une seconde personne : il n'existe pas de circuit
« demandeur + valideur ». La double authentification (2FA/TOTP) du compte est indépendante et n'est
pas requise.

**Lien entre ce qui est affiché et ce qui est validé** : le récapitulatif porte une empreinte
(sha256) des données présentées. À la demande de code, le serveur mémorise l'empreinte et le motif ;
à la confirmation, il exige les mêmes et recalcule l'empreinte sur les données réelles. Tout
changement entre-temps (nouvel encaissement, commission validée…) fait refuser l'opération.

## Permission

`ventes.annuler_exceptionnel` (`PermissionCatalog::STANDALONE`, domaine Ventes > Cycle de vente),
accordée au seul rôle `super_admin` par la migration
`2026_09_24_100100_backfill_ventes_annuler_exceptionnel_permission`. Une organisation peut la
déléguer à un autre rôle dans /backoffice/roles.

La même permission protège désormais la suppression unitaire d'un encaissement
(`DestroyEncaissementVenteController`), qui n'exigeait auparavant aucune permission.

Le contrôle d'organisation est **explicite** dans le service et les contrôleurs : le `Gate::before`
du super admin court-circuite les Policies.

## Trace `annulations_exceptionnelles`

Une ligne par commande : organisation, commande, facture, auteur, motif, statut avant annulation,
empreinte confirmée, méthode (`email_code` ou `simple`), adresse masquée et date de demande du code
(nulles en `simple`), date de confirmation,
montants (commande, facture, encaissé, commissions, cashback), instantané complet du récapitulatif et
liste des régularisations. Jamais le code.

## Limites connues

- En `email_code`, l'e-mail est envoyé de façon synchrone par le système d'e-mail de l'application.
  Si l'envoi échoue (ex : authentification SMTP refusée, quota atteint), le code est invalidé et un
  message d'erreur s'affiche : l'annulation reste impossible tant que l'e-mail ne part pas — ou
  jusqu'à ce qu'un utilisateur habilité passe l'organisation en `simple`.
- La permission `parametres-ventes.update` du catalogue n'est vérifiée nulle part : l'écran
  Paramètres → Ventes contrôle `parametres.read` / `parametres.update`, que ce réglage réutilise.
- Retour partiel, commission traitée, cashback validé et caisse déjà versée sont des refus « dans un
  premier temps » : les régulariser d'abord à la main.

## Fichiers clés

- `app/Services/AnnulationExceptionnelleService.php` — récapitulatif, refus, code, exécution.
- `app/Services/CommandeVenteService.php` — `annulerPourErreurSaisie()` / `appliquerAnnulation()`.
- `app/Http/Controllers/Ventes/{Show,DemanderCode,Confirmer}AnnulationExceptionnelleController.php`.
- `app/Mail/AnnulationExceptionnelleCodeMail.php`, `resources/views/emails/annulation-exceptionnelle-code.blade.php`.
- `app/Models/AnnulationExceptionnelle.php`, migration `2026_09_24_100000_create_annulations_exceptionnelles_table`.
- `resources/js/pages/Ventes/partials/AnnulationExceptionnelleDialog.vue`.
- `app/Enums/ModeConfirmationAnnulationExceptionnelle.php`, `Parametre::getModeConfirmationAnnulationExceptionnelle()`,
  `Settings/Ventes/{Edit,Update}VenteParametrageController.php`, `resources/js/pages/settings/Ventes.vue`.
- `tests/Feature/AnnulationExceptionnelleTest.php`, `tests/Feature/Settings/VenteParametrageTest.php`.
