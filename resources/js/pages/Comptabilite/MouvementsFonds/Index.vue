<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRightLeft } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Mouvement {
    id: string;
    reference: string;
    site_origine: string | null;
    site_destination: string | null;
    compte_origine: string | null;
    compte_destination: string | null;
    montant: number;
    statut: string;
    statut_label: string;
    date_envoi: string | null;
    date_reception: string | null;
    created_at: string;
    peut_envoyer: boolean;
    peut_recevoir: boolean;
    peut_annuler: boolean;
    peut_contester: boolean;
    peut_confirmer_retour: boolean;
}

const props = defineProps<{
    mouvements: { data: Mouvement[]; total: number };
    filters: { statut: string; search: string; site_ids: string[] };
    statut_options: { value: string; label: string }[];
    sites: { value: string; label: string }[];
    is_admin: boolean;
    peut_creer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Mouvements de fonds', href: '#' },
];

const filterFields: FilterField[] = [
    {
        key: 'search',
        label: 'Référence',
        type: 'text',
        inline: true,
        placeholder: 'MVT-2026-00001',
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        inline: true,
        options: props.statut_options,
    },
];

// DataFilters attend { id, nom } (convention Site), pas { value, label }.
const sitesPourFiltre = computed(() =>
    props.sites.map((s) => ({ id: s.value, nom: s.label })),
);

function envoyer(m: Mouvement) {
    router.post(
        `/backoffice/comptabilite/tresorerie/mouvements/${m.id}/envoyer`,
        {},
        { preserveScroll: true },
    );
}

function recevoir(m: Mouvement) {
    router.post(
        `/backoffice/comptabilite/tresorerie/mouvements/${m.id}/recevoir`,
        {},
        { preserveScroll: true },
    );
}

// ── Dialog motif (annuler / contester / confirmer-retour) — un seul dialog réutilisé ──

type ActionMotif = 'annuler' | 'contester' | 'confirmer-retour';

const motifDialogOpen = ref(false);
const motifDialogAction = ref<ActionMotif>('annuler');
const motifCible = ref<Mouvement | null>(null);
const motif = ref('');
const motifError = ref('');

const motifDialogTitres: Record<ActionMotif, string> = {
    annuler: 'Annuler le mouvement',
    contester: 'Contester le mouvement',
    'confirmer-retour': 'Confirmer le retour des fonds',
};

function ouvrirDialogMotif(action: ActionMotif, m: Mouvement) {
    motifDialogAction.value = action;
    motifCible.value = m;
    motif.value = '';
    motifError.value = '';
    motifDialogOpen.value = true;
}

function confirmerMotif() {
    if (!motif.value.trim()) {
        motifError.value = 'Le motif est obligatoire.';
        return;
    }
    if (!motifCible.value) return;

    router.post(
        `/backoffice/comptabilite/tresorerie/mouvements/${motifCible.value.id}/${motifDialogAction.value}`,
        { motif: motif.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                motifDialogOpen.value = false;
            },
            onError: (errors) => {
                motifError.value = errors.motif ?? 'Une erreur est survenue.';
            },
        },
    );
}
</script>

<template>
    <Head title="Mouvements de fonds" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        <ArrowRightLeft class="h-5 w-5 text-muted-foreground" />
                        Mouvements de fonds
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Remises des agences au siège et financements envoyés par
                        le siège.
                    </p>
                </div>
                <Link
                    v-if="peut_creer"
                    href="/backoffice/comptabilite/tresorerie/mouvements/create"
                    class="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    Nouveau mouvement
                </Link>
            </div>

            <DataFilters
                url="/backoffice/comptabilite/tresorerie/mouvements"
                :values="filters"
                :fields="filterFields"
                :sites="sitesPourFiltre"
                :result-count="mouvements.total"
            />

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[880px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Référence</th>
                            <th class="px-4 py-3 font-medium">Origine</th>
                            <th class="px-4 py-3 font-medium">Destination</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Montant
                            </th>
                            <th class="px-4 py-3 text-left font-medium">
                                Statut
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="m in mouvements.data"
                            :key="m.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ m.reference }}
                            </td>
                            <td class="px-4 py-3">
                                {{ m.site_origine ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ m.site_destination ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(m.montant) }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="m.statut"
                                    :label="m.statut_label"
                                />
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex justify-end gap-3">
                                    <button
                                        v-if="m.peut_envoyer"
                                        type="button"
                                        class="text-xs font-medium text-primary hover:underline"
                                        @click="envoyer(m)"
                                    >
                                        Envoyer
                                    </button>
                                    <button
                                        v-if="m.peut_recevoir"
                                        type="button"
                                        class="text-xs font-medium text-primary hover:underline"
                                        @click="recevoir(m)"
                                    >
                                        Confirmer réception
                                    </button>
                                    <button
                                        v-if="m.peut_annuler"
                                        type="button"
                                        class="text-xs font-medium text-muted-foreground hover:underline"
                                        @click="ouvrirDialogMotif('annuler', m)"
                                    >
                                        Annuler
                                    </button>
                                    <button
                                        v-if="m.peut_contester"
                                        type="button"
                                        class="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                                        @click="
                                            ouvrirDialogMotif('contester', m)
                                        "
                                    >
                                        Contester
                                    </button>
                                    <button
                                        v-if="m.peut_confirmer_retour"
                                        type="button"
                                        class="text-xs font-medium text-primary hover:underline"
                                        @click="
                                            ouvrirDialogMotif(
                                                'confirmer-retour',
                                                m,
                                            )
                                        "
                                    >
                                        Confirmer le retour
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="mouvements.data.length === 0">
                            <td
                                colspan="6"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucun mouvement de fonds.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="motifDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{ motifDialogTitres[motifDialogAction] }}
                        {{ motifCible?.reference }}
                    </DialogTitle>
                </DialogHeader>
                <div class="space-y-1.5">
                    <Label for="motif">Motif</Label>
                    <textarea
                        id="motif"
                        v-model="motif"
                        rows="3"
                        placeholder="Expliquez la raison…"
                        class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <p
                        v-if="motifError"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ motifError }}
                    </p>
                </div>
                <DialogFooter>
                    <button
                        type="button"
                        class="h-9 rounded-md border px-4 text-sm"
                        @click="motifDialogOpen = false"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground"
                        @click="confirmerMotif"
                    >
                        Confirmer
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
