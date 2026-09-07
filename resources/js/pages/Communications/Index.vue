<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Eye, MessageSquare } from 'lucide-vue-next';
import PvDialog from 'primevue/dialog';
import { ref } from 'vue';

interface MessageLogRow {
    id: string;
    channel: string;
    channel_label: string;
    direction: string;
    direction_label: string;
    purpose: string | null;
    purpose_label: string;
    provider: string;
    provider_message_id: string | null;
    masked_recipient: string;
    status: string;
    status_label: string;
    provider_status: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    sent_at: string | null;
    failed_at: string | null;
}

interface SelectOption {
    value: string;
    label: string;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    logs: Paginator<MessageLogRow>;
    filters: {
        channel: string;
        direction: string;
        status: string;
        search: string;
        dateDebut: string;
        dateFin: string;
    };
    channels: SelectOption[];
    directions: SelectOption[];
    statuses: SelectOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Communications', href: '/backoffice/communications' },
];

const filterFields: FilterField[] = [
    {
        key: 'status',
        label: 'Statut',
        type: 'select',
        inline: true,
        options: props.statuses,
    },
    {
        key: 'channel',
        label: 'Canal',
        type: 'select',
        inline: true,
        options: props.channels,
    },
    {
        key: 'direction',
        label: 'Sens',
        type: 'select',
        inline: true,
        options: props.directions,
    },
    {
        key: 'search',
        label: 'Destinataire',
        type: 'text',
        inline: true,
        // Le numéro est masqué en base (jamais stocké en clair) — seuls les
        // chiffres visibles (préfixe/suffixe) sont recherchables.
        placeholder: 'Chiffres visibles (ex: 224 ou 12)',
    },
    {
        key: 'date',
        label: 'Période',
        type: 'date-range',
        startKey: 'date_debut',
        endKey: 'date_fin',
    },
];

const communicationsFilters = {
    status: props.filters.status ?? '',
    channel: props.filters.channel ?? '',
    direction: props.filters.direction ?? '',
    search: props.filters.search ?? '',
    date_debut: props.filters.dateDebut ?? '',
    date_fin: props.filters.dateFin ?? '',
};

const selectedLog = ref<MessageLogRow | null>(null);
const showDetail = ref(false);

function openDetail(log: MessageLogRow) {
    selectedLog.value = log;
    showDetail.value = true;
}
</script>

<template>
    <Head title="Communications" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Communications
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ logs.total }} message{{ logs.total !== 1 ? 's' : '' }}
                        — journal des envois SMS/WhatsApp
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/backoffice/communications"
                :values="communicationsFilters"
                :fields="filterFields"
                :result-count="logs.total"
                hide-agence-selector
            />

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table v-if="logs.data.length > 0" class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Date
                            </th>
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Canal
                            </th>
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Type
                            </th>
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Destinataire
                            </th>
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Statut
                            </th>
                            <th class="w-10 px-4 py-3.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="log in logs.data"
                            :key="log.id"
                            class="transition-colors hover:bg-muted/10"
                        >
                            <td
                                class="px-5 py-3.5 text-xs text-muted-foreground"
                            >
                                {{ log.created_at }}
                            </td>
                            <td class="px-5 py-3.5">
                                {{ log.channel_label }}
                                <span class="text-xs text-muted-foreground"
                                    >({{ log.direction_label }})</span
                                >
                            </td>
                            <td class="px-5 py-3.5 text-sm">
                                {{ log.purpose_label }}
                            </td>
                            <td class="px-5 py-3.5 font-mono text-xs">
                                {{ log.masked_recipient }}
                            </td>
                            <td class="px-5 py-3.5">
                                <StatusDot
                                    :status="log.status"
                                    :label="log.status_label"
                                />
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="rounded p-1 hover:bg-muted/50"
                                    title="Voir les détails"
                                    @click="openDetail(log)"
                                >
                                    <Eye
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div
                    v-else
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <MessageSquare class="h-12 w-12 opacity-30" />
                    <p class="text-sm">
                        Aucun message enregistré pour ce filtre.
                    </p>
                </div>
            </div>

            <!-- Pagination -->
            <div
                v-if="logs.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    {{ logs.from }}–{{ logs.to }} sur {{ logs.total }}
                </p>
                <div class="flex items-center gap-1">
                    <a
                        v-for="link in logs.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        class="inline-flex h-8 items-center justify-center rounded px-3 text-sm transition-colors"
                        :class="[
                            link.active
                                ? 'bg-primary font-medium text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted/50',
                            !link.url ? 'pointer-events-none opacity-40' : '',
                        ]"
                        v-html="
                            link.label
                                .replace('&laquo;', '‹')
                                .replace('&raquo;', '›')
                        "
                    />
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Détail Dialog -->
    <PvDialog
        v-model:visible="showDetail"
        modal
        header="Détail du message"
        :style="{ width: '560px' }"
        :draggable="false"
    >
        <template v-if="selectedLog">
            <div class="space-y-4 text-sm">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Canal
                        </p>
                        <p class="mt-1">
                            {{ selectedLog.channel_label }} ({{
                                selectedLog.direction_label
                            }})
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Type
                        </p>
                        <p class="mt-1">{{ selectedLog.purpose_label }}</p>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Destinataire
                        </p>
                        <p class="mt-1 font-mono">
                            {{ selectedLog.masked_recipient }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Fournisseur
                        </p>
                        <p class="mt-1 capitalize">
                            {{ selectedLog.provider }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Statut
                        </p>
                        <p class="mt-1">
                            <StatusDot
                                :status="selectedLog.status"
                                :label="selectedLog.status_label"
                            />
                        </p>
                    </div>
                    <div v-if="selectedLog.provider_message_id">
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            ID fournisseur
                        </p>
                        <p class="mt-1 font-mono text-xs">
                            {{ selectedLog.provider_message_id }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Créé le
                        </p>
                        <p class="mt-1">{{ selectedLog.created_at }}</p>
                    </div>
                    <div v-if="selectedLog.sent_at">
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Envoyé le
                        </p>
                        <p class="mt-1">{{ selectedLog.sent_at }}</p>
                    </div>
                    <div v-if="selectedLog.failed_at">
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Échoué le
                        </p>
                        <p class="mt-1">{{ selectedLog.failed_at }}</p>
                    </div>
                </div>

                <div
                    v-if="selectedLog.error_message"
                    class="space-y-1 rounded-lg bg-muted/50 p-3"
                >
                    <p
                        class="text-xs font-medium text-muted-foreground uppercase"
                    >
                        Erreur
                        <span v-if="selectedLog.error_code"
                            >({{ selectedLog.error_code }})</span
                        >
                    </p>
                    <p class="text-muted-foreground">
                        {{ selectedLog.error_message }}
                    </p>
                </div>
            </div>
        </template>
    </PvDialog>
</template>
