// Installation PWA (bandeau "Installer ELM", post-connexion) — même
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

export function isIosDevice(userAgent: string, maxTouchPoints: number): boolean {
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

// Priorité UX demandée : le bandeau d'installation ne s'affiche que sur
// téléphone/tablette, jamais sur desktop (ordinateur de bureau au clavier),
// même si une invite native y est techniquement disponible. Basé sur le même
// principe UA que isIosDevice, complété par le nombre de points tactiles et
// la largeur d'écran pour couvrir Android/tablettes génériques.
export function isMobileOrTabletDevice(
    userAgent: string,
    maxTouchPoints: number,
    viewportWidth: number,
): boolean {
    if (/Android|iPhone|iPad|iPod/i.test(userAgent)) return true;
    if (/Macintosh/i.test(userAgent) && maxTouchPoints > 1) return true;
    return maxTouchPoints > 1 && viewportWidth <= 1024;
}
