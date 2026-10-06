<script setup lang="ts">
// Proposition d'installation mobile — cf. docs/pwa.md § Proposition
// d'installation mobile. Carte flottante non bloquante : l'interface ELM reste
// utilisable dans le navigateur, la carte propose seulement d'installer la
// PWA (invite native Android/Chrome, ou instructions Safari sur iPhone) et
// peut être écartée (« Plus tard », pour la session).
//
// Montée à l'identique sur les 3 layouts partagés (AuthSimpleLayout,
// AppSidebarLayout, ClientLayout). Tablette et desktop : jamais affichée
// (isPhoneDevice).
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { usePwaInstall } from '@/composables/usePwaInstall';
import { Download, Share, X } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';

const { showPrompt, showIosSheet, promptInstall, dismiss } = usePwaInstall();
</script>

<template>
    <Transition
        enter-active-class="transition ease-out duration-200"
        enter-from-class="opacity-0 translate-y-4"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition ease-in duration-150"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 translate-y-4"
    >
        <div
            v-if="showPrompt"
            data-testid="pwa-install-prompt"
            class="fixed inset-x-0 bottom-0 z-50 flex justify-center px-4 pb-[max(1rem,env(safe-area-inset-bottom))]"
        >
            <div
                class="relative flex w-full max-w-sm items-start gap-3 rounded-xl border border-border bg-background p-4 shadow-lg"
            >
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10"
                >
                    <AppLogoIcon class="size-5 fill-current text-primary" />
                </div>
                <div class="flex-1 space-y-2 pr-5">
                    <div>
                        <p class="text-sm font-semibold">Installer ELM</p>
                        <p class="text-sm text-muted-foreground">
                            Ajoutez ELM à votre écran d'accueil pour l'ouvrir
                            comme une application.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button size="sm" @click="promptInstall">
                            <Download class="size-4" />
                            Installer
                        </Button>
                        <Button variant="ghost" size="sm" @click="dismiss">
                            Plus tard
                        </Button>
                    </div>
                </div>
                <button
                    type="button"
                    class="absolute top-2 right-2 rounded-md p-1 text-muted-foreground hover:text-foreground"
                    aria-label="Fermer"
                    @click="dismiss"
                >
                    <X class="size-4" />
                </button>
            </div>
        </div>
    </Transition>

    <Dialog
        v-model:visible="showIosSheet"
        modal
        header="Installer ELM"
        :style="{ width: 'min(94vw, 26rem)' }"
        :draggable="false"
    >
        <div class="flex flex-col gap-4">
            <ol class="flex flex-col gap-3 text-sm">
                <li class="flex items-center gap-3">
                    <Share
                        class="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
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
                        >Choisissez
                        <strong>« Sur l'écran d'accueil »</strong>.</span
                    >
                </li>
                <li class="flex items-center gap-3">
                    <i
                        class="pi pi-check-circle text-primary"
                        aria-hidden="true"
                    />
                    <span>Appuyez sur <strong>Ajouter</strong>.</span>
                </li>
            </ol>
            <Button class="w-full" @click="showIosSheet = false">
                Compris
            </Button>
        </div>
    </Dialog>
</template>
