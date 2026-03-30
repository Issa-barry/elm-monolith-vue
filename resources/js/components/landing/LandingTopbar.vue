<script setup lang="ts">
import { dashboard, login, register } from '@/routes';
import { router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import { onBeforeUnmount, onMounted, ref } from 'vue';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const logoPath =
    'M6.84219 2.87829C5.69766 3.67858 4.6627 4.62478 3.76426 5.68992C7.4357 5.34906 12.1001 5.90564 17.5155 8.61335C23.2984 11.5047 27.955 11.6025 31.1958 10.9773C30.9017 10.087 30.5315 9.23135 30.093 8.41791C26.3832 8.80919 21.6272 8.29127 16.0845 5.51998C12.5648 3.76014 9.46221 3.03521 6.84219 2.87829ZM27.9259 5.33332C24.9962 2.06 20.7387 0 16 0C14.6084 0 13.2581 0.177686 11.9709 0.511584C13.7143 0.987269 15.5663 1.68319 17.5155 2.65781C21.5736 4.68682 25.0771 5.34013 27.9259 5.33332ZM31.8887 14.1025C27.9735 14.8756 22.567 14.7168 16.0845 11.4755C10.024 8.44527 5.20035 8.48343 1.94712 9.20639C1.7792 9.24367 1.61523 9.28287 1.45522 9.32367C1.0293 10.25 0.689308 11.2241 0.445362 12.2356C0.705909 12.166 0.975145 12.0998 1.25293 12.0381C5.19966 11.161 10.7761 11.1991 17.5155 14.5689C23.5761 17.5991 28.3997 17.561 31.6529 16.838C31.7644 16.8133 31.8742 16.7877 31.9822 16.7613C31.9941 16.509 32 16.2552 32 16C32 15.358 31.9622 14.7248 31.8887 14.1025ZM31.4598 20.1378C27.5826 20.8157 22.3336 20.5555 16.0845 17.431C10.024 14.4008 5.20035 14.439 1.94712 15.1619C1.225 15.3223 0.575392 15.5178 0.002344 15.7241C0.000781601 15.8158 0 15.9078 0 16C0 24.8366 7.16344 32 16 32C23.4057 32 29.6362 26.9687 31.4598 20.1378Z';

const isMobileMenuOpen = ref(false);
const isProductsOpen = ref(false);
const productsMenuRef = ref<HTMLElement | null>(null);

const closeProductMenus = () => {
    isProductsOpen.value = false;
};

const toggleProducts = () => {
    isProductsOpen.value = !isProductsOpen.value;
};

const onDocumentClick = (event: MouseEvent) => {
    const target = event.target as Node | null;

    if (
        target &&
        productsMenuRef.value &&
        !productsMenuRef.value.contains(target)
    ) {
        closeProductMenus();
    }
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
    <header class="sticky top-0 z-50 w-full bg-white dark:bg-slate-900">
        <div class="resize-container-1 w-full">
            <div
                class="relative flex w-full items-center justify-between bg-white px-6 py-4 shadow lg:static lg:px-12 dark:bg-slate-900"
            >
                <div class="flex items-center gap-4 py-2 lg:hidden">
                    <svg
                        width="32"
                        height="32"
                        viewBox="0 0 32 32"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            fill-rule="evenodd"
                            clip-rule="evenodd"
                            :d="logoPath"
                            class="fill-primary"
                        />
                    </svg>
                    <span
                        class="text-surface-900 dark:text-surface-0 text-xl font-medium"
                        >Eau la maman</span
                    >
                </div>

                <button
                    type="button"
                    class="text-surface-700 dark:text-surface-100 mt-1 block cursor-pointer lg:hidden"
                    @click="isMobileMenuOpen = !isMobileMenuOpen"
                >
                    <i class="pi pi-bars text-2xl" />
                </button>

                <div
                    :class="[
                        'absolute top-full left-0 z-20 w-full grow items-center justify-between bg-white px-6 shadow lg:static lg:flex lg:px-0 lg:shadow-none dark:bg-slate-900',
                        isMobileMenuOpen ? 'block' : 'hidden',
                    ]"
                >
                    <ul
                        class="text-surface-900 dark:text-surface-0 m-0 flex cursor-pointer list-none flex-col p-0 py-4 select-none lg:w-4/12 lg:flex-row lg:items-center lg:py-0"
                    >
                        <li ref="productsMenuRef" class="relative">
                            <button
                                type="button"
                                class="flex items-center px-0 py-2 font-medium transition-colors duration-150 hover:text-primary lg:px-4"
                                @click="toggleProducts"
                            >
                                <span>Partenariat</span>
                                <i class="pi pi-angle-down ml-auto lg:ml-4" />
                            </button>

                            <ul
                                :class="[
                                    'shadow-0 rounded-border m-0 list-none bg-white p-1 lg:absolute lg:top-20 lg:w-60 lg:shadow dark:bg-slate-900',
                                    isProductsOpen ? 'block' : 'hidden',
                                ]"
                            >
                                <li>
                                    <a
                                        class="hover:bg-surface-100 dark:hover:bg-surface-800 flex items-center gap-2 rounded px-4 py-2 transition-colors duration-150"
                                    >
                                        <i class="pi pi-shop" />
                                        <span>Devenir revendeur</span>
                                    </a>
                                </li>
                                <li>
                                    <a
                                        class="hover:bg-surface-100 dark:hover:bg-surface-800 flex items-center gap-2 rounded px-4 py-2 transition-colors duration-150"
                                    >
                                        <i class="pi pi-briefcase" />
                                        <span>Fournisseur</span>
                                    </a>
                                </li>
                                <li>
                                    <a
                                        class="hover:bg-surface-100 dark:hover:bg-surface-800 flex items-center gap-2 rounded px-4 py-2 transition-colors duration-150"
                                    >
                                        <i class="pi pi-chart-line" />
                                        <span>Vous voulez investir</span>
                                    </a>
                                </li>
                                <li>
                                    <a
                                        class="hover:bg-surface-100 dark:hover:bg-surface-800 flex items-center gap-2 rounded px-4 py-2 transition-colors duration-150"
                                    >
                                        <i class="pi pi-truck" />
                                        <span>
                                            Vous avez un vehicule pour la
                                            livraison
                                        </span>
                                    </a>
                                </li>
                            </ul>
                        </li>

                        <li>
                            <a
                                class="flex px-0 py-2 font-medium transition-colors duration-150 hover:text-primary lg:px-4"
                                @click="closeProductMenus"
                            >
                                <span>Produits</span>
                            </a>
                        </li>

                        <li>
                            <a
                                class="flex px-0 py-2 font-medium transition-colors duration-150 hover:text-primary lg:px-4"
                                @click="
                                    closeProductMenus();
                                    router.visit('/contact');
                                "
                            >
                                <span>Contact</span>
                            </a>
                        </li>
                    </ul>

                    <div class="hidden items-center gap-4 py-2 lg:flex">
                        <svg
                            width="32"
                            height="32"
                            viewBox="0 0 32 32"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                fill-rule="evenodd"
                                clip-rule="evenodd"
                                :d="logoPath"
                                class="fill-primary"
                            />
                        </svg>
                        <span
                            class="text-surface-900 dark:text-surface-0 text-xl font-medium"
                            >Eau la maman</span
                        >
                    </div>

                    <div
                        class="border-surface mt-4 flex border-t py-4 lg:mt-0 lg:block lg:w-4/12 lg:border-t-0 lg:py-0 lg:text-right"
                    >
                        <template v-if="$page.props.auth.user">
                            <Button
                                label="Tableau de bord"
                                rounded
                                @click="router.visit(dashboard())"
                            />
                        </template>
                        <template v-else>
                            <Button
                                label="Connexion"
                                text
                                rounded
                                @click="router.visit(login())"
                            />
                            <Button
                                v-if="canRegister"
                                label="Inscription"
                                rounded
                                class="ml-4"
                                @click="router.visit(register())"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>
