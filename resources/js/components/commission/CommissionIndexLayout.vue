<script setup lang="ts">
import CommissionIndexSummaryCards from '@/components/commission/CommissionIndexSummaryCards.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import type { CommissionIndexSummary } from '@/types/commission';
import {
    ChevronDown,
    Download,
    FileSpreadsheet,
    FileText,
    HandCoins,
    Search,
    X,
} from 'lucide-vue-next';

interface SiteOption {
    id: string;
    nom: string;
}

interface PeriodStatus {
    status: string;
    label: string;
}

interface SummaryCardLabelOverride {
    label?: string;
    ariaLabel?: string;
    tooltip?: string;
}

type SummaryLabelOverrides = Partial<
    Record<
        'generated' | 'expenses' | 'netValidated' | 'remaining',
        SummaryCardLabelOverride
    >
>;

withDefaults(
    defineProps<{
        title: string;
        entityCount: number;
        entityLabel: string;
        entityLabelPlural?: string;
        periodLabel: string;
        periodStatus?: PeriodStatus | null;
        filterUrl: string;
        savedFilterScope?: string;
        filterValues: Record<string, unknown>;
        filterFields: FilterField[];
        sites?: SiteOption[];
        /** Masque le sélecteur Agence/Site du DataFilters partagé — pour une cible qui n'est
         * rattachée à aucun site (ex: Consultant, désigné au niveau organisation), jamais pour
         * masquer artificiellement un filtre par ailleurs pertinent. */
        hideAgenceSelector?: boolean;
        summary: CommissionIndexSummary;
        /** Transmis tel quel à CommissionIndexSummaryCards — voir sa doc pour le contrat. */
        summaryLabelOverrides?: SummaryLabelOverrides;
        tableTitle: string;
        resultCount: number;
        emptyMessage?: string;
        showExport?: boolean;
        /** Affiche dans l'en-tête du tableau une recherche par mot-clé (v-model:search-query),
         * à la manière du globalFilter de la DataTable PrimeVue. La page filtre elle-même ses
         * lignes et passe `resultCount` (lignes affichées) et `totalCount` (lignes chargées). */
        searchable?: boolean;
        searchPlaceholder?: string;
        totalCount?: number;
    }>(),
    {
        entityLabelPlural: undefined,
        periodStatus: null,
        sites: () => [],
        hideAgenceSelector: false,
        summaryLabelOverrides: () => ({}),
        emptyMessage: 'Aucune commission trouvée.',
        showExport: true,
        searchable: false,
        searchPlaceholder: 'Rechercher dans le tableau',
        totalCount: undefined,
    },
);

const searchQuery = defineModel<string>('searchQuery', { default: '' });

defineEmits<{
    exportExcel: [];
    exportPdf: [];
}>();
</script>

<template>
    <div class="space-y-5 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ title }}
                </h1>
                <div
                    class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                >
                    <span>
                        {{ entityCount }}
                        {{
                            entityCount === 1
                                ? entityLabel
                                : (entityLabelPlural ?? `${entityLabel}s`)
                        }}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>{{ periodLabel }}</span>
                    <template v-if="periodStatus">
                        <span aria-hidden="true">·</span>
                        <StatusDot
                            :status="periodStatus.status"
                            :label="periodStatus.label"
                        />
                    </template>
                </div>
            </div>

            <ListPageActions>
                <template v-if="showExport" #export>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                data-testid="commission-export-trigger"
                            >
                                <Download class="mr-2 h-4 w-4" />
                                Exporter
                                <ChevronDown class="ml-2 h-3.5 w-3.5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-48">
                            <DropdownMenuItem
                                class="cursor-pointer"
                                data-testid="commission-export-excel"
                                @click="$emit('exportExcel')"
                            >
                                <FileSpreadsheet class="h-4 w-4" />
                                Exporter en Excel
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                class="cursor-pointer"
                                data-testid="commission-export-pdf"
                                @click="$emit('exportPdf')"
                            >
                                <FileText class="h-4 w-4" />
                                Exporter en PDF
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
                <template #filters>
                    <DataFilters
                        trigger-only
                        :url="filterUrl"
                        :saved-filter-scope="savedFilterScope"
                        :values="filterValues"
                        :fields="filterFields"
                        :sites="sites"
                        :hide-agence-selector="hideAgenceSelector"
                        :result-count="resultCount"
                    />
                </template>
            </ListPageActions>
        </div>

        <slot name="after-header" />

        <CommissionIndexSummaryCards
            :summary="summary"
            :label-overrides="summaryLabelOverrides"
        />

        <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
            <div
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b px-5 py-3"
            >
                <h2 class="text-base font-semibold">{{ tableTitle }}</h2>
                <div
                    class="flex w-full items-center gap-3 sm:w-auto sm:justify-end"
                >
                    <div v-if="searchable" class="relative flex-1 sm:w-72">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="searchQuery"
                            type="text"
                            :placeholder="searchPlaceholder"
                            :aria-label="searchPlaceholder"
                            data-testid="commission-table-search"
                            class="h-8 pr-8 pl-9"
                            @keydown.esc="searchQuery = ''"
                        />
                        <button
                            v-if="searchQuery"
                            type="button"
                            aria-label="Effacer la recherche"
                            class="absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                            @click="searchQuery = ''"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>
                    <span
                        class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                        data-testid="commission-result-count"
                    >
                        {{ resultCount }} résultat{{
                            resultCount !== 1 ? 's' : ''
                        }}
                        <template
                            v-if="
                                searchQuery.trim() &&
                                totalCount !== undefined &&
                                totalCount !== resultCount
                            "
                        >
                            sur {{ totalCount }}
                        </template>
                    </span>
                </div>
            </div>
            <div
                v-if="resultCount > 0"
                data-testid="commission-table-scroll"
                class="max-w-full overflow-x-auto pb-1"
            >
                <slot />
            </div>
            <div
                v-else
                data-testid="commission-empty-state"
                class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
            >
                <HandCoins class="h-12 w-12 opacity-30" />
                <p class="text-sm">{{ emptyMessage }}</p>
            </div>
        </div>
    </div>
</template>
