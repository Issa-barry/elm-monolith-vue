<script setup lang="ts">
import ReceptionAchatDialog, {
    type CommandeAReceptionner,
} from '@/components/achats/ReceptionAchatDialog.vue';
import ReceptionsOrigineTabs from '@/components/achats/ReceptionsOrigineTabs.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, PackageCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Ligne {
    id: string;
    produit_nom: string;
    qte: number;
    qte_recue: number;
    reliquat: number;
}

interface Commande {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
    fournisseur_nom: string | null;
    site_nom: string | null;
    validee_at: string | null;
    qte_commandee: number;
    qte_recue: number;
    lignes: Ligne[];
    peut_receptionner: boolean;
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    commandes: {
        data: Commande[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        last_page: number;
    };
    filters: Record<string, unknown>;
    statuts: Option[];
    fournisseurs: Option[];
    sites: { id: string; nom: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Logistique', href: '/backoffice/logistique/receptions' },
    {
        title: 'Réceptions — commandes fournisseurs',
        href: '/backoffice/logistique/receptions-fournisseurs',
    },
];

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        inline: true,
        options: [{ value: '', label: 'À réceptionner' }, ...props.statuts],
    },
    {
        key: 'fournisseur_id',
        label: 'Fournisseur',
        type: 'select',
        inline: true,
        searchable: true,
        options: [
            { value: '', label: 'Tous les fournisseurs' },
            ...props.fournisseurs,
        ],
    },
    {
        key: 'reference',
        label: 'Référence',
        type: 'text',
        inline: true,
        placeholder: 'BC-…',
    },
]);

const dialogOuvert = ref(false);
const selection = ref<CommandeAReceptionner | null>(null);

function receptionner(c: Commande) {
    selection.value = {
        id: c.id,
        reference: c.reference,
        site_nom: c.site_nom,
        lignes: c.lignes,
    };
    dialogOuvert.value = true;
}

function paginationLabel(label: string): string {
    return label.replace(/&laquo;|&raquo;/g, '').trim();
}
</script>

<template>
    <Head title="Réceptions — commandes fournisseurs" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4 sm:gap-6 sm:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Réceptions
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Bons de commande fournisseurs validés attendus dans vos
                    agences. Chaque réception augmente le stock de l'agence de
                    la commande ; une commande peut être reçue en plusieurs
                    fois.
                </p>
            </div>

            <ReceptionsOrigineTabs actif="fournisseurs" />

            <DataFilters
                url="/backoffice/logistique/receptions-fournisseurs"
                :values="filters"
                :sites="sites"
                :result-count="commandes.total"
                :fields="filterFields"
            />

            <div
                v-if="commandes.data.length === 0"
                class="rounded-xl border bg-card px-4 py-16 text-center text-sm text-muted-foreground"
            >
                <PackageCheck class="mx-auto mb-3 h-10 w-10 opacity-30" />
                Aucun bon de commande à réceptionner.
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="c in commandes.data"
                    :key="c.id"
                    class="rounded-xl border bg-card p-4 shadow-sm"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    :href="`/backoffice/achats/${c.id}`"
                                    class="font-mono font-semibold tracking-wide hover:underline"
                                    >{{ c.reference }}</Link
                                >
                                <StatusDot
                                    :status="c.statut"
                                    :label="c.statut_label"
                                    class="text-sm text-muted-foreground"
                                />
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ c.fournisseur_nom ?? '—' }} →
                                <span class="font-medium text-foreground">{{
                                    c.site_nom
                                }}</span>
                                <template v-if="c.validee_at">
                                    · validée le {{ c.validee_at }}</template
                                >
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span
                                class="text-sm text-muted-foreground tabular-nums"
                                >Reçu {{ c.qte_recue }} /
                                {{ c.qte_commandee }}</span
                            >
                            <Button
                                v-if="c.peut_receptionner"
                                size="sm"
                                class="bg-emerald-600 text-white hover:bg-emerald-700"
                                @click="receptionner(c)"
                            >
                                <PackageCheck class="mr-2 h-4 w-4" />
                                Réceptionner
                            </Button>
                        </div>
                    </div>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs text-muted-foreground">
                                    <th class="py-1 text-left font-medium">
                                        Produit
                                    </th>
                                    <th class="py-1 text-right font-medium">
                                        Commandé
                                    </th>
                                    <th class="py-1 text-right font-medium">
                                        Reçu
                                    </th>
                                    <th class="py-1 text-right font-medium">
                                        Reste
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in c.lignes" :key="l.id">
                                    <td class="py-1">{{ l.produit_nom }}</td>
                                    <td class="py-1 text-right tabular-nums">
                                        {{ l.qte }}
                                    </td>
                                    <td class="py-1 text-right tabular-nums">
                                        {{ l.qte_recue }}
                                    </td>
                                    <td
                                        class="py-1 text-right font-medium tabular-nums"
                                    >
                                        {{ l.reliquat }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div
                v-if="commandes.last_page > 1"
                class="flex flex-wrap items-center justify-center gap-1"
            >
                <template v-for="link in commandes.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-muted"
                        :class="{
                            'border-primary bg-primary text-primary-foreground hover:bg-primary/90':
                                link.active,
                        }"
                    >
                        <ChevronLeft
                            v-if="link.label.includes('&laquo')"
                            class="h-4 w-4"
                        />
                        <ChevronRight
                            v-else-if="link.label.includes('&raquo')"
                            class="h-4 w-4"
                        />
                        <span v-else>{{ paginationLabel(link.label) }}</span>
                    </Link>
                </template>
            </div>
        </div>

        <ReceptionAchatDialog
            :open="dialogOuvert"
            :commande="selection"
            @update:open="dialogOuvert = $event"
        />
    </AppLayout>
</template>
