<script setup lang="ts">
// Bandeau "Installer ELM" — reprend le principe UX de
// elm-vitrine-nuxt/components/PwaInstallButton.vue (bouton -> invite native
// Android/desktop, ou instructions manuelles iOS), adapté ici en bandeau
// dismissible plutôt qu'un CTA de landing, affiché sur les layouts partagés
// (auth, backoffice, espace client) : voir docs/pwa.md § UX installation.
//
// Priorité mobile/tablette (usePwaInstall::showBanner) : jamais affiché sur
// desktop dans cette V1, même si une invite native y serait techniquement
// disponible — un point d'entrée desktop séparé pourrait réutiliser `state`
// plus tard sans toucher cette logique.
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { usePwaInstall } from '@/composables/usePwaInstall';
import { Download } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';

const { showBanner, showIosSheet, promptInstall, closeIosSheet, dismiss } =
    usePwaInstall();
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
            v-if="showBanner"
            class="fixed inset-x-0 bottom-0 z-50 flex justify-center px-4 pb-4"
        >
            <div
                class="flex w-full max-w-sm items-start gap-3 rounded-xl border border-border bg-background p-4 shadow-lg"
            >
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10"
                >
                    <AppLogoIcon class="size-5 fill-current text-primary" />
                </div>
                <div class="flex-1 space-y-2">
                    <div>
                        <p class="text-sm font-semibold">Installer ELM</p>
                        <p class="text-sm text-muted-foreground">
                            Installez ELM sur votre appareil pour y accéder
                            comme une véritable application.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button size="sm" @click="promptInstall">
                            <Download class="size-4" />
                            Installer ELM
                        </Button>
                        <Button variant="ghost" size="sm" @click="dismiss">
                            Plus tard
                        </Button>
                    </div>
                </div>
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
                    <i class="pi pi-share-alt text-primary" aria-hidden="true" />
                    <span
                        >Appuyez sur le bouton
                        <strong>Partager</strong> de Safari.</span
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
            <Button class="w-full" @click="closeIosSheet">Compris</Button>
        </div>
    </Dialog>
</template>
