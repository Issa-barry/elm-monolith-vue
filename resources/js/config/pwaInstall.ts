// Garde-fou d'accès mobile PWA (cf. docs/pwa.md § Garde-fou mobile) — même
// principe que elm-vitrine-nuxt/config/pwaInstall.ts : logique pure ici
// (testable sans DOM), accès navigateur réels dans
// composables/usePwaInstall.ts.
//
// iOS (Safari/WebKit) ne déclenche jamais `beforeinstallprompt` : aucune
// tentative de reproduire artificiellement le comportement Android — un
// parcours manuel dédié (instructions "Partager -> Sur l'écran d'accueil")
// est proposé à la place, jamais un vrai déclenchement automatique.
export type PwaInstallState =
    | 'hidden' // déjà installée (standalone), ou aucun chemin d'installation détecté
    | 'ios_instructions' // iOS non standalone : bouton -> instructions Safari
    | 'native_prompt'; // beforeinstallprompt capté (Android/Chrome, desktop) : bouton -> invite native

export interface PwaInstallStateInput {
    isStandalone: boolean;
    isIos: boolean;
    hasDeferredPrompt: boolean;
}

// Priorité à l'invite native dès qu'elle est disponible ; iOS retombe sur les
// instructions manuelles ; tout le reste (navigateur qui ne propose ni l'un
// ni l'autre, ex. Firefox desktop) reste masqué plutôt que d'afficher un
// bouton qui échouerait silencieusement.
export function resolvePwaInstallState(
    input: PwaInstallStateInput,
): PwaInstallState {
    if (input.isStandalone) return 'hidden';
    if (input.hasDeferredPrompt) return 'native_prompt';
    if (input.isIos) return 'ios_instructions';
    return 'hidden';
}

export function isIosDevice(
    userAgent: string,
    maxTouchPoints: number,
): boolean {
    if (/iPad|iPhone|iPod/i.test(userAgent)) return true;
    // iPadOS 13+ : Safari annonce un user-agent "Macintosh" (UA desktop),
    // seul le nombre de points tactiles le distingue d'un vrai Mac.
    return /Macintosh/i.test(userAgent) && maxTouchPoints > 1;
}

export function isStandaloneDisplay(
    matchesStandaloneMedia: boolean,
    iosNavigatorStandalone: boolean | undefined,
): boolean {
    return matchesStandaloneMedia || iosNavigatorStandalone === true;
}

// Garde-fou d'accès (docs/pwa.md § Garde-fou mobile) : SEUL le téléphone est
// concerné — la tablette garde le comportement web normal, comme le desktop.
// Distinction volontairement plus stricte que l'ancien isMobileOrTabletDevice
// (mobile+tablette confondus, qui ne servait qu'à un bandeau suggestif non
// bloquant) : bloquer une tablette par erreur casserait un usage légitime,
// jamais acceptable pour un vrai garde-fou.
//
// iPad : toujours exclu, y compris le déguisement UA "Macintosh" d'iPadOS
// 13+ (seul le tactile le distingue d'un vrai Mac, même heuristique que
// isIosDevice mais inversée ici). Android : la convention UA place "Mobile"
// uniquement sur téléphone (absent sur tablette) — signal déjà utilisé par
// les sites pour adapter leur layout par défaut, réutilisé ici tel quel. UA
// inconnu (OS mobile atypique, navigateur exotique) : repli sur un seuil de
// largeur d'écran typique d'un téléphone plutôt qu'une tablette.
export function isPhoneDevice(
    userAgent: string,
    maxTouchPoints: number,
    viewportWidth: number,
): boolean {
    if (/iPhone|iPod/i.test(userAgent)) return true;
    if (/iPad/i.test(userAgent)) return false;
    if (/Macintosh/i.test(userAgent) && maxTouchPoints > 1) return false;
    if (/Android/i.test(userAgent)) return /Mobile/i.test(userAgent);
    return maxTouchPoints > 1 && viewportWidth <= 480;
}
