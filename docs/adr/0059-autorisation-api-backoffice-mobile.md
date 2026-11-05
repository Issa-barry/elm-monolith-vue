# ADR 0059 — Autorisation de l'API back-office mobile et identité du livreur

- **Date** : 2026-11-05
- **Statut** : accepté (demande de l'utilisateur du 05/11/2026, lots de sécurité 0A et écritures livreur)
- **Périmètre** : routes `v1/backoffice/logistique/*`, `v1/backoffice/stats`, routes
  `v1/mobile/livraisons-transferts*` ; la règle du scan QR est décrite dans
  [le contrat de l'espace client](../api-espace-client-contract.md)

## Contexte

Le login de l'application Pro n'accepte que `admin_entreprise` et `super_admin`
(`BackofficeLoginController`), mais les routes `v1/backoffice` n'exigeaient qu'un jeton Sanctum. Or le
login standard en délivre un à tout compte actif (ADR 0055). Constaté par des tests le 05/11/2026 :

- un compte de l'organisation sans aucune permission validait une réception, y compris la validation
  administrateur qui déclenche par défaut la commission de transfert ;
- un compte sans organisation lisait les transferts de toutes les organisations
  (`if ($user->organization_id && …)` et `->when($user->organization_id, …)` sautaient le contrôle) ;
- la création acceptait les sites, véhicules et produits d'une autre organisation ;
- côté livreur, un compte portant le numéro de la fiche d'un autre compte écrivait à sa place, et un
  compte sans organisation agissait sur une fiche de n'importe quelle organisation.

## Décision

1. **Un middleware unique, déclaré route par route** : `backoffice.autoriser:<capacité>`
   (`AutoriserBackofficeMobile`). Il s'exécute avant les FormRequest et répond
   `403 {"message": "Accès non autorisé."}`, le format déjà lu par l'application Pro. Un test échoue si
   une route de ces groupes n'en déclare pas.
2. **Aucune règle nouvelle** : les capacités sont celles du back-office web.

   | Route | Capacité | Règle |
   |---|---|---|
   | `GET stats` | `factures.read` | Permission |
   | `GET logistique/ressources` | `logistique.create` | Permission : ne sert qu'au formulaire de création |
   | `GET logistique/transferts` | `logistique.read` | Liste limitée aux agences du compte (départ ou arrivée), sauf vision de toutes les agences |
   | `POST logistique/transferts` | `logistique.create` | Ressources de l'organisation ; départ libre seulement pour qui agit sur toutes les agences |
   | `GET logistique/transferts/{id}` | policy `view` | `logistique.read` et même organisation |
   | `PUT …/reception`, `POST …/valider-reception` | policy `validerReception` | `logistique.update` et site destination, ou action sur toutes les agences |
   | `POST …/validation-admin` | policy `deciderReceptionAdmin` | `super_admin` ou `admin_entreprise` |
   | `GET logistique/commissions`, `{id}` | `logistique.read` | Liste limitée comme les transferts |

3. **L'organisation est contrôlée dans le middleware, jamais déléguée à la policy** : `Gate::before`
   laisse passer le super administrateur sans lire la policy. Il reste limité à son organisation, et un
   compte sans organisation est toujours refusé, super administrateur compris.
4. **Identité du livreur** : `LivreurCompteResolver`, utilisé par les 5 routes
   `livraisons-transferts`. Le lien `user_id` prime. Le repli par téléphone réutilise
   `ClientIdentityResolver` : il ne retient qu'une fiche non réclamée de l'organisation du compte.
   Un compte sans organisation n'y a pas droit. Les deux lectures suivent la même règle que les trois
   écritures, sinon l'application afficherait des actions refusées au clic.

## Conséquences

- Parcours et formats de réponse inchangés pour les rôles admis par l'application Pro, sous réserve que
  leur rôle porte les permissions ci-dessus : la matrice n'est posée qu'à la création du rôle, à vérifier
  en production avant déploiement.
- Un super administrateur sans organisation ne voit plus rien sur ces routes.
- La policy `validerReception` vérifie `logistique.update`. La permission du catalogue
  `logistique.valider_reception` n'est lue nulle part : la cocher seule ne donne aucun droit, ni sur le
  web ni ici.
- **Risque résiduel accepté** : le repli par téléphone reste possible sur une fiche non réclamée de
  l'organisation, sans preuve de possession du numéro. Il sera traité par le chantier dédié à la preuve
  du numéro (décision du 04/11/2026).
- Les transferts déjà validés avant ce correctif ne sont pas réexaminés automatiquement : un contrôle
  ponctuel de `validated_by` en production reste à faire.
