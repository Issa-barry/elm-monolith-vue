# Dashboard mobile — QR d'identité et scanner caméra (05/09/2026)

Amélioration ciblée du tableau de bord backoffice sur mobile : les 3 contrôles de
filtre (Télécharger, Envoyer rapport, sélecteur de période) restent réservés au
desktop (`sm:` et plus), remplacés sur mobile par le QR d'identité de l'utilisateur et
un bouton **Scanner**. Aucun calcul financier, filtre backend ni permission existante
n'a été modifié — uniquement `resources/js/components/dashboard/banking/HeaderWidget.vue`
et les ajouts décrits ci-dessous.

## 1. QR d'identité (remplace les initiales sur mobile)

- Backend : `App\Services\Client\QrPayloadResolver` — service partagé qui construit le
  `qr_payload` (URL de fiche backoffice `proprietaires.show`/`livreurs.show`) à partir
  d'une `ClientIdentity` déjà résolue par `ClientIdentityResolver`. Utilisé par les
  **trois** points d'exposition qui en avaient auparavant chacun une copie légèrement
  différente :
  - `MeController` (`GET /api/auth/me`, cf. `docs/api-espace-client-contract.md`) —
    `null` si aucun profil propriétaire/livreur réellement rattaché.
  - `ClientDashboardController::qrCode()` (espace client Inertia, image SVG) — replie
    sur `route('dashboard')` si aucun profil (un QR y est toujours affiché).
  - `DashboardController` (backoffice, nouveau) — expose `qr_payload` (`string|null`)
    comme prop Inertia dédiée à la page `Dashboard`, **pas** dans le payload `auth`
    partagé globalement (`HandleInertiaRequests`) : `ClientIdentityResolver` fait 3
    requêtes (Client/Proprietaire/Livreur) qu'il serait inutile de payer sur chaque
    page du backoffice alors que seul le dashboard en a besoin.
- **Format inchangé** : même URL absolue (`route()`) que l'espace client/l'API mobile —
  aucune nouvelle convention introduite.
- Un compte staff pur (aucun profil propriétaire/livreur cumulé, cas normal pour la
  plupart des utilisateurs backoffice) reçoit `qr_payload: null` → le frontend affiche
  un état neutre **« QR indisponible »**, jamais un QR fabriqué à partir d'autre chose
  (ex. jamais reconstruit depuis un numéro de téléphone non sécurisé).
- Frontend : `resources/js/components/identity/IdentityQrBadge.vue` — génère l'image
  via le package `qrcode` déjà utilisé par
  `resources/js/components/print/QrCodeTicket.vue` (pas de nouvelle dépendance). Cadre
  blanc, agrandissement dans une modale au clic (`aria-label="Agrandir mon QR code"`).
  Sur desktop, `HeaderWidget.vue` continue d'afficher les initiales — comportement
  desktop inchangé au pixel près.

## 2. Bouton Scanner (mobile uniquement)

`resources/js/components/scanner/ScannerModal.vue` — modale caméra basée sur
`@zxing/browser`/`@zxing/library` (dépendances déjà présentes, versions verrouillées
pour Node 22 — ne pas les mettre à jour sans revalidation explicite).

- Formats reconnus : `QR_CODE`, `EAN_13`, `EAN_8`, `UPC_A`, `UPC_E`, `CODE_128`
  (`DecodeHintType.POSSIBLE_FORMATS`, pour éviter que le moteur tente les formats non
  supportés par ce parcours).
- Caméra arrière préférée (`facingMode: { ideal: 'environment' }`), jamais activée
  avant l'ouverture explicite de la modale (`watch(visible)`), flux et lecteur arrêtés
  systématiquement à la fermeture de la modale **et** au démontage du composant
  (`stopCamera()` appelé par `@hide`, `watch` et `onBeforeUnmount`).
- États gérés explicitement : contexte non sécurisé (pas de HTTPS), caméra absente,
  autorisation refusée (`NotAllowedError`), échec de démarrage — messages dédiés,
  jamais une erreur générique.
- Résolution du résultat scanné : **réutilise exactement** la logique déjà utilisée par
  le scanner USB clavier du backoffice (`useScanInterceptor.ts`, actif sur tout le
  backoffice depuis avant ce chantier), extraite dans
  `resources/js/composables/scan/scanResolvers.ts` pour éviter une deuxième
  implémentation :
  - `QR_CODE` → `resolveQrText()` : URL interne complète (validée et **reconstruite sur
    l'origine du navigateur**, jamais l'hôte scanné — protection déjà présente dans le
    code existant, réutilisée telle quelle), sinon ULID nu (`GET /scan/user/{id}`),
    sinon référence livraison `VT-`/`TR-` (`GET /scan/livraison/{reference}`).
  - `EAN_13`/`EAN_8`/`UPC_A`/`UPC_E`/`CODE_128` → `resolveBarcodeText()` :
    `GET /scan/produit/{code}` (nouveau, cf. `docs/references-metier.md` §"Scan côté
    backoffice"), gardé par la permission `produits.read` et scopé à l'organisation de
    l'appelant.
  - Toute URL résolue par le backend est également reconstruite sur l'origine du
    navigateur avant navigation (`toCurrentOrigin`, dans `scanResolvers.ts`) — une URL
    absolue générée avec un `APP_URL` différent de l'hôte réellement affiché (migration
    de domaine, cf. mémoire projet) n'ouvre donc jamais un hôte externe.
  - Résultat non résolu : `« Code reconnu mais introuvable »` (structure reconnue,
    aucune correspondance backend) vs `« Code non reconnu »` (QR dont le contenu ne
    correspond à aucun des 3 cas ci-dessus) — deux messages distincts, toast PrimeVue
    (`useToast()`, remonte dans le `<Toast position="top-right" />` déjà monté par
    `AppSidebarLayout.vue`).
  - Résolution réussie → navigation Inertia (`router.visit`), jamais un rechargement
    complet ni une ouverture d'URL externe automatique.

## Hors périmètre (signalé, non corrigé par ce chantier)

- `ScanLivraisonController`/`ScanUserController` ne filtrent toujours pas par
  `organization_id` (gap déjà documenté le 31/08/2026, cf.
  `docs/references-metier.md`) — non corrigé ici, changement non demandé et sans
  rapport direct avec l'ajout du scanner caméra.
- `useScanInterceptor`'s `LIVRAISON_REF_RE` (`/^(VT|TR)-/i`) ne reconnaît pas les
  préfixes actuels `VTE-`/`DST-`/`TRF-` (seulement l'ancien `TR-`) — préexistant,
  inchangé par ce chantier (déplacé tel quel vers `scanResolvers.ts`).
