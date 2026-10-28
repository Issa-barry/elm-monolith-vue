<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Download, History, Upload } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    avec_salaire: boolean;
    peut_creer_contrat: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Employés', href: '/backoffice/employes' },
    {
        title: 'Mettre à jour les dossiers',
        href: '/backoffice/employes/imports/nouveau',
    },
];

const form = useForm<{ fichier: File | null }>({ fichier: null });
const fileInput = ref<HTMLInputElement | null>(null);
const fileName = ref<string | null>(null);

function onFileChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.fichier = file;
    fileName.value = file?.name ?? null;
}

function submit() {
    form.post('/backoffice/employes/imports', { forceFormData: true });
}
</script>

<template>
    <Head title="Employés - Mettre à jour les dossiers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl space-y-6 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-lg font-semibold">
                        Mettre à jour les dossiers et contrats
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Salariés déjà existants uniquement — l'import ne crée
                        jamais de salarié.
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link href="/backoffice/employes/imports">
                        <Button size="sm" variant="outline">
                            <History class="mr-1.5 h-4 w-4" />
                            Historique
                        </Button>
                    </Link>
                    <Link href="/backoffice/employes">
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
                    1. Exporter l'état actuel
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Une ligne par salarié de vos agences : matricule (clé), date
                    d'embauche, service, fonction, contrat<template
                        v-if="avec_salaire"
                        >, salaire de base</template
                    >. Les feuilles « references » et « mode_emploi » donnent
                    les valeurs acceptées et les règles.
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    Modifiez seulement les cellules à changer : une cellule vide
                    signifie « ne pas modifier ». Un contrat actif existant ne
                    se modifie jamais par l'import.
                </p>
                <p
                    v-if="!peut_creer_contrat"
                    class="mt-2 text-sm text-amber-700 dark:text-amber-400"
                >
                    Vous n'avez pas le droit de créer des contrats : les lignes
                    qui en créent seront refusées.
                </p>
                <a
                    href="/backoffice/employes/export-maj"
                    class="mt-4 inline-flex items-center gap-2 rounded-md border bg-background px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                >
                    <Download class="h-4 w-4" />
                    Exporter pour mise à jour
                </a>
            </div>

            <div class="rounded-xl border bg-card p-5 sm:p-6">
                <h2
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    2. Déposer le fichier modifié
                </h2>

                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div>
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".xlsx,.xls"
                            class="hidden"
                            data-testid="import-rh-fichier"
                            @change="onFileChange"
                        />
                        <button
                            type="button"
                            class="flex w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-8 text-center hover:bg-muted/40"
                            @click="fileInput?.click()"
                        >
                            <Upload class="h-8 w-8 text-muted-foreground/60" />
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
                        :aria-busy="form.processing"
                        class="w-full"
                    >
                        <Spinner v-if="form.processing" class="mr-1.5" />
                        {{
                            form.processing
                                ? 'Analyse en cours…'
                                : 'Analyser le fichier'
                        }}
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        L'analyse n'enregistre rien : un aperçu vous sera
                        présenté avant toute confirmation.
                    </p>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
