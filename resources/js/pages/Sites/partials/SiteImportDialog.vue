<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { AlertTriangle, CheckCircle2, Download, Upload } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref, watch } from 'vue';

interface LigneResultat {
    numero_ligne: number;
    nom: string | null;
    statut: 'nouveau' | 'existant' | 'mise_a_jour' | 'erreur';
    erreurs: string[];
    normalisations: string[];
    avertissements: string[];
}

interface ReponseImport {
    nb_lignes_total: number;
    nb_nouveaux: number;
    nb_existants: number;
    nb_mises_a_jour: number;
    nb_erreurs: number;
    lignes: LigneResultat[];
    execute?: boolean;
    crees?: number;
    mis_a_jour?: number;
    existants_ignores?: number;
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
            '/backoffice/sites/import/analyser',
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
            '/backoffice/sites/import/confirmer',
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
        header="Importer des sites"
        :style="{ width: '720px' }"
        :breakpoints="{ '960px': '90vw' }"
    >
        <div class="space-y-5">
            <!-- Étape 1 : modèle -->
            <div class="rounded-lg border bg-muted/20 p-4 text-sm">
                <p class="text-muted-foreground">
                    Utilisez le modèle Excel pour préparer votre fichier —
                    colonnes obligatoires : <strong>nom</strong>,
                    <strong>type</strong>, <strong>ville_obligatoire</strong>,
                    <strong>quartier_obligatoire</strong>,
                    <strong>telephone_obligatoire</strong>.
                    <strong>site_parent_facultatif</strong> désigne le nom d'un
                    site existant ou d'une autre ligne de ce même fichier.
                    Renseignez <strong>code_facultatif</strong> avec le code
                    d'un site déjà existant pour le mettre à jour au lieu d'en
                    créer un nouveau lors d'un réimport.
                </p>
                <a
                    href="/backoffice/sites/import/modele"
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
                        Import terminé — {{ rapport.crees }} site{{
                            (rapport.crees ?? 0) > 1 ? 's' : ''
                        }}
                        créé{{ (rapport.crees ?? 0) > 1 ? 's' : '' }},
                        {{ rapport.mis_a_jour ?? 0 }} mis à jour,
                        {{ rapport.existants_ignores }} déjà existant{{
                            (rapport.existants_ignores ?? 0) > 1 ? 's' : ''
                        }}
                        (ignoré{{
                            (rapport.existants_ignores ?? 0) > 1 ? 's' : ''
                        }}).
                    </p>
                </div>

                <div v-else class="grid grid-cols-4 gap-3 text-center text-sm">
                    <div class="rounded-lg border bg-card p-3">
                        <p class="text-2xl font-semibold">
                            {{ rapport.nb_nouveaux }}
                        </p>
                        <p class="text-xs text-muted-foreground">à créer</p>
                    </div>
                    <div class="rounded-lg border bg-card p-3">
                        <p class="text-2xl font-semibold">
                            {{ rapport.nb_mises_a_jour ?? 0 }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            à mettre à jour
                        </p>
                    </div>
                    <div class="rounded-lg border bg-card p-3">
                        <p class="text-2xl font-semibold">
                            {{ rapport.nb_existants }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            déjà existants
                        </p>
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
                            site ne sera importé tant qu'elles ne sont pas
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
                                    v-if="l.nom"
                                    class="font-normal text-muted-foreground"
                                    >— {{ l.nom }}</span
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
                                <th class="px-3 py-2 font-medium">Nom</th>
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
                                    {{ l.nom }}
                                    <p
                                        v-for="(a, i) in l.avertissements"
                                        :key="i"
                                        class="mt-0.5 text-[11px] text-amber-600 dark:text-amber-500"
                                    >
                                        ⚠ {{ a }}
                                    </p>
                                </td>
                                <td class="px-3 py-1.5 align-top">
                                    <span
                                        v-if="l.statut === 'nouveau'"
                                        class="text-muted-foreground"
                                        >À créer</span
                                    >
                                    <span
                                        v-else-if="l.statut === 'mise_a_jour'"
                                        class="text-muted-foreground"
                                        >À mettre à jour</span
                                    >
                                    <span v-else class="text-muted-foreground"
                                        >Déjà existant (ignoré)</span
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
