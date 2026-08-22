<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface CompteTresorerie {
    id: string;
    site: string | null;
    site_id: string;
    type: string;
    type_label: string;
    libelle: string;
    compte_numero: string | null;
    moyen_paiement_defaut: string | null;
    actif: boolean;
    solde_ouverture: { montant: number; statut: string } | null;
}

defineProps<{
    comptes: CompteTresorerie[];
    sites: { id: string; nom: string }[];
    type_options: { value: string; label: string }[];
    comptes_comptables: { id: string; numero: string; libelle: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Supports de trésorerie', href: '#' },
];

const form = useForm({
    site_id: '',
    compte_comptable_id: '',
    type: 'caisse',
    libelle: '',
    moyen_paiement_defaut: '',
});

function creerSupport() {
    form.post('/backoffice/comptabilite/tresorerie/supports', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset('libelle', 'moyen_paiement_defaut'),
    });
}

const soldeForm = useForm({
    compte_tresorerie_id: '',
    date_situation: new Date().toISOString().slice(0, 10),
    montant: '',
    commentaire: '',
});
const soldeDialogPourId = ref<string | null>(null);

function ouvrirSolde(id: string) {
    soldeDialogPourId.value = id;
    soldeForm.compte_tresorerie_id = id;
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

function validerSolde(compte: CompteTresorerie) {
    if (!compte.solde_ouverture) return;
    router.post(
        `/backoffice/comptabilite/tresorerie/soldes-ouverture/${compte.id}/valider`,
        {},
        { preserveScroll: true },
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
                    <select
                        v-model="form.compte_comptable_id"
                        class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                    >
                        <option value="" disabled>Compte comptable…</option>
                        <option
                            v-for="c in comptes_comptables"
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
                    <input
                        v-model="form.libelle"
                        type="text"
                        placeholder="Libellé (ex: Caisse Matoto)"
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
                    class="h-9 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground"
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
                                    <span class="text-xs text-muted-foreground"
                                        >({{ c.solde_ouverture.statut }})</span
                                    >
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
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
                                    class="text-xs font-medium text-primary hover:underline"
                                    @click="validerSolde(c)"
                                >
                                    Valider
                                </button>
                            </td>
                        </tr>
                        <tr v-if="comptes.length === 0">
                            <td
                                colspan="5"
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
                            v-model="soldeForm.montant"
                            type="number"
                            min="0"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
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
                            class="h-9 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground"
                            @click="enregistrerSolde"
                        >
                            Enregistrer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
