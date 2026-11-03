# ADR 0051 — Prénom, nom et e-mail modifiables depuis l'espace client

- **Date** : 2026-11-02
- **Statut** : accepté (demande de l'utilisateur du 02/11/2026, confirmation de l'e-mail par lien
  validée le même jour)
- **Périmètre** : `PATCH /v1/mobile/profile`, écran Profil de l'espace client Nuxt — cf.
  [api-espace-client-contract.md](../api-espace-client-contract.md),
  [identite-client-personne.md](../identite-client-personne.md)

## Contexte

Depuis le 26/08/2026, le titulaire d'un compte ne pouvait modifier que sa localisation. Le prénom, le
nom et l'e-mail restaient réservés au back-office. Conséquence en production : un compte connu
seulement par son nom complet voit des champs vides et verrouillés, sans moyen de les renseigner.

Trois contraintes du code existant encadrent le changement :

- `AccountEligibility` refuse la connexion dès qu'une identité e-mail existe sans être vérifiée.
- L'identité e-mail reçoit les codes OTP et les liens de réinitialisation du mot de passe.
- `code_pays` et `code_phone_pays` recomposent le téléphone dans les formulaires du back-office :
  `code_pays = FR` sur un numéro `+224` deviendrait `+33…` au prochain enregistrement d'une fiche.

## Décision

1. **Prénom et nom** sont modifiables, sans pouvoir être vidés. Ils s'appliquent à chaque `Personne` du
   compte et aux colonnes de `Client`.
2. **`nom_complet` n'est pas réécrit** : ce libellé appartient au back-office et peut contenir une
   désignation provisoire (« Chauffeur-1 »). Les écrans du back-office qui l'affichent gardent donc
   l'ancien libellé tant qu'un agent ne le modifie pas.
3. **L'e-mail n'est appliqué qu'après ouverture d'un lien** envoyé à la nouvelle adresse. La demande
   n'écrit rien en base : l'adresse voyage dans un lien signé valable 24 h, lié à l'adresse actuelle
   et à sa date de vérification pour que tout changement ultérieur rende les anciens liens caducs,
   même après un retour à la même adresse. L'ouverture du lien écrit
   l'identité e-mail (vérifiée), les `Personne` et `Client`. L'e-mail ne peut pas être vidé.
4. **Le pays est un libellé choisi dans une liste fermée** publiée par l'API. `code_pays` et
   `code_phone_pays` ne sont plus acceptés par ce endpoint.
5. **Le téléphone reste non modifiable** par le titulaire.

Aucune règle de connexion, d'inscription ou d'OTP n'est modifiée.

## Options écartées pour l'e-mail

- **Enregistrer l'adresse comme non vérifiée** : bloquerait la prochaine connexion, y compris pour une
  simple faute de frappe.
- **Enregistrer l'adresse comme vérifiée sans preuve** : une adresse tierce recevrait les codes OTP et
  les liens de réinitialisation de ce compte.
- **Assouplir `AccountEligibility`** : modifie la connexion, hors périmètre par décision de
  l'utilisateur du 02/11/2026.

## Conséquences

- Un enregistrement de fiche au back-office remet `pays` sur le pays du téléphone, car ces formulaires
  le déduisent de `code_pays`.
- Le lien repose sur l'envoi d'e-mails : en cas d'échec, l'API le signale et l'écran l'affiche, sans
  perdre les autres modifications. Les envois sont limités à 5 par compte et par heure.
- Les apps mobiles n'appellent pas ce endpoint et ne sont pas concernées.
