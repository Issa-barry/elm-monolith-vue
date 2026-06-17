<script setup lang="ts">
import { stripHtml } from '@/lib/stripHtml';
import { ArrowDown, ArrowUp, Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Tab from 'primevue/tab';
import TabList from 'primevue/tablist';
import TabPanel from 'primevue/tabpanel';
import TabPanels from 'primevue/tabpanels';
import Tabs from 'primevue/tabs';
import { computed } from 'vue';

interface StockMouvement {
    id: string;
    type: 'entree' | 'sortie';
    quantite: number;
    stock_avant: number | null;
    stock_apres: number | null;
    notes: string | null;
    site_nom: string | null;
    site_code: string | null;
    createur_nom: string | null;
    created_at: string;
    is_initial?: boolean;
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
}>();

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

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
    is_alerte: 'Alerte',
    description: 'Description',
    code_fournisseur: 'Code fournisseur',
};

function formatVal(key: string, val: unknown): string {
    if (val === null || val === undefined) return '—';
    if (key === 'is_alerte') return val ? 'Oui' : 'Non';
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
        :header="title ?? 'Historique'"
        :style="{ width: '820px' }"
        :draggable="false"
    >
        <div
            v-if="loading"
            class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
        >
            <Loader2 class="h-5 w-5 animate-spin" />
            Chargement…
        </div>

        <Tabs v-else value="0">
            <TabList>
                <Tab value="0">
                    Ajustements stock
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
                        v-if="ajustements.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Aucun ajustement de stock enregistré.
                    </div>
                    <div v-else class="overflow-x-auto pt-2">
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b text-xs text-muted-foreground"
                                >
                                    <th class="pr-4 pb-2 text-left font-medium">
                                        Date
                                    </th>
                                    <th class="pr-4 pb-2 text-left font-medium">
                                        Site
                                    </th>
                                    <th class="pr-4 pb-2 text-left font-medium">
                                        Par
                                    </th>
                                    <th
                                        class="pr-4 pb-2 text-center font-medium"
                                    >
                                        Action
                                    </th>
                                    <th
                                        class="pr-4 pb-2 text-right font-medium"
                                    >
                                        Avant
                                    </th>
                                    <th
                                        class="pr-4 pb-2 text-right font-medium"
                                    >
                                        Après
                                    </th>
                                    <th class="pb-2 text-left font-medium">
                                        Motif
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/50">
                                <tr
                                    v-for="m in ajustements"
                                    :key="m.id"
                                    class="group"
                                >
                                    <td
                                        class="py-2 pr-4 font-mono text-xs whitespace-nowrap text-muted-foreground"
                                    >
                                        {{ m.created_at }}
                                    </td>
                                    <td class="py-2 pr-4 text-xs">
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
                                    <td class="py-2 pr-4 text-xs">
                                        {{ m.createur_nom || '—' }}
                                    </td>
                                    <td class="py-2 pr-4 text-center">
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
                                        class="py-2 pr-4 text-right text-muted-foreground tabular-nums"
                                    >
                                        {{ formatQte(m.stock_avant) }}
                                    </td>
                                    <td
                                        class="py-2 pr-4 text-right font-semibold tabular-nums"
                                    >
                                        {{ formatQte(m.stock_apres) }}
                                    </td>
                                    <td
                                        class="py-2 text-xs text-muted-foreground"
                                    >
                                        {{ m.notes || '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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
                                class="mt-2 overflow-hidden rounded-lg border text-xs"
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
                        </li>
                    </ol>
                </TabPanel>
            </TabPanels>
        </Tabs>
    </Dialog>
</template>
