<script setup lang="ts">
import LandingTopbar from '@/components/landing/LandingTopbar.vue';
import { Head } from '@inertiajs/vue3';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="Aide" />

    <div class="bg-surface-100 min-h-screen">
        <LandingTopbar :can-register="canRegister" />
        <main class="p-6">
            <div>
                <div class="card">
                    <div
                        class="text-primary-contrast relative mb-20 flex h-80 flex-col items-center justify-center overflow-hidden rounded bg-primary"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 1440 320"
                            class="absolute top-0 left-0 w-full"
                        >
                            <path
                                fill="var(--p-primary-600)"
                                fillOpacity="1"
                                d="M0,64L48,90.7C96,117,192,171,288,208C384,245,480,267,576,256C672,245,768,203,864,165.3C960,128,1056,96,1152,74.7C1248,53,1344,43,1392,37.3L1440,32L1440,0L1392,0C1344,0,1248,0,1152,0C1056,0,960,0,864,0C768,0,672,0,576,0C480,0,384,0,288,0C192,0,96,0,48,0L0,0Z"
                            />
                        </svg>
                        <div class="z-10 mb-8 text-4xl font-bold">
                            How can we help?
                        </div>
                        <IconField class="w-9/12 md:w-6/12">
                            <InputIcon class="pi pi-search" />
                            <InputText
                                id="name"
                                type="text"
                                placeholder="Search"
                                :style="{ borderRadius: '2rem' }"
                                class="w-full"
                            />
                        </IconField>
                    </div>

                    <div class="mb-8 grid grid-cols-12 gap-4">
                        <div class="col-span-12 mb-8 md:col-span-4">
                            <div class="flex flex-col items-center">
                                <span
                                    class="bg-primary-100 mb-8 inline-flex h-20 w-20 items-center justify-center rounded-full"
                                >
                                    <i
                                        class="pi pi-power-off text-primary-700 !text-4xl"
                                    />
                                </span>
                                <div class="mb-4 text-2xl font-medium">
                                    Getting Started
                                </div>
                                <ul class="m-0 list-none p-0 text-center">
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Lorem ipsum dolor</a
                                        >
                                    </li>
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Consectetur adipiscing elit</a
                                        >
                                    </li>
                                    <li class="mb-4 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Sed do eiusmod tempor</a
                                        >
                                    </li>
                                    <li>
                                        <a
                                            class="cursor-pointer font-medium text-primary hover:underline"
                                            >View all</a
                                        >
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-span-12 mb-8 md:col-span-4">
                            <div class="flex flex-col items-center">
                                <span
                                    class="bg-primary-100 mb-8 inline-flex h-20 w-20 items-center justify-center rounded-full"
                                >
                                    <i
                                        class="pi pi-arrows-h text-primary-700 !text-4xl"
                                    />
                                </span>
                                <div class="mb-4 text-2xl font-medium">
                                    Transactions
                                </div>
                                <ul class="m-0 list-none p-0 text-center">
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Lorem ipsum dolor</a
                                        >
                                    </li>
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Consectetur adipiscing elit</a
                                        >
                                    </li>
                                    <li class="mb-4 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Sed do eiusmod tempor</a
                                        >
                                    </li>
                                    <li>
                                        <a
                                            class="cursor-pointer font-medium text-primary hover:underline"
                                            >View all</a
                                        >
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-span-12 mb-8 md:col-span-4">
                            <div class="flex flex-col items-center">
                                <span
                                    class="bg-primary-100 mb-8 inline-flex h-20 w-20 items-center justify-center rounded-full"
                                >
                                    <i
                                        class="pi pi-user text-primary-700 !text-4xl"
                                    />
                                </span>
                                <div class="mb-4 text-2xl font-medium">
                                    Profile
                                </div>
                                <ul class="m-0 list-none p-0 text-center">
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Lorem ipsum dolor</a
                                        >
                                    </li>
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Consectetur adipiscing elit</a
                                        >
                                    </li>
                                    <li class="mb-4 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Sed do eiusmod tempor</a
                                        >
                                    </li>
                                    <li>
                                        <a
                                            class="cursor-pointer font-medium text-primary hover:underline"
                                            >View all</a
                                        >
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-span-12 mb-8 md:col-span-4">
                            <div class="flex flex-col items-center">
                                <span
                                    class="bg-primary-100 mb-8 inline-flex h-20 w-20 items-center justify-center rounded-full"
                                >
                                    <i
                                        class="pi pi-money-bill text-primary-700 !text-4xl"
                                    />
                                </span>
                                <div class="mb-4 text-2xl font-medium">
                                    Billing
                                </div>
                                <ul class="m-0 list-none p-0 text-center">
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Lorem ipsum dolor</a
                                        >
                                    </li>
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Consectetur adipiscing elit</a
                                        >
                                    </li>
                                    <li class="mb-4 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Sed do eiusmod tempor</a
                                        >
                                    </li>
                                    <li>
                                        <a
                                            class="cursor-pointer font-medium text-primary hover:underline"
                                            >View all</a
                                        >
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-span-12 mb-8 md:col-span-4">
                            <div class="flex flex-col items-center">
                                <span
                                    class="bg-primary-100 mb-8 inline-flex h-20 w-20 items-center justify-center rounded-full"
                                >
                                    <i
                                        class="pi pi-database text-primary-700 !text-4xl"
                                    />
                                </span>
                                <div class="mb-4 text-2xl font-medium">
                                    Integrations
                                </div>
                                <ul class="m-0 list-none p-0 text-center">
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Lorem ipsum dolor</a
                                        >
                                    </li>
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Consectetur adipiscing elit</a
                                        >
                                    </li>
                                    <li class="mb-4 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Sed do eiusmod tempor</a
                                        >
                                    </li>
                                    <li>
                                        <a
                                            class="cursor-pointer font-medium text-primary hover:underline"
                                            >View all</a
                                        >
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-span-12 mb-8 md:col-span-4">
                            <div class="flex flex-col items-center">
                                <span
                                    class="bg-primary-100 mb-8 inline-flex h-20 w-20 items-center justify-center rounded-full"
                                >
                                    <i
                                        class="pi pi-lock text-primary-700 !text-4xl"
                                    />
                                </span>
                                <div class="mb-4 text-2xl font-medium">
                                    Security
                                </div>
                                <ul class="m-0 list-none p-0 text-center">
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Lorem ipsum dolor</a
                                        >
                                    </li>
                                    <li class="mb-1 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Consectetur adipiscing elit</a
                                        >
                                    </li>
                                    <li class="mb-4 leading-normal">
                                        <a
                                            class="text-surface-500 dark:text-surface-400 cursor-pointer hover:text-primary"
                                            >Sed do eiusmod tempor</a
                                        >
                                    </li>
                                    <li>
                                        <a
                                            class="cursor-pointer font-medium text-primary hover:underline"
                                            >View all</a
                                        >
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
