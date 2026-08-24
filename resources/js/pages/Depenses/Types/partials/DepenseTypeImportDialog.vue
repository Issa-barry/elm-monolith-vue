<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { AlertTriangle, CheckCircle2, Download, Upload } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref, watch } from 'vue';

interface LigneResultat {
    numero_ligne: number;
    libelle: string | null;
    statut: 'nouveau' | 'erreur';
    erreurs: string[];
    normalisations: string[];
    avertissements: string[];
}

interface ReponseImport {
    nb_lignes_total: number;
    nb_nouveaux: number;
    nb_erreurs: number;
    lignes: LigneResultat[];
    execute?: boolean;
    crees?: number;
}

const props = defineProps<{ visible: boolean }>();
const emit = defineEmits<{
    'update:visible': [boolean];
    imported: [];
}>();

const visible = computed({
    get: () => props.visible,
    set: (v: boolean) => emit('update:visible', v),
});

const fileInput = ref<HTMLInputElement | null>(null);
const file = ref<File | null>(null);
const analysing = ref(false);
const confirming = ref(false);
const rapport = ref<ReponseImport | null>(null);
const erreurGenerale = ref<string | null>(null);
const succesFinal = ref(false);

function reset() {
    file.value = null;
    rapport.value = null;
    erreurGenerale.value = null;
    succesFinal.value = false;
    if (fileInput.value) fileInput.value.value = '';
}

watch(
    () => props.visible,
    (v) => {
        if (!v) reset();
    },
);

function onFileChange(e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    file.value = f;
    rapport.value = null;
    erreurGenerale.value = null;
    succesFinal.value = false;
}

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

async function appelerImport(
    url: string,
    onNetworkError: string,
): Promise<ReponseImport | null> {
    if (!file.value) return null;

    const formData = new FormData();
    formData.append('fichier', file.value);

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: formData,
    });

    const json = await response.json().catch(() => null);

    if (!response.ok) {
        erreurGenerale.value =
            json?.errors?.fichier?.[0] ?? json?.message ?? onNetworkError;

        return null;
    }

    return json as ReponseImport;
}

async function analyser() {
    if (!file.value) return;
    analysing.value = true;
    erreurGenerale.value = null;

    try {
        const json = await appelerImport(
            '/backoffice/depenses/types/import/analyser',
            "Une erreur est survenue pendant l'analyse du fichier.",
        );
        if (json) rapport.value = json;
    } finally {
        analysing.value = false;
    }
}

async function confirmer() {
    if (!file.value) return;
    confirming.value = true;
    erreurGenerale.value = null;

    try {
        const json = await appelerImport(
            '/backoffice/depenses/types/import/confirmer',
            "Une erreur est survenue pendant l'import.",
        );
        if (json) {
            rapport.value = json;
            if (json.execute) {
                succesFinal.value = true;
                emit('imported');
            }
        }
    } finally {
        confirming.value = false;
    }
}

const lignesErreur = computed(
    () => rapport.value?.lignes.filter((l) => l.statut === 'erreur') ?? [],
);
const lignesValides = computed(
    () => rapport.value?.lignes.filter((l) => l.statut !== 'erreur') ?? [],
);
const peutConfirmer = computed(
    () =>
        rapport.value !== null &&
        rapport.value.nb_erreurs === 0 &&
        rapport.value.nb_lignes_total > 0 &&
        !succesFinal.value,
);

function fermer() {
    visible.value = false;
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        header="Importer des types de dépense"
        :style="{ width: '720px' }"
        :breakpoints="{ '960px': '90vw' }"
    >
        <div class="space-y-5">
            <!-- Étape 1 : modèle -->
            <div class="rounded-lg border bg-muted/20 p-4 text-sm">
                <p class="text-muted-foreground">
                    Utilisez le modèle Excel pour préparer votre fichier —
                    colonnes obligatoires : <strong>libelle</strong> et
                    <strong>concerne</strong> (véhicule, propriétaire, livreur,
                    salarié, interne ou prestataire).
                    <strong>description_facultatif</strong>,
                    <strong>commentaire_obligatoire</strong> et
                    <strong>justificatif_obligatoire</strong> (Oui/Non) et
                    <strong>statut</strong> (Actif/Inactif) sont optionnelles.
                    Un libellé déjà utilisé dans votre organisation est toujours
                    refusé — aucun type existant n'est jamais modifié par un
                    import.
                </p>
                <a
                    href="/backoffice/depenses/types/import/modele"
                    class="mt-3 inline-flex items-center gap-2 rounded-md border bg-background px-3 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-muted"
                >
                    <Download class="h-3.5 w-3.5" />
                    Télécharger le modèle
                </a>
            </div>

            <!-- Étape 2 : fichier -->
            <div v-if="!succesFinal">
                <input
                    ref="fileInput"
                    type="file"
                    accept=".xlsx,.xls"
                    class="hidden"
                    @change="onFileChange"
                />
                <button
                    type="button"
                    class="flex w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-6 text-center hover:bg-muted/40"
                    @click="fileInput?.click()"
                >
                    <Upload class="h-6 w-6 text-muted-foreground/60" />
                    <span class="text-sm font-medium">{{
                        file?.name ?? 'Cliquez pour choisir un fichier'
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >.xlsx ou .xls — 5 Mo max</span
                    >
                </button>

                <p v-if="erreurGenerale" class="mt-2 text-xs text-destructive">
                    {{ erreurGenerale }}
                </p>

                <Button
                    class="mt-3 w-full"
                    :disabled="!file || analysing"
                    @click="analyser"
                >
                    <Spinner v-if="analysing" class="mr-2" />
                    {{
                        analysing ? 'Analyse en cours…' : 'Analyser le fichier'
                    }}
                </Button>
            </div>

            <!-- Résultat de l'analyse / confirmation -->
            <template v-if="rapport">
                <div
                    v-if="succesFinal"
                    class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20"
                >
                    <CheckCircle2 class="h-5 w-5 shrink-0 text-emerald-600" />
                    <p
                        class="text-sm font-medium text-emerald-700 dark:text-emerald-400"
                    >
                        Import terminé — {{ rapport.crees }} type{{
                            (rapport.crees ?? 0) > 1 ? 's' : ''
                        }}
                        créé{{ (rapport.crees ?? 0) > 1 ? 's' : '' }}.
                    </p>
                </div>

                <div v-else class="grid grid-cols-2 gap-3 text-center text-sm">
                    <div class="rounded-lg border bg-card p-3">
                        <p class="text-2xl font-semibold">
                            {{ rapport.nb_nouveaux }}
                        </p>
                        <p class="text-xs text-muted-foreground">à créer</p>
                    </div>
                    <div class="rounded-lg border bg-card p-3">
                        <p
                            class="text-2xl font-semibold"
                            :class="{
                                'text-destructive': rapport.nb_erreurs > 0,
                            }"
                        >
                            {{ rapport.nb_erreurs }}
                        </p>
                        <p class="text-xs text-muted-foreground">en erreur</p>
                    </div>
                </div>

                <div
                    v-if="lignesErreur.length"
                    class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/40 dark:bg-red-950/20"
                >
                    <div class="flex items-center gap-2">
                        <AlertTriangle class="h-4 w-4 text-red-600" />
                        <h3
                            class="text-sm font-semibold text-red-700 dark:text-red-400"
                        >
                            {{ lignesErreur.length }} ligne(s) en erreur — aucun
                            type ne sera importé tant qu'elles ne sont pas
                            corrigées
                        </h3>
                    </div>
                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="l in lignesErreur"
                            :key="l.numero_ligne"
                            class="rounded-md border border-red-200 bg-background p-2.5 text-xs dark:border-red-900/40"
                        >
                            <p class="font-medium">
                                Ligne {{ l.numero_ligne }}
                                <span
                                    v-if="l.libelle"
                                    class="font-normal text-muted-foreground"
                                    >— {{ l.libelle }}</span
                                >
                            </p>
                            <ul
                                class="mt-1 list-inside list-disc text-red-700 dark:text-red-400"
                            >
                                <li v-for="(e, i) in l.erreurs" :key="i">
                                    {{ e }}
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div
                    v-if="!succesFinal && lignesValides.length"
                    class="max-h-56 overflow-y-auto rounded-lg border"
                >
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-muted/60">
                            <tr class="text-left text-muted-foreground">
                                <th class="px-3 py-2 font-medium">Ligne</th>
                                <th class="px-3 py-2 font-medium">Libellé</th>
                                <th class="px-3 py-2 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="l in lignesValides"
                                :key="l.numero_ligne"
                            >
                                <td
                                    class="px-3 py-1.5 align-top text-muted-foreground"
                                >
                                    {{ l.numero_ligne }}
                                </td>
                                <td class="px-3 py-1.5 align-top">
                                    {{ l.libelle }}
                                    <p
                                        v-for="(a, i) in l.avertissements"
                                        :key="i"
                                        class="mt-0.5 text-[11px] text-amber-600 dark:text-amber-500"
                                    >
                                        ⚠ {{ a }}
                                    </p>
                                </td>
                                <td class="px-3 py-1.5 align-top">
                                    <span class="text-muted-foreground"
                                        >À créer</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Button
                    v-if="!succesFinal"
                    class="w-full"
                    :disabled="!peutConfirmer || confirming"
                    @click="confirmer"
                >
                    <Spinner v-if="confirming" class="mr-2" />
                    {{ confirming ? 'Import en cours…' : "Confirmer l'import" }}
                </Button>
            </template>
        </div>

        <template #footer>
            <Button variant="outline" @click="fermer">
                {{ succesFinal ? 'Fermer' : 'Annuler' }}
            </Button>
        </template>
    </Dialog>
</template>
