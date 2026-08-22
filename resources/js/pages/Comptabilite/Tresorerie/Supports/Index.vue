<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface CompteTresorerie {
    id: string;
    site: string | null;
    site_id: string;
    type: string;
    type_label: string;
    libelle: string;
    compte_comptable_id: string;
    compte_numero: string | null;
    moyen_paiement_defaut: string | null;
    actif: boolean;
    solde_ouverture: { id: string; montant: number; statut: string } | null;
}

const props = defineProps<{
    comptes: CompteTresorerie[];
    sites: { id: string; nom: string }[];
    type_options: { value: string; label: string }[];
    comptes_comptables: {
        id: string;
        numero: string;
        libelle: string;
        type_support: string | null;
    }[];
}>();

useFlashToast('top');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Supports de trésorerie', href: '#' },
];

const soldeStatutLabels: Record<string, string> = {
    brouillon: 'Brouillon',
    valide: 'Validé',
};

const form = useForm({
    site_id: '',
    compte_comptable_id: '',
    type: 'caisse',
    libelle: '',
    moyen_paiement_defaut: '',
});

// Un compte Mobile Money ne doit pas être sélectionnable pour un support
// Caisse (et inversement) — cf. revue du 2026-08-22. type_support est déduit
// côté serveur depuis compta_mappings ; un compte non classé (null) reste
// affiché pour ne pas bloquer un paramétrage encore incomplet.
function comptesCompatibles(type: string) {
    return props.comptes_comptables.filter(
        (c) => c.type_support === null || c.type_support === type,
    );
}

const comptesFiltres = computed(() => comptesCompatibles(form.type));

watch(
    () => form.type,
    () => {
        const compatibles = comptesFiltres.value;
        const selectionToujoursValide = compatibles.some(
            (c) => c.id === form.compte_comptable_id,
        );
        if (selectionToujoursValide) return;
        // Présélectionne le compte quand une seule option est compatible,
        // sinon laisse l'utilisateur choisir.
        form.compte_comptable_id =
            compatibles.length === 1 ? compatibles[0].id : '';
    },
    { immediate: true },
);

// Aperçu du libellé auto-généré si l'utilisateur ne saisit rien — la valeur
// réelle est calculée côté serveur (App\Models\CompteTresorerie::boot()),
// ceci n'est qu'un aperçu pour que l'utilisateur comprenne ce qui sera créé.
const libellePreview = computed(() => {
    const site = props.sites.find((s) => s.id === form.site_id);
    const type = props.type_options.find((t) => t.value === form.type);
    if (!site || !type) return 'Libellé (optionnel)';
    return `Automatique : ${type.label} de ${site.nom}`;
});

function creerSupport() {
    form.post('/backoffice/comptabilite/tresorerie/supports', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset('libelle', 'moyen_paiement_defaut'),
    });
}

const editForm = useForm({
    libelle: '',
    type: '',
    compte_comptable_id: '',
    moyen_paiement_defaut: '',
    actif: true,
});
const editDialogPour = ref<CompteTresorerie | null>(null);

// Une fois un solde d'ouverture saisi, le type et le compte comptable sont
// figés côté serveur (cf. CompteTresorerieController::update()) — les selects
// sont désactivés en cohérence, seul le libellé reste modifiable.
const editionTypeCompteVerrouillee = computed(
    () => !!editDialogPour.value?.solde_ouverture,
);

const comptesFiltresEdition = computed(() => comptesCompatibles(editForm.type));

watch(
    () => editForm.type,
    () => {
        if (!editDialogPour.value || editionTypeCompteVerrouillee.value) return;
        const compatibles = comptesFiltresEdition.value;
        const selectionToujoursValide = compatibles.some(
            (c) => c.id === editForm.compte_comptable_id,
        );
        if (selectionToujoursValide) return;
        editForm.compte_comptable_id =
            compatibles.length === 1 ? compatibles[0].id : '';
    },
);

function ouvrirEdition(compte: CompteTresorerie) {
    editForm.clearErrors();
    editForm.libelle = compte.libelle;
    editForm.type = compte.type;
    editForm.compte_comptable_id = compte.compte_comptable_id;
    editForm.moyen_paiement_defaut = compte.moyen_paiement_defaut ?? '';
    editForm.actif = compte.actif;
    editDialogPour.value = compte;
}

function enregistrerEdition() {
    if (!editDialogPour.value) return;
    editForm.put(
        `/backoffice/comptabilite/tresorerie/supports/${editDialogPour.value.id}`,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                editDialogPour.value = null;
            },
        },
    );
}

const soldeForm = useForm({
    compte_tresorerie_id: '',
    date_situation: new Date().toISOString().slice(0, 10),
    montant: '',
    commentaire: '',
});
const soldeDialogPourId = ref<string | null>(null);
const soldeMontantDisplay = ref('');

function handleSoldeMontantInput(e: Event) {
    const raw = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    soldeForm.montant = raw ? String(parseInt(raw, 10)) : '';
    soldeMontantDisplay.value = raw
        ? parseInt(raw, 10).toLocaleString('fr-FR', {
              maximumFractionDigits: 0,
          })
        : '';
}

function ouvrirSolde(id: string) {
    soldeDialogPourId.value = id;
    soldeForm.compte_tresorerie_id = id;
    soldeForm.montant = '';
    soldeMontantDisplay.value = '';
}

function enregistrerSolde() {
    soldeForm.post('/backoffice/comptabilite/tresorerie/soldes-ouverture', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            soldeDialogPourId.value = null;
        },
    });
}

// Protège contre un double clic pendant la requête (idempotent côté serveur,
// mais évite quand même deux visites Inertia superflues).
const validationEnCours = ref<string | null>(null);

function validerSolde(compte: CompteTresorerie) {
    if (!compte.solde_ouverture || validationEnCours.value) return;

    validationEnCours.value = compte.id;
    router.post(
        `/backoffice/comptabilite/tresorerie/soldes-ouverture/${compte.solde_ouverture.id}/valider`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                validationEnCours.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Supports de trésorerie" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">Supports de trésorerie</h1>
                <p class="text-sm text-muted-foreground">
                    Caisses, banques et comptes Mobile Money de chaque agence —
                    prérequis pour créer un mouvement de fonds ou un solde
                    d'ouverture.
                </p>
            </div>

            <form
                class="grid items-start gap-4 rounded-xl border bg-card p-4 sm:grid-cols-5"
                @submit.prevent="creerSupport"
            >
                <div class="space-y-1">
                    <label class="text-xs font-medium text-muted-foreground"
                        >Site <span class="text-destructive">*</span></label
                    >
                    <select
                        v-model="form.site_id"
                        class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                    >
                        <option value="" disabled>Site…</option>
                        <option v-for="s in sites" :key="s.id" :value="s.id">
                            {{ s.nom }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.site_id"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ form.errors.site_id }}
                    </p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-medium text-muted-foreground"
                        >Type <span class="text-destructive">*</span></label
                    >
                    <select
                        v-model="form.type"
                        class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                    >
                        <option
                            v-for="t in type_options"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.type"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ form.errors.type }}
                    </p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-medium text-muted-foreground"
                        >Compte comptable
                        <span class="text-destructive">*</span></label
                    >
                    <select
                        v-model="form.compte_comptable_id"
                        class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                    >
                        <option value="" disabled>Compte comptable…</option>
                        <option
                            v-for="c in comptesFiltres"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.numero }} — {{ c.libelle }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.compte_comptable_id"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ form.errors.compte_comptable_id }}
                    </p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-medium text-muted-foreground"
                        >Libellé (optionnel)</label
                    >
                    <input
                        v-model="form.libelle"
                        type="text"
                        :placeholder="libellePreview"
                        class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                    />
                    <p
                        v-if="form.errors.libelle"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ form.errors.libelle }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="h-9 self-end rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
                >
                    Ajouter
                </button>
            </form>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[760px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Site</th>
                            <th class="px-4 py-3 font-medium">Support</th>
                            <th class="px-4 py-3 font-medium">Compte</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Solde d'ouverture
                            </th>
                            <th class="px-4 py-3 text-left font-medium">
                                Statut
                            </th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="c in comptes" :key="c.id">
                            <td class="px-4 py-3">{{ c.site }}</td>
                            <td class="px-4 py-3">
                                {{ c.libelle }}
                                <span class="text-xs text-muted-foreground"
                                    >({{ c.type_label }})</span
                                >
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ c.compte_numero }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <template v-if="c.solde_ouverture">
                                    {{ formatGNF(c.solde_ouverture.montant) }}
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    v-if="c.solde_ouverture"
                                    :status="c.solde_ouverture.statut"
                                    :label="
                                        soldeStatutLabels[
                                            c.solde_ouverture.statut
                                        ] ?? c.solde_ouverture.statut
                                    "
                                />
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    class="text-xs font-medium text-muted-foreground hover:underline"
                                    @click="ouvrirEdition(c)"
                                >
                                    Modifier
                                </button>
                                <span
                                    v-if="
                                        !c.solde_ouverture ||
                                        c.solde_ouverture.statut === 'brouillon'
                                    "
                                    class="mx-1.5 text-muted-foreground"
                                    >·</span
                                >
                                <button
                                    v-if="!c.solde_ouverture"
                                    type="button"
                                    class="text-xs font-medium text-primary hover:underline"
                                    @click="ouvrirSolde(c.id)"
                                >
                                    Saisir le solde d'ouverture
                                </button>
                                <button
                                    v-else-if="
                                        c.solde_ouverture.statut === 'brouillon'
                                    "
                                    type="button"
                                    :disabled="validationEnCours === c.id"
                                    class="text-xs font-medium text-primary hover:underline disabled:opacity-50"
                                    @click="validerSolde(c)"
                                >
                                    Valider
                                </button>
                            </td>
                        </tr>
                        <tr v-if="comptes.length === 0">
                            <td
                                colspan="6"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucun support de trésorerie configuré.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="soldeDialogPourId"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            >
                <div
                    class="w-full max-w-sm space-y-4 rounded-xl border bg-card p-5"
                >
                    <h2 class="text-sm font-semibold">Solde d'ouverture</h2>
                    <div class="space-y-1.5">
                        <label class="text-sm">Date de situation</label>
                        <input
                            v-model="soldeForm.date_situation"
                            type="date"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        />
                        <p
                            v-if="soldeForm.errors.date_situation"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ soldeForm.errors.date_situation }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-sm">Montant (GNF)</label>
                        <input
                            :value="soldeMontantDisplay"
                            type="text"
                            inputmode="numeric"
                            placeholder="0"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm tabular-nums"
                            @input="handleSoldeMontantInput"
                        />
                        <p
                            v-if="soldeForm.errors.montant"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ soldeForm.errors.montant }}
                        </p>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="h-9 rounded-md border px-3 text-sm"
                            @click="soldeDialogPourId = null"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            :disabled="soldeForm.processing"
                            class="h-9 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
                            @click="enregistrerSolde"
                        >
                            Enregistrer
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="editDialogPour"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            >
                <div
                    class="w-full max-w-sm space-y-4 rounded-xl border bg-card p-5"
                >
                    <h2 class="text-sm font-semibold">Modifier le support</h2>
                    <div class="space-y-1.5">
                        <label class="text-sm">Libellé</label>
                        <input
                            v-model="editForm.libelle"
                            type="text"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        />
                        <p
                            v-if="editForm.errors.libelle"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ editForm.errors.libelle }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-sm">Type</label>
                        <select
                            v-model="editForm.type"
                            :disabled="editionTypeCompteVerrouillee"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm disabled:opacity-50"
                        >
                            <option
                                v-for="t in type_options"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.label }}
                            </option>
                        </select>
                        <p
                            v-if="editForm.errors.type"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ editForm.errors.type }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-sm">Compte comptable</label>
                        <select
                            v-model="editForm.compte_comptable_id"
                            :disabled="editionTypeCompteVerrouillee"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm disabled:opacity-50"
                        >
                            <option
                                v-for="c in comptesFiltresEdition"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{ c.numero }} — {{ c.libelle }}
                            </option>
                        </select>
                        <p
                            v-if="editForm.errors.compte_comptable_id"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ editForm.errors.compte_comptable_id }}
                        </p>
                        <p
                            v-if="editionTypeCompteVerrouillee"
                            class="text-xs text-muted-foreground"
                        >
                            Verrouillé : un solde d'ouverture existe déjà pour
                            ce support.
                        </p>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="editForm.actif"
                            type="checkbox"
                            class="h-4 w-4 rounded border-input"
                        />
                        Actif
                    </label>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="h-9 rounded-md border px-3 text-sm"
                            @click="editDialogPour = null"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            :disabled="editForm.processing"
                            class="h-9 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
                            @click="enregistrerEdition"
                        >
                            Enregistrer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
