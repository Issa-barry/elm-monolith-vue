<script setup lang="ts">
import AuditTimeline from '@/components/AuditTimeline.vue';
import type { CommissionGlobalFiltersValue } from '@/types/commission';

const props = defineProps<{
    auditableType: string;
    auditableId: string;
    module: string;
    filters: CommissionGlobalFiltersValue;
}>();
</script>

<template>
    <AuditTimeline
        :auditable-type="props.auditableType"
        :auditable-id="props.auditableId"
        :module="props.module"
        :date-debut="props.filters.periode_range?.debut ?? undefined"
        :date-fin="props.filters.periode_range?.fin ?? undefined"
        :site-ids="props.filters.site_ids"
    />
</template>
