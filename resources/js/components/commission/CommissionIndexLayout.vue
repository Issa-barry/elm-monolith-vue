<script setup lang="ts">
import CommissionIndexSummaryCards from '@/components/commission/CommissionIndexSummaryCards.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { CommissionIndexSummary } from '@/types/commission';
import {
    ChevronDown,
    Download,
    FileSpreadsheet,
    FileText,
    HandCoins,
} from 'lucide-vue-next';

interface SiteOption {
    id: string;
    nom: string;
}

interface PeriodStatus {
    status: string;
    label: string;
}

withDefaults(
    defineProps<{
        title: string;
        entityCount: number;
        entityLabel: string;
        entityLabelPlural?: string;
        periodLabel: string;
        periodStatus?: PeriodStatus | null;
        filterUrl: string;
        filterValues: Record<string, unknown>;
        filterFields: FilterField[];
        sites?: SiteOption[];
        summary: CommissionIndexSummary;
        tableTitle: string;
        resultCount: number;
        emptyMessage?: string;
    }>(),
    {
        entityLabelPlural: undefined,
        periodStatus: null,
        sites: () => [],
        emptyMessage: 'Aucune commission trouvée.',
    },
);

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

            <div class="flex items-center gap-2">
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
                <DataFilters
                    trigger-only
                    :url="filterUrl"
                    :values="filterValues"
                    :fields="filterFields"
                    :sites="sites"
                    :result-count="resultCount"
                />
            </div>
        </div>

        <slot name="after-header" />

        <CommissionIndexSummaryCards :summary="summary" />

        <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
            <div
                class="flex items-center justify-between gap-4 border-b px-5 py-3"
            >
                <h2 class="text-base font-semibold">{{ tableTitle }}</h2>
                <span class="text-xs text-muted-foreground">
                    {{ resultCount }} résultat{{ resultCount !== 1 ? 's' : '' }}
                </span>
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
