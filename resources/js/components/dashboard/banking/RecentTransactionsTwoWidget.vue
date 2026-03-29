<script setup lang="ts">
import { ref } from 'vue';

const price = ref(0);
</script>

<template>
    <div class="card h-full">
        <div class="mb-4 flex items-center justify-between">
            <div
                class="text-surface-900 dark:text-surface-0 text-xl font-semibold"
            >
                Recent Transactions
            </div>
            <Button
                type="button"
                icon="pi pi-plus"
                label="Add New"
                size="small"
                outlined
            ></Button>
        </div>
        <div class="flex flex-col gap-y-4">
            <div class="flex flex-col gap-4 lg:flex-row">
                <div
                    class="border-surface-200 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-surface-700 border-radius flex w-full cursor-pointer items-center rounded border p-4 lg:w-6/12"
                >
                    <img
                        alt="avatar"
                        src="/demo/images/avatar/circle/avatar-f-1.png"
                        class="mr-2 w-8 flex-shrink-0"
                    />
                    <span
                        class="text-surface-900 dark:text-surface-0 text-lg font-medium"
                        >Aisha Williams</span
                    >
                </div>
                <div
                    class="border-surface-200 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-surface-700 border-radius flex w-full cursor-pointer items-center rounded border p-4 lg:w-6/12"
                >
                    <img
                        alt="avatar"
                        src="/demo/images/avatar/circle/avatar-f-2.png"
                        class="mr-2 w-8 flex-shrink-0"
                    />
                    <span
                        class="text-surface-900 dark:text-surface-0 text-lg font-medium"
                        >Jane Watson</span
                    >
                </div>
            </div>
            <div class="flex flex-col gap-4 lg:flex-row">
                <div
                    class="border-surface-200 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-surface-700 border-radius flex w-full cursor-pointer items-center rounded border p-4 lg:w-6/12"
                >
                    <img
                        alt="avatar"
                        src="/demo/images/avatar/circle/avatar-m-1.png"
                        class="mr-2 w-8 flex-shrink-0"
                    />
                    <span
                        class="text-surface-900 dark:text-surface-0 text-lg font-medium"
                        >Brad Curry</span
                    >
                </div>
                <div
                    class="border-surface-200 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-surface-700 border-radius flex w-full cursor-pointer items-center rounded border p-4 lg:w-6/12"
                >
                    <img
                        alt="avatar"
                        src="/demo/images/avatar/circle/avatar-f-3.png"
                        class="mr-2 w-8 flex-shrink-0"
                    />
                    <span
                        class="text-surface-900 dark:text-surface-0 text-lg font-medium"
                        >Claire Dunphy</span
                    >
                </div>
            </div>
            <div class="flex flex-col gap-4 lg:flex-row">
                <div
                    class="border-surface-200 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-surface-700 border-radius flex w-full cursor-pointer items-center rounded border p-4 lg:w-6/12"
                >
                    <img
                        alt="avatar"
                        src="/demo/images/avatar/circle/avatar-m-2.png"
                        class="mr-2 w-8 flex-shrink-0"
                    />
                    <span
                        class="text-surface-900 dark:text-surface-0 text-lg font-medium"
                        >Kevin James</span
                    >
                </div>
                <div
                    class="border-surface-200 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-surface-700 flex w-full cursor-pointer items-center rounded border p-4 lg:w-6/12"
                >
                    <img
                        alt="avatar"
                        src="/demo/images/avatar/circle/avatar-f-4.png"
                        class="mr-2 w-8 flex-shrink-0"
                    />
                    <span
                        class="text-surface-900 dark:text-surface-0 text-lg font-medium"
                        >Sarah McTamish</span
                    >
                </div>
            </div>
        </div>

        <div class="mt-8 flex flex-col gap-4 sm:flex-row">
            <InputNumber
                type="text"
                :value="price"
                mode="currency"
                currency="USD"
                locale="en-US"
                fluid
            ></InputNumber>
            <Button type="button" label="Send"></Button>
        </div>
    </div>
</template>
