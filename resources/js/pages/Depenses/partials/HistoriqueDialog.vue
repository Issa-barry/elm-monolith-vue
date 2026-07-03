<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { History, Loader2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface LogEntry {
    id: string;
    date: string;
    acteur: string;
    event_code: string;
    action: string;
    description: string;
}

const props = defineProps<{
    depenseId: string | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const logs = ref<LogEntry[]>([]);
const loading = ref(false);
const fetchError = ref<string | null>(null);

watch(
    () => props.depenseId,
    async (id) => {
        if (!id) {
            logs.value = [];
            return;
        }
        loading.value = true;
        fetchError.value = null;
        try {
            const response = await fetch(`/backoffice/depenses/${id}/historique`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error();
            const data = await response.json();
            logs.value = data.logs;
        } catch {
            fetchError.value = "Impossible de charger l'historique.";
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

const eventBadge: Record<string, string> = {
    created: 'bg-blue-100 text-blue-700',
    updated: 'bg-amber-100 text-amber-700',
    submitted: 'bg-sky-100 text-sky-700',
    validated: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-red-100 text-red-700',
    cancelled: 'bg-red-100 text-red-700',
    deleted: 'bg-red-100 text-red-700',
    exported: 'bg-slate-100 text-slate-600',
};
</script>

<template>
    <Dialog
        :open="!!depenseId"
        @update:open="
            (v: boolean) => {
                if (!v) emit('close');
            }
        "
    >
        <DialogContent
            class="flex max-h-[80vh] max-w-2xl flex-col overflow-hidden"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <History class="h-5 w-5" />
                    Historique de la dépense
                </DialogTitle>
            </DialogHeader>

            <!-- Loading -->
            <div v-if="loading" class="flex items-center justify-center py-12">
                <Loader2 class="h-6 w-6 animate-spin text-muted-foreground" />
            </div>

            <!-- Erreur -->
            <div
                v-else-if="fetchError"
                class="py-8 text-center text-sm text-destructive"
            >
                {{ fetchError }}
            </div>

            <!-- Vide -->
            <div
                v-else-if="logs.length === 0"
                class="py-8 text-center text-sm text-muted-foreground"
            >
                Aucun historique disponible pour cette dépense.
            </div>

            <!-- Tableau -->
            <div v-else class="flex-1 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-background">
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-4 py-2.5 text-left text-xs font-medium whitespace-nowrap text-muted-foreground"
                            >
                                Date / Heure
                            </th>
                            <th
                                class="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground"
                            >
                                Utilisateur
                            </th>
                            <th
                                class="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground"
                            >
                                Action
                            </th>
                            <th
                                class="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground"
                            >
                                Description
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="log in logs"
                            :key="log.id"
                            class="border-b last:border-b-0 hover:bg-muted/20"
                        >
                            <td
                                class="px-4 py-3 text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                            >
                                {{ log.date }}
                            </td>
                            <td class="px-4 py-3 text-xs font-medium">
                                {{ log.acteur }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                    :class="
                                        eventBadge[log.event_code] ??
                                        'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ log.action }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ log.description }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </DialogContent>
    </Dialog>
</template>
