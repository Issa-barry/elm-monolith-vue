import {
    isIosDevice,
    isPhoneDevice,
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
// layout (AppSidebarLayout, ClientLayout, AuthSimpleLayout) — jamais deux
// enregistrements du même listener global.
let deferredPrompt: BeforeInstallPromptEvent | null = null;
let listenerRegistered = false;

// « Plus tard » : persisté en sessionStorage pour ne pas reproposer la carte
// à chaque navigation Inertia (le composable est remonté sur chaque layout
// traversé), mais limité à cette session navigateur.
const DISMISS_KEY = 'elm-pwa-install-dismissed';

const isReady = ref(false);
const isStandalone = ref(false);
const isIos = ref(false);
const isPhone = ref(false);
const hasDeferredPrompt = ref(false);
const dismissed = ref(false);
const showIosSheet = ref(false);

const state = computed<PwaInstallState>(() => {
    if (!isReady.value) return 'hidden';
    return resolvePwaInstallState({
        isStandalone: isStandalone.value,
        isIos: isIos.value,
        hasDeferredPrompt: hasDeferredPrompt.value,
    });
});

// Proposition d'installation (docs/pwa.md § Proposition d'installation
// mobile) : jamais bloquante, seulement sur téléphone non standalone, et
// seulement quand un chemin d'installation réel existe — aucun bouton qui
// échouerait silencieusement sur un navigateur sans support.
const showPrompt = computed(
    () =>
        isReady.value &&
        isPhone.value &&
        state.value !== 'hidden' &&
        !dismissed.value,
);

function registerListeners(): void {
    if (listenerRegistered || typeof window === 'undefined') return;
    listenerRegistered = true;

    // preventDefault() : on garde la main pour déclencher l'invite au clic
    // sur NOTRE bouton plutôt que la mini-infobar par défaut de Chrome.
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event as BeforeInstallPromptEvent;
        hasDeferredPrompt.value = true;
    });

    // L'installation peut aussi survenir sans passer par notre bouton (menu
    // du navigateur) : la proposition doit disparaître dans ce cas aussi.
    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        hasDeferredPrompt.value = false;
        isStandalone.value = true;
    });
}

// Proposition d'installation PWA (téléphone uniquement, cf. docs/pwa.md) —
// logique pure dans config/pwaInstall.ts, ce composable ne fait que la
// relier aux vraies API navigateur/PWA.
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
        isPhone.value = isPhoneDevice(
            navigator.userAgent,
            navigator.maxTouchPoints || 0,
            window.innerWidth,
        );
        hasDeferredPrompt.value = deferredPrompt !== null;
        isReady.value = true;

        try {
            dismissed.value =
                window.sessionStorage.getItem(DISMISS_KEY) === '1';
        } catch {
            // Stockage indisponible (navigation privée stricte...) : la
            // carte reste simplement proposée, rien de bloquant.
        }

        registerListeners();
    });

    // Seule fonction qui déclenche réellement une action d'installation —
    // jamais automatique, uniquement depuis le clic explicite du bouton.
    // iOS n'a aucune invite native : le bouton ouvre les instructions Safari.
    async function promptInstall(): Promise<void> {
        if (state.value === 'ios_instructions') {
            showIosSheet.value = true;
            return;
        }
        if (!deferredPrompt) return;
        const prompt = deferredPrompt;
        // Une invite native ne peut être déclenchée qu'une fois : on la
        // "consomme" immédiatement pour ne pas retenter prompt() sur un
        // événement déjà utilisé.
        deferredPrompt = null;
        hasDeferredPrompt.value = false;
        await prompt.prompt();
        await prompt.userChoice;
    }

    function dismiss(): void {
        dismissed.value = true;
        try {
            window.sessionStorage.setItem(DISMISS_KEY, '1');
        } catch {
            // Stockage indisponible : masquée pour cette instance seulement.
        }
    }

    return {
        showPrompt,
        showIosSheet,
        state,
        promptInstall,
        dismiss,
    };
}
