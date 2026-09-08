import {
    isIosDevice,
    isPhoneDevice,
    isStandaloneDisplay,
    resolvePwaInstallState,
    type PwaInstallState,
} from '@/config/pwaInstall';
import { computed, onMounted, onUnmounted, ref } from 'vue';

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
// layout (AppSidebarLayout, ClientLayout, AuthSimpleLayout) — jamais deux
// enregistrements du même listener global.
let deferredPrompt: BeforeInstallPromptEvent | null = null;
let listenerRegistered = false;

// Contournement du garde-fou : réservé au seul cas où AUCUN chemin
// d'installation n'est détecté (state === 'hidden' alors qu'on est sur
// téléphone, non standalone — navigateur qui ne supporte ni
// beforeinstallprompt ni les instructions iOS, ex. Firefox Android, webview
// in-app WhatsApp/Facebook). Bloquer sans issue dans ce cas serait un
// verrouillage total sans solution pour l'utilisateur — jamais acceptable.
// Jamais utilisable pour 'native_prompt'/'ios_instructions' : ces deux
// chemins sont réellement actionnables, aucun contournement n'y est permis.
const UNSUPPORTED_BYPASS_KEY = 'elm-pwa-gate-unsupported-bypass';

// Délai avant de proposer le contournement "navigateur non supporté" :
// `beforeinstallprompt` peut se déclencher avec un léger retard après le
// montage (Chrome évalue l'installabilité de façon asynchrone) — laisser
// cette fenêtre avant d'offrir l'échappatoire évite de la proposer à tort à
// un téléphone Android qui aurait pu installer normalement.
const UNSUPPORTED_FALLBACK_DELAY_MS = 1200;

const isReady = ref(false);
const isStandalone = ref(false);
const isIos = ref(false);
const isPhone = ref(false);
const hasDeferredPrompt = ref(false);
const unsupportedBypassed = ref(false);
const fallbackReady = ref(false);

const state = computed<PwaInstallState>(() => {
    if (!isReady.value) return 'hidden';
    return resolvePwaInstallState({
        isStandalone: isStandalone.value,
        isIos: isIos.value,
        hasDeferredPrompt: hasDeferredPrompt.value,
    });
});

// Garde-fou d'accès (docs/pwa.md § Garde-fou mobile) : sur téléphone non
// standalone, l'interface ELM normale ne doit jamais s'afficher — seule
// cette page d'installation est visible, remplaçant entièrement le
// back-office/l'espace client/la page de connexion, jamais un simple
// bandeau superposé. Unique échappatoire : navigateur sans aucun chemin
// d'installation détecté (cf. UNSUPPORTED_BYPASS_KEY ci-dessus).
const showGate = computed(
    () =>
        isReady.value &&
        isPhone.value &&
        !isStandalone.value &&
        !(state.value === 'hidden' && unsupportedBypassed.value),
);

function registerListeners(): void {
    if (listenerRegistered || typeof window === 'undefined') return;
    listenerRegistered = true;

    // preventDefault() : on garde la main pour déclencher l'invite au clic
    // sur NOTRE écran plutôt que la mini-infobar par défaut de Chrome.
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event as BeforeInstallPromptEvent;
        hasDeferredPrompt.value = true;
    });

    // L'installation peut aussi survenir sans passer par notre écran (icône
    // native de la barre d'adresse Chrome/Edge) : le garde-fou doit se lever
    // dans ce cas aussi, pas seulement après un clic sur notre bouton.
    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        hasDeferredPrompt.value = false;
        isStandalone.value = true;
    });
}

// Garde-fou d'installation PWA (téléphone uniquement, cf. docs/pwa.md) —
// logique pure dans config/pwaInstall.ts, ce composable ne fait que la
// relier aux vraies API navigateur/PWA.
export function usePwaInstall() {
    let fallbackTimer: ReturnType<typeof setTimeout> | undefined;

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
        isPhone.value = isPhoneDevice(
            navigator.userAgent,
            navigator.maxTouchPoints || 0,
            window.innerWidth,
        );
        hasDeferredPrompt.value = deferredPrompt !== null;
        isReady.value = true;

        try {
            unsupportedBypassed.value =
                window.sessionStorage.getItem(UNSUPPORTED_BYPASS_KEY) === '1';
        } catch {
            // Stockage indisponible (navigation privée stricte...) : le
            // contournement reste simplement indisponible, jamais bloquant
            // en plus (voir continueInBrowser ci-dessous).
        }

        fallbackTimer = setTimeout(() => {
            fallbackReady.value = true;
        }, UNSUPPORTED_FALLBACK_DELAY_MS);

        registerListeners();
    });

    onUnmounted(() => {
        if (fallbackTimer) clearTimeout(fallbackTimer);
    });

    // Seule fonction qui déclenche réellement une action d'installation —
    // jamais automatique, uniquement depuis le clic explicite du bouton.
    // Sur iOS, aucune invite native n'existe : les instructions manuelles
    // sont déjà affichées directement dans l'écran du garde-fou.
    async function promptInstall(): Promise<void> {
        if (!deferredPrompt) return;
        const prompt = deferredPrompt;
        // Une invite native ne peut être déclenchée qu'une fois : on la
        // "consomme" immédiatement pour ne pas retenter prompt() sur un
        // événement déjà utilisé si l'écran reste affiché un instant.
        deferredPrompt = null;
        hasDeferredPrompt.value = false;
        await prompt.prompt();
        await prompt.userChoice;
    }

    // Réservé au cas "navigateur non supporté" (voir UNSUPPORTED_BYPASS_KEY) :
    // jamais appelable/affiché quand un chemin d'installation réel existe.
    // Persisté en sessionStorage comme l'ancien "Plus tard", pour ne pas le
    // reproposer à chaque navigation Inertia (le composable est remonté sur
    // chaque layout traversé) mais rester limité à cette session navigateur.
    function continueInBrowser(): void {
        unsupportedBypassed.value = true;
        try {
            window.sessionStorage.setItem(UNSUPPORTED_BYPASS_KEY, '1');
        } catch {
            // Stockage indisponible : le contournement reste actif pour
            // cette instance du composant, rien de plus grave.
        }
    }

    return {
        showGate,
        state,
        fallbackReady,
        promptInstall,
        continueInBrowser,
    };
}
