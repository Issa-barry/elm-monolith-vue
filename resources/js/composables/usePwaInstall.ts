import {
    isIosDevice,
    isMobileOrTabletDevice,
    isStandaloneDisplay,
    resolvePwaInstallState,
    type PwaInstallState,
} from '@/config/pwaInstall';
import { computed, onMounted, ref } from 'vue';

// Pas de typage DOM standard pour cet événement (absent de WindowEventMap) :
// TypeScript retombe sur la surcharge générique addEventListener(type:
// string, ...), donc ce cast reste nécessaire, pas de lib.dom augmentée.
interface BeforeInstallPromptEvent extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{
        outcome: 'accepted' | 'dismissed';
        platform: string;
    }>;
}

// Référence brute à l'événement natif — jamais dans un ref exposé au template
// (non nécessaire) ni reconstruite entre deux appels. Singleton de module
// plutôt que par composant : `beforeinstallprompt` ne se déclenche qu'une
// fois par chargement de page, et ce composable est instancié une fois par
// layout (AppSidebarLayout, ClientLayout) — jamais deux enregistrements du
// même listener global.
let deferredPrompt: BeforeInstallPromptEvent | null = null;
let listenerRegistered = false;

// "Plus tard" : masqué pour le reste de la session navigateur (pas de
// re-proposition à chaque navigation Inertia, qui remonte ce composable sur
// chaque layout traversé), mais réaffiché à la prochaine vraie visite —
// jamais caché définitivement, l'installation reste facultative.
const DISMISS_KEY = 'elm-pwa-install-dismissed';

const isReady = ref(false);
const isStandalone = ref(false);
const isIos = ref(false);
const isMobileOrTablet = ref(false);
const hasDeferredPrompt = ref(false);
const isDismissed = ref(false);
const showIosSheet = ref(false);

const state = computed<PwaInstallState>(() => {
    if (!isReady.value) return 'hidden';
    return resolvePwaInstallState({
        isStandalone: isStandalone.value,
        isIos: isIos.value,
        hasDeferredPrompt: hasDeferredPrompt.value,
    });
});

// Bandeau proprement dit : en plus d'un chemin d'installation disponible
// (state), priorité UX mobile/tablette et respect du "Plus tard" de cette
// session. `state` reste utilisable seul si un point d'entrée desktop
// (bouton discret, non prioritaire) est ajouté plus tard.
const showBanner = computed(
    () =>
        state.value !== 'hidden' &&
        isMobileOrTablet.value &&
        !isDismissed.value,
);

function registerListeners(): void {
    if (listenerRegistered || typeof window === 'undefined') return;
    listenerRegistered = true;

    // preventDefault() : on garde la main pour déclencher l'invite au clic
    // sur NOTRE bandeau plutôt que la mini-infobar par défaut de Chrome.
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event as BeforeInstallPromptEvent;
        hasDeferredPrompt.value = true;
    });

    // L'installation peut aussi survenir sans passer par notre bandeau
    // (icône native de la barre d'adresse Chrome/Edge) : il doit disparaître
    // dans ce cas aussi, pas seulement après un clic dessus.
    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        hasDeferredPrompt.value = false;
        isStandalone.value = true;
    });
}

// Bandeau "Installer ELM" (post-connexion, mobile/tablette) — même
// convention que elm-vitrine-nuxt : logique pure dans config/pwaInstall.ts,
// ce composable ne fait que la relier aux vraies API navigateur/PWA.
export function usePwaInstall() {
    onMounted(() => {
        if (typeof window === 'undefined' || typeof navigator === 'undefined')
            return;

        const matchesStandaloneMedia =
            window.matchMedia?.('(display-mode: standalone)').matches ?? false;
        const iosNavigatorStandalone = (
            navigator as Navigator & { standalone?: boolean }
        ).standalone;
        isStandalone.value = isStandaloneDisplay(
            matchesStandaloneMedia,
            iosNavigatorStandalone,
        );
        isIos.value = isIosDevice(
            navigator.userAgent,
            navigator.maxTouchPoints || 0,
        );
        isMobileOrTablet.value = isMobileOrTabletDevice(
            navigator.userAgent,
            navigator.maxTouchPoints || 0,
            window.innerWidth,
        );
        hasDeferredPrompt.value = deferredPrompt !== null;
        isReady.value = true;

        try {
            isDismissed.value =
                window.sessionStorage.getItem(DISMISS_KEY) === '1';
        } catch {
            // Stockage indisponible (navigation privée stricte...) : le
            // bandeau reste simplement proposé à chaque visite, sans erreur.
        }

        registerListeners();
    });

    // Seule fonction qui déclenche réellement une action d'installation —
    // jamais automatique, uniquement depuis le clic explicite du bouton. Sur
    // iOS, ouvre les instructions manuelles (aucune invite native possible) ;
    // sinon déclenche l'invite native capturée par beforeinstallprompt.
    async function promptInstall(): Promise<void> {
        if (state.value === 'ios_instructions') {
            showIosSheet.value = true;
            return;
        }
        if (!deferredPrompt) return;
        const prompt = deferredPrompt;
        // Une invite native ne peut être déclenchée qu'une fois : on la
        // "consomme" immédiatement pour ne pas retenter prompt() sur un
        // événement déjà utilisé si le bandeau reste affiché un instant.
        deferredPrompt = null;
        hasDeferredPrompt.value = false;
        await prompt.prompt();
        await prompt.userChoice;
    }

    function closeIosSheet(): void {
        showIosSheet.value = false;
    }

    // "Plus tard" : ferme simplement le bandeau, n'empêche jamais
    // l'utilisation d'ELM. Persisté en sessionStorage pour ne pas le
    // reproposer à chaque navigation (le composable est remonté sur chaque
    // layout traversé), mais jamais caché de façon permanente.
    function dismiss(): void {
        isDismissed.value = true;
        try {
            window.sessionStorage.setItem(DISMISS_KEY, '1');
        } catch {
            // Stockage indisponible : le bandeau reste fermé pour cette
            // instance du composant, rien de plus grave.
        }
    }

    return {
        showBanner,
        state,
        showIosSheet,
        promptInstall,
        closeIosSheet,
        dismiss,
    };
}
