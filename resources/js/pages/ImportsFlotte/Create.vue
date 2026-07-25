<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Download, History, Upload } from 'lucide-vue-next';
import { ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/parametres' },
    { title: 'Import flotte', href: '/settings/imports-flotte/nouveau' },
];

const form = useForm<{ fichier: File | null }>({
    fichier: null,
});

const fileInput = ref<HTMLInputElement | null>(null);
const fileName = ref<string | null>(null);

function onFileChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.fichier = file;
    fileName.value = file?.name ?? null;
}

function submit() {
    form.post('/settings/imports-flotte', {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="Import flotte - Nouvel import" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl space-y-6 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-lg font-semibold">
                        Import Propriétaires, véhicules et livreurs
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Créez plusieurs propriétaires, véhicules et équipes de
                        livraison en une fois à partir d'un fichier Excel.
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link href="/settings/imports-flotte">
                        <Button size="sm" variant="outline">
                            <History class="mr-1.5 h-4 w-4" />
                            Historique
                        </Button>
                    </Link>
                    <Link href="/settings/parametres">
                        <Button size="sm" variant="outline">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                </div>
            </div>

            <div class="rounded-xl border bg-card p-5 sm:p-6">
                <h2
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    1. Télécharger le modèle
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Le modèle contient deux feuilles : <strong>vehicules</strong>
                    (une ligne par véhicule + son propriétaire) et
                    <strong>livreurs</strong> (une ligne par livreur, reliée au
                    véhicule par l'immatriculation). Pas besoin de répéter les
                    infos du véhicule pour chaque livreur — seule
                    l'immatriculation est à reporter sur chaque ligne de la
                    feuille livreurs. La commission et la répartition par
                    livreur se configurent après coup dans Équipes de
                    livraison.
                </p>
                <a
                    href="/settings/imports-flotte/modele"
                    class="mt-4 inline-flex items-center gap-2 rounded-md border bg-background px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                >
                    <Download class="h-4 w-4" />
                    Télécharger le modèle Excel
                </a>
            </div>

            <div class="rounded-xl border bg-card p-5 sm:p-6">
                <h2
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    2. Déposer le fichier rempli
                </h2>

                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div>
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".xlsx,.xls"
                            class="hidden"
                            @change="onFileChange"
                        />
                        <button
                            type="button"
                            class="flex w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-8 text-center hover:bg-muted/40"
                            @click="fileInput?.click()"
                        >
                            <Upload
                                class="h-8 w-8 text-muted-foreground/60"
                            />
                            <span class="text-sm font-medium">{{
                                fileName ?? 'Cliquez pour choisir un fichier'
                            }}</span>
                            <span class="text-xs text-muted-foreground"
                                >.xlsx ou .xls — 5 Mo max</span
                            >
                        </button>
                        <p
                            v-if="form.errors.fichier"
                            class="mt-2 text-xs text-destructive"
                        >
                            {{ form.errors.fichier }}
                        </p>
                    </div>

                    <Button
                        type="submit"
                        :disabled="!form.fichier || form.processing"
                        class="w-full"
                    >
                        Analyser le fichier
                    </Button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
