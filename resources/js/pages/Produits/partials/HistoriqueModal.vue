<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed } from 'vue';

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
    historiques: AuditEntry[];
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

// ── Couleurs et verbes par event_code ─────────────────────────────────────────

const eventTextColor: Record<string, string> = {
    created: 'text-blue-600 dark:text-blue-400',
    updated: 'text-amber-600 dark:text-amber-400',
    deleted: 'text-red-600 dark:text-red-400',
    stock_adjusted: 'text-teal-600 dark:text-teal-400',
    validated: 'text-emerald-600 dark:text-emerald-400',
    cancelled: 'text-red-600 dark:text-red-400',
};

const eventVerb: Record<string, string> = {
    created: 'Créé par',
    updated: 'Modifié par',
    deleted: 'Supprimé par',
    stock_adjusted: 'Stock ajusté par',
    validated: 'Validé par',
    cancelled: 'Annulé par',
};

const eventDotColor: Record<string, string> = {
    created: 'bg-blue-500',
    updated: 'bg-amber-500',
    deleted: 'bg-red-500',
    stock_adjusted: 'bg-teal-500',
    validated: 'bg-emerald-500',
    cancelled: 'bg-red-500',
};

// ── Libellés des champs ───────────────────────────────────────────────────────

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
    motif: 'Motif',
};

function formatVal(key: string, val: unknown): string {
    if (val === null || val === undefined) return '—';
    if (key === 'is_alerte') return val ? 'Oui' : 'Non';
    if (
        key === 'prix_vente' ||
        key === 'prix_achat' ||
        key === 'prix_usine' ||
        key === 'cout'
    )
        return new Intl.NumberFormat('fr-FR').format(Number(val)) + ' GNF';
    if (key === 'qte_stock' || key === 'seuil_alerte_stock')
        return new Intl.NumberFormat('fr-FR').format(Number(val));
    return String(val);
}

function diffRows(
    entry: AuditEntry,
): { field: string; label: string; old: string; new: string }[] {
    const old = entry.old_values ?? {};
    const next = entry.new_values ?? {};
    const keys = new Set([...Object.keys(old), ...Object.keys(next)]);
    const rows: { field: string; label: string; old: string; new: string }[] =
        [];
    keys.forEach((k) => {
        rows.push({
            field: k,
            label: FIELD_LABELS[k] ?? k,
            old: formatVal(k, old[k]),
            new: formatVal(k, next[k]),
        });
    });
    return rows;
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        :header="title ?? 'Historique'"
        :style="{ width: '760px' }"
        :draggable="false"
    >
        <div
            v-if="loading"
            class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
        >
            <Loader2 class="h-5 w-5 animate-spin" />
            Chargement…
        </div>

        <div
            v-else-if="historiques.length === 0"
            class="py-8 text-center text-sm text-muted-foreground"
        >
            Aucun historique disponible.
        </div>

        <ol v-else-if="!loading" class="relative border-l border-border pl-1">
            <li
                v-for="entry in historiques"
                :key="entry.id"
                class="mb-6 ml-5 last:mb-0"
            >
                <!-- dot -->
                <span
                    class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border-2 border-background"
                    :class="eventDotColor[entry.event_code] ?? 'bg-zinc-400'"
                />

                <!-- header ligne -->
                <div class="flex flex-wrap items-baseline gap-1 text-xs">
                    <span
                        class="font-semibold"
                        :class="
                            eventTextColor[entry.event_code] ??
                            'text-muted-foreground'
                        "
                    >
                        {{ eventVerb[entry.event_code] ?? entry.event_label }}
                    </span>
                    <strong class="text-foreground">{{
                        entry.actor_name
                    }}</strong>
                    <span class="text-muted-foreground"
                        >— {{ entry.created_at }}</span
                    >
                </div>

                <!-- table diff -->
                <div
                    v-if="
                        (entry.old_values &&
                            Object.keys(entry.old_values).length > 0) ||
                        (entry.new_values &&
                            Object.keys(entry.new_values).length > 0)
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
    </Dialog>
</template>
