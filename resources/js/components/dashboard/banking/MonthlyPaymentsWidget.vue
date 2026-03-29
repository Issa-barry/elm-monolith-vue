<script setup>
import { ref } from 'vue';

const payments = ref([
    { name: 'Electric Bill', amount: 75.6, paid: true, date: '06/04/2022' },
    { name: 'Water Bill', amount: 45.5, paid: true, date: '07/04/2022' },
    { name: 'Gas Bill', amount: 45.2, paid: false, date: '12/04/2022' },
    { name: 'Internet Bill', amount: 25.9, paid: true, date: '17/04/2022' },
    { name: 'Streaming', amount: 40.9, paid: false, date: '20/04/2022' },
]);

function formatCurrency(value) {
    return value.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
    });
}
</script>

<template>
    <div class="card">
        <div
            class="text-surface-900 dark:text-surface-0 mb-4 text-xl font-semibold"
        >
            Monthly Payments
        </div>

        <DataTable ref="dt" :value="payments" dataKey="id" :rows="5">
            <template #empty> No products found.</template>
            <Column field="name" header="Name" class="w-4/12 whitespace-nowrap">
            </Column>
            <Column
                field="price"
                header="Amount"
                class="w-4/12 whitespace-nowrap"
            >
                <template #body="{ data }">
                    {{ formatCurrency(data.amount) }}
                </template>
            </Column>
            <Column field="date" header="Date" class="w-4/12 whitespace-nowrap">
            </Column>
            <Column
                field="inventoryStatus"
                header="Status"
                class="w-4/12 text-right whitespace-nowrap"
            >
                <template #body="{ data }">
                    <Tag
                        v-if="data.paid"
                        value="COMPLETED"
                        severity="success"
                    ></Tag>
                    <Tag v-else value="PENDING" severity="warn"></Tag>
                </template>
            </Column>
        </DataTable>
    </div>
</template>
