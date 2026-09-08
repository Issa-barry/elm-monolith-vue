<script setup lang="ts">
// Garde-fou d'accès mobile — cf. docs/pwa.md § Garde-fou mobile. Remplace
// entièrement l'interface (connexion, back-office, espace client) par cet
// écran tant qu'un téléphone n'utilise pas ELM en PWA installée
// (`display: standalone`) : le navigateur mobile devient un simple point
// d'entrée vers l'installation, jamais l'interface métier elle-même.
//
// Monté à l'identique sur les 3 layouts partagés (AuthSimpleLayout,
// AppSidebarLayout, ClientLayout) en overlay plein écran (z-index maximal,
// au-dessus des dialogues PrimeVue) plutôt qu'en restructurant leur `slot` :
// le contenu du layout continue de se monter derrière, mais reste
// entièrement masqué et inatteignable tant que le garde-fou est actif.
//
// Tablette et desktop : jamais concernés (isPhoneDevice), comportement web
// normal inchangé.
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { usePwaInstall } from '@/composables/usePwaInstall';
import { Download, Share } from 'lucide-vue-next';

const { showGate, state, fallbackReady, promptInstall, continueInBrowser } =
    usePwaInstall();
</script>

<template>
    <div
        v-if="showGate"
        data-testid="pwa-gate"
        class="fixed inset-0 flex flex-col items-center justify-center gap-6 bg-background px-6 py-10 text-center"
        style="z-index: 2147483647"
    >
        <div
            class="flex size-16 items-center justify-center rounded-2xl bg-primary/10"
        >
            <AppLogoIcon class="size-8 fill-current text-primary" />
        </div>

        <div class="max-w-xs space-y-2">
            <h1 class="text-lg font-semibold">Installer ELM</h1>

            <template v-if="state === 'native_prompt'">
                <p class="text-sm text-muted-foreground">
                    Sur téléphone, ELM s'utilise comme une application
                    installée. L'installation est nécessaire pour continuer.
                </p>
            </template>

            <template v-else-if="state === 'ios_instructions'">
                <p class="text-sm text-muted-foreground">
                    Sur iPhone, ELM s'utilise comme une application installée.
                    Suivez ces étapes pour continuer :
                </p>
            </template>

            <template v-else>
                <p class="text-sm text-muted-foreground">
                    Votre navigateur ne permet pas l'installation
                    automatique. Ouvrez ce lien dans Chrome ou Safari, ou
                    utilisez le menu de votre navigateur pour ajouter ELM à
                    l'écran d'accueil.
                </p>
            </template>
        </div>

        <Button
            v-if="state === 'native_prompt'"
            size="lg"
            class="w-full max-w-xs"
            @click="promptInstall"
        >
            <Download class="size-4" />
            Installer ELM
        </Button>

        <ol
            v-else-if="state === 'ios_instructions'"
            class="flex w-full max-w-xs flex-col gap-3 rounded-xl border bg-card p-4 text-left text-sm"
        >
            <li class="flex items-center gap-3">
                <Share class="size-4 shrink-0 text-primary" aria-hidden="true" />
                <span
                    >Appuyez sur le bouton <strong>Partager</strong> de
                    Safari.</span
                >
            </li>
            <li class="flex items-center gap-3">
                <i
                    class="pi pi-plus-circle text-primary"
                    aria-hidden="true"
                />
                <span
                    >Choisissez <strong>« Sur l'écran d'accueil »</strong>.</span
                >
            </li>
            <li class="flex items-center gap-3">
                <i class="pi pi-check-circle text-primary" aria-hidden="true" />
                <span>Appuyez sur <strong>Ajouter</strong>.</span>
            </li>
        </ol>

        <p
            v-if="state === 'ios_instructions'"
            class="max-w-xs text-xs text-muted-foreground"
        >
            Ouvrez ensuite ELM depuis l'icône ajoutée à votre écran d'accueil.
        </p>

        <Button
            v-if="state === 'hidden' && fallbackReady"
            variant="ghost"
            size="sm"
            @click="continueInBrowser"
        >
            Continuer dans le navigateur
        </Button>
    </div>
</template>
