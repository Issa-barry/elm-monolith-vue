<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { stripHtml } from '@/lib/stripHtml';
import { useMediaQuery } from '@vueuse/core';
import { ArrowDown, ArrowUp, Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import Tab from 'primevue/tab';
import TabList from 'primevue/tablist';
import TabPanel from 'primevue/tabpanel';
import TabPanels from 'primevue/tabpanels';
import Tabs from 'primevue/tabs';
import { computed, ref, watch } from 'vue';
import './stock-dialog.css';

interface StockMouvement {
    id: string;
    type: 'entree' | 'sortie';
    quantite: number;
    stock_avant: number | null;
    stock_apres: number | null;
    notes: string | null;
    /** Clé stable du motif (ex: "vente", "transfert", "apres_production") — cf.
     * MouvementStockMotifService::classify(). Sert au filtre ci-dessous. */
    motif_type: string;
    /** Texte affiché dans la colonne Motif (ex: "Vente — CMD-230826-002"). */
    motif_label: string;
    site_nom: string | null;
    site_code: string | null;
    createur_nom: string | null;
    /** Date métier de l'opération (saisie par l'utilisateur pour un ajustement manuel). */
    date: string;
    /** Horodatage technique de création — distinct de `date`, cf. colonne Date du tableau. */
    created_at: string;
    is_initial?: boolean;
}

interface MotifOption {
    value: string;
    label: string;
}

interface AuditEntry {
    id: string;
    event_code: string;
    event_label: string;
    actor_name: string;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string;
}

const props = defineProps<{
    visible: boolean;
    ajustements: StockMouvement[];
    modifications: AuditEntry[];
    loading?: boolean;
    title?: string;
    /** Motifs réellement présents dans les mouvements chargés — jamais de valeur
     * inventée côté front, cf. HistoriqueProduitController/ShowProduitController. */
    motifOptions?: MotifOption[];
}>();

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
    (e: 'after-hide'): void;
    /** Émis quand l'utilisateur change le filtre Motif — permet au parent de
     * recharger l'historique côté backend (cf. Produits/Stock/Index.vue) pour
     * rester correct au-delà de la fenêtre chargée (take(200)). */
    (e: 'filter-motif', motif: string | null): void;
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

const selectedMotif = ref<string | null>(null);
const isMobile = useMediaQuery('(max-width: 639px)');

// Réinitialise le filtre à chaque ouverture (le composant reste monté entre deux
// produits/variantes consultés — cf. Produits/Stock/Index.vue qui ne fait pas de v-if).
watch(
    () => props.visible,
    (val) => {
        if (val) selectedMotif.value = null;
    },
);

const motifSelectOptions = computed(() => [
    { value: null as string | null, label: 'Tous les motifs' },
    ...(props.motifOptions ?? []),
]);

const ajustementsFiltres = computed(() => {
    if (!selectedMotif.value) return props.ajustements;
    return props.ajustements.filter(
        (m) => m.motif_type === selectedMotif.value,
    );
});

function onMotifChange() {
    emit('filter-motif', selectedMotif.value);
}

const eventTextColor: Record<string, string> = {
    created: 'text-blue-600 dark:text-blue-400',
    updated: 'text-amber-600 dark:text-amber-400',
    deleted: 'text-red-600 dark:text-red-400',
    validated: 'text-emerald-600 dark:text-emerald-400',
    cancelled: 'text-red-600 dark:text-red-400',
};

const eventVerb: Record<string, string> = {
    created: 'Créé par',
    updated: 'Modifié par',
    deleted: 'Supprimé par',
    validated: 'Validé par',
    cancelled: 'Annulé par',
};

const eventDotColor: Record<string, string> = {
    created: 'bg-blue-500',
    updated: 'bg-amber-500',
    deleted: 'bg-red-500',
    validated: 'bg-emerald-500',
    cancelled: 'bg-red-500',
};

const FIELD_LABELS: Record<string, string> = {
    nom: 'Nom',
    type: 'Type',
    statut: 'Statut',
    prix_vente: 'Prix de vente',
    prix_achat: "Prix d'achat",
    prix_usine: 'Prix usine',
    cout: 'Coût',
    qte_stock: 'Stock',
    seuil_alerte_stock: "Seuil d'alerte",
    description: 'Description',
    code_barres: 'Code-barres',
    fournisseur: 'Fournisseur',
};

function formatVal(key: string, val: unknown): string {
    if (val === null || val === undefined) return '—';
    if (['prix_vente', 'prix_achat', 'prix_usine', 'cout'].includes(key))
        return new Intl.NumberFormat('fr-FR').format(Number(val)) + ' GNF';
    if (['qte_stock', 'seuil_alerte_stock'].includes(key))
        return new Intl.NumberFormat('fr-FR').format(Number(val));
    if (typeof val === 'number') return String(val);
    return stripHtml(String(val));
}

function diffRows(entry: AuditEntry) {
    const old = entry.old_values ?? {};
    const next = entry.new_values ?? {};
    const keys = new Set([...Object.keys(old), ...Object.keys(next)]);
    return [...keys].map((k) => ({
        field: k,
        label: FIELD_LABELS[k] ?? k,
        old: formatVal(k, old[k]),
        new: formatVal(k, next[k]),
    }));
}

function formatQte(val: number | null | undefined): string {
    if (val === null || val === undefined) return '—';
    return new Intl.NumberFormat('fr-FR').format(val);
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        :header="isMobile ? 'Historique du stock' : (title ?? 'Historique')"
        class="stock-dialog"
        :style="{ '--stock-dialog-width': 'min(1120px, 94vw)' }"
        :pt="{ pcCloseButton: { root: { 'aria-label': 'Fermer' } } }"
        :draggable="false"
        @after-hide="emit('after-hide')"
    >
        <p
            v-if="title"
            class="mb-4 text-sm leading-6 break-words text-muted-foreground sm:hidden"
        >
            {{ title }}
        </p>
        <div
            v-if="loading"
            role="status"
            class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
        >
            <Loader2 class="h-5 w-5 animate-spin" />
            Chargement…
        </div>

        <Tabs v-else value="0">
            <TabList>
                <Tab value="0">
                    Mouvements
                    <span
                        v-if="ajustements.length"
                        class="ml-1.5 rounded-full bg-teal-100 px-1.5 py-0.5 text-xs font-medium text-teal-700 dark:bg-teal-950/40 dark:text-teal-400"
                        >{{ ajustements.length }}</span
                    >
                </Tab>
                <Tab value="1">
                    Modifications
                    <span
                        v-if="modifications.length"
                        class="ml-1.5 rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground"
                        >{{ modifications.length }}</span
                    >
                </Tab>
            </TabList>

            <TabPanels>
                <!-- ─── Onglet Ajustements ─── -->
                <TabPanel value="0">
                    <div
                        v-if="ajustements.length === 0 && !selectedMotif"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Aucun mouvement de stock enregistré.
                    </div>
                    <template v-else>
                        <div
                            class="flex flex-col gap-2 pt-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <p class="text-xs text-muted-foreground">
                                {{ ajustementsFiltres.length }} mouvement(s)
                                affiché(s)
                            </p>
                            <div
                                class="flex min-w-0 flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-2"
                            >
                                <label
                                    for="historique-motif-filter"
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    Motif
                                </label>
                                <Dropdown
                                    v-model="selectedMotif"
                                    input-id="historique-motif-filter"
                                    placeholder="Tous les motifs"
                                    :options="motifSelectOptions"
                                    option-label="label"
                                    option-value="value"
                                    class="w-full sm:w-64"
                                    :pt="{
                                        root: {
                                            'data-testid':
                                                'historique-motif-filter',
                                        },
                                    }"
                                    @change="onMotifChange"
                                />
                            </div>
                        </div>

                        <div
                            v-if="ajustementsFiltres.length === 0"
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            Aucun mouvement pour ce motif.
                        </div>
                        <div
                            v-else
                            class="mt-4 hidden overflow-x-auto rounded-xl border border-border/70 sm:block"
                        >
                            <table class="w-full min-w-[900px] text-sm">
                                <thead class="bg-muted/30">
                                    <tr
                                        class="border-b text-xs text-muted-foreground"
                                    >
                                        <th
                                            class="px-4 py-3 text-left font-medium"
                                        >
                                            Date
                                        </th>
                                        <th
                                            class="px-4 py-3 text-left font-medium"
                                        >
                                            Site
                                        </th>
                                        <th
                                            class="px-4 py-3 text-left font-medium"
                                        >
                                            Par
                                        </th>
                                        <th
                                            class="px-4 py-3 text-center font-medium"
                                        >
                                            Action
                                        </th>
                                        <th
                                            class="px-4 py-3 text-right font-medium"
                                        >
                                            Avant
                                        </th>
                                        <th
                                            class="px-4 py-3 text-right font-medium"
                                        >
                                            Après
                                        </th>
                                        <th
                                            class="px-4 py-3 text-left font-medium"
                                        >
                                            Motif
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/50">
                                    <tr
                                        v-for="m in ajustementsFiltres"
                                        :key="m.id"
                                        class="group transition-colors hover:bg-muted/20"
                                    >
                                        <td
                                            class="px-4 py-3 font-mono text-xs whitespace-nowrap"
                                        >
                                            <div class="text-foreground">
                                                {{ m.date }}
                                            </div>
                                            <div
                                                class="text-[11px] text-muted-foreground"
                                                :title="
                                                    'Enregistré le ' +
                                                    m.created_at
                                                "
                                            >
                                                Saisi le {{ m.created_at }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            <span
                                                v-if="m.site_code || m.site_nom"
                                                class="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 font-mono text-xs font-medium text-muted-foreground"
                                            >
                                                {{ m.site_code ?? m.site_nom }}
                                            </span>
                                            <span
                                                v-else
                                                class="text-muted-foreground"
                                                >—</span
                                            >
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            {{ m.createur_nom || '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span
                                                v-if="m.is_initial"
                                                class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950/30 dark:text-blue-400"
                                            >
                                                {{ m.quantite }}
                                            </span>
                                            <span
                                                v-else-if="m.type === 'entree'"
                                                class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400"
                                            >
                                                <ArrowUp class="h-3 w-3" />
                                                +{{ m.quantite }}
                                            </span>
                                            <span
                                                v-else
                                                class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/30 dark:text-red-400"
                                            >
                                                <ArrowDown class="h-3 w-3" />
                                                -{{ m.quantite }}
                                            </span>
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                        >
                                            {{ formatQte(m.stock_avant) }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right font-semibold tabular-nums"
                                        >
                                            {{ formatQte(m.stock_apres) }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-xs text-muted-foreground"
                                        >
                                            {{ m.motif_label || '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div
                            v-if="ajustementsFiltres.length"
                            class="mt-4 space-y-3 sm:hidden"
                        >
                            <article
                                v-for="m in ajustementsFiltres"
                                :key="m.id"
                                data-testid="stock-movement-card"
                                class="rounded-xl border border-border/70 p-3.5"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ m.date }}
                                        </p>
                                        <p
                                            class="mt-1 text-sm leading-5 font-semibold break-words"
                                        >
                                            {{
                                                m.motif_label ||
                                                'Motif non renseigné'
                                            }}
                                        </p>
                                    </div>
                                    <span
                                        class="shrink-0 text-base font-semibold tabular-nums"
                                        :class="
                                            m.is_initial
                                                ? 'text-foreground'
                                                : m.type === 'entree'
                                                  ? 'text-emerald-700 dark:text-emerald-400'
                                                  : 'text-red-700 dark:text-red-400'
                                        "
                                    >
                                        {{
                                            m.is_initial
                                                ? ''
                                                : m.type === 'entree'
                                                  ? '+'
                                                  : '−'
                                        }}{{ formatQte(m.quantite) }}
                                    </span>
                                </div>
                                <p
                                    class="mt-2 text-xs leading-5 break-words text-muted-foreground"
                                >
                                    {{
                                        m.site_nom ||
                                        m.site_code ||
                                        'Agence non renseignée'
                                    }}
                                    <span v-if="m.createur_nom">
                                        · {{ m.createur_nom }}</span
                                    >
                                </p>
                                <dl
                                    class="mt-3 grid grid-cols-2 gap-3 rounded-lg bg-muted/40 p-2.5"
                                >
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Avant
                                        </dt>
                                        <dd class="mt-1 text-sm tabular-nums">
                                            {{ formatQte(m.stock_avant) }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Après
                                        </dt>
                                        <dd
                                            class="mt-1 text-sm font-semibold tabular-nums"
                                        >
                                            {{ formatQte(m.stock_apres) }}
                                        </dd>
                                    </div>
                                </dl>
                                <p
                                    v-if="m.notes"
                                    class="mt-3 text-xs leading-5 break-words whitespace-pre-wrap"
                                >
                                    {{ m.notes }}
                                </p>
                                <p
                                    class="mt-2 text-[11px] leading-4 text-muted-foreground"
                                >
                                    Saisi le {{ m.created_at }}
                                </p>
                            </article>
                        </div>
                    </template>
                </TabPanel>

                <!-- ─── Onglet Modifications ─── -->
                <TabPanel value="1">
                    <div
                        v-if="modifications.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Aucune modification enregistrée.
                    </div>

                    <ol
                        v-else
                        class="relative border-l border-border pt-2 pl-1"
                    >
                        <li
                            v-for="entry in modifications"
                            :key="entry.id"
                            class="mb-6 ml-5 last:mb-0"
                        >
                            <span
                                class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border-2 border-background"
                                :class="
                                    eventDotColor[entry.event_code] ??
                                    'bg-zinc-400'
                                "
                            />
                            <div
                                class="flex flex-wrap items-baseline gap-1 text-xs"
                            >
                                <span
                                    class="font-semibold"
                                    :class="
                                        eventTextColor[entry.event_code] ??
                                        'text-muted-foreground'
                                    "
                                >
                                    {{
                                        eventVerb[entry.event_code] ??
                                        entry.event_label
                                    }}
                                </span>
                                <strong class="text-foreground">{{
                                    entry.actor_name
                                }}</strong>
                                <span class="text-muted-foreground"
                                    >— {{ entry.created_at }}</span
                                >
                            </div>

                            <div
                                v-if="
                                    (entry.old_values &&
                                        Object.keys(entry.old_values).length >
                                            0) ||
                                    (entry.new_values &&
                                        Object.keys(entry.new_values).length >
                                            0)
                                "
                                class="mt-2 hidden overflow-hidden rounded-lg border text-xs sm:block"
                            >
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b bg-muted/40">
                                            <th
                                                class="px-3 py-1.5 text-left font-medium text-muted-foreground"
                                            >
                                                Champ
                                            </th>
                                            <th
                                                v-if="entry.old_values"
                                                class="px-3 py-1.5 text-left font-medium text-muted-foreground"
                                            >
                                                Avant
                                            </th>
                                            <th
                                                v-if="entry.new_values"
                                                class="px-3 py-1.5 text-left font-medium text-muted-foreground"
                                            >
                                                Après
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="row in diffRows(entry)"
                                            :key="row.field"
                                            class="hover:bg-muted/10"
                                        >
                                            <td
                                                class="px-3 py-1.5 font-medium text-muted-foreground"
                                            >
                                                {{ row.label }}
                                            </td>
                                            <td
                                                v-if="entry.old_values"
                                                class="px-3 py-1.5 whitespace-pre-line"
                                            >
                                                {{ row.old }}
                                            </td>
                                            <td
                                                v-if="entry.new_values"
                                                class="px-3 py-1.5 whitespace-pre-line"
                                            >
                                                {{ row.new }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <dl
                                v-if="
                                    (entry.old_values &&
                                        Object.keys(entry.old_values).length) ||
                                    (entry.new_values &&
                                        Object.keys(entry.new_values).length)
                                "
                                data-testid="stock-audit-changes"
                                class="mt-3 divide-y rounded-xl border px-3 sm:hidden"
                            >
                                <div
                                    v-for="row in diffRows(entry)"
                                    :key="row.field"
                                    class="py-3"
                                >
                                    <dt class="text-xs font-semibold">
                                        {{ row.label }}
                                    </dt>
                                    <dd
                                        class="mt-2 space-y-2 text-sm leading-5"
                                    >
                                        <p
                                            v-if="entry.old_values"
                                            class="break-words whitespace-pre-wrap"
                                        >
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >Avant : </span
                                            >{{ row.old }}
                                        </p>
                                        <p
                                            v-if="entry.new_values"
                                            class="break-words whitespace-pre-wrap"
                                        >
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >Après : </span
                                            >{{ row.new }}
                                        </p>
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    </ol>
                </TabPanel>
            </TabPanels>
        </Tabs>
        <template #footer>
            <Button
                variant="outline"
                class="w-full sm:w-auto"
                @click="localVisible = false"
                >Fermer l’historique</Button
            >
        </template>
    </Dialog>
</template>
