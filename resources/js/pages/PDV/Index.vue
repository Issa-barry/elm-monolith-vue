<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

interface Product {
    id: number;
    code: string;
    name: string;
    subtitle: string;
    category: 'Packs' | 'Accessoires' | 'Boissons';
    stock: number;
    unitPrice: number;
    image: string;
}

interface CartRow {
    productId: number;
    quantity: number;
}

type CartItem = Product & {
    quantity: number;
    lineTotal: number;
};

type SaleMode = 'Vente rapide' | 'Client' | 'Livreur';
type ProductCategory = 'Tous' | 'Packs' | 'Accessoires' | 'Boissons';

const primeImage1 =
    'https://fqjltiegiezfetthbags.supabase.co/storage/v1/object/public/block.images/blocks/ecommerce/shoppingcart/extended-slide-over-1.jpg';
const primeImage2 =
    'https://fqjltiegiezfetthbags.supabase.co/storage/v1/object/public/block.images/blocks/ecommerce/shoppingcart/extended-slide-over-2.jpg';
const primeImage3 =
    'https://fqjltiegiezfetthbags.supabase.co/storage/v1/object/public/block.images/blocks/ecommerce/shoppingcart/extended-slide-over-3.jpg';
const primeImage4 =
    'https://fqjltiegiezfetthbags.supabase.co/storage/v1/object/public/block.images/blocks/ecommerce/shoppingcart/extended-slide-over-4.jpg';

const searchQuery = ref('');
const selectedCategory = ref<ProductCategory>('Tous');
const selectedMode = ref<SaleMode>('Vente rapide');

const products = ref<Product[]>([
    {
        id: 1,
        code: 'PDV-001',
        name: 'Pack de 30',
        subtitle: 'Eau de source',
        category: 'Packs',
        stock: 120,
        unitPrice: 5000,
        image: primeImage1,
    },
    {
        id: 2,
        code: 'PDV-002',
        name: 'Pack de 20',
        subtitle: 'Eau minerale',
        category: 'Packs',
        stock: 85,
        unitPrice: 3500,
        image: primeImage2,
    },
    {
        id: 3,
        code: 'PDV-003',
        name: 'Bouteille 1.5L',
        subtitle: 'Carton x6',
        category: 'Boissons',
        stock: 240,
        unitPrice: 1200,
        image: primeImage3,
    },
    {
        id: 4,
        code: 'PDV-004',
        name: 'Bouteille 0.5L',
        subtitle: 'Carton x12',
        category: 'Boissons',
        stock: 300,
        unitPrice: 900,
        image: primeImage4,
    },
    {
        id: 5,
        code: 'PDV-005',
        name: 'Bidon 10L',
        subtitle: 'Consigne incluse',
        category: 'Packs',
        stock: 50,
        unitPrice: 8000,
        image: primeImage1,
    },
    {
        id: 6,
        code: 'PDV-006',
        name: 'Distributeur',
        subtitle: 'Tabletop',
        category: 'Accessoires',
        stock: 15,
        unitPrice: 45000,
        image: primeImage2,
    },
    {
        id: 7,
        code: 'PDV-007',
        name: 'Gobelets',
        subtitle: 'Pack x50',
        category: 'Accessoires',
        stock: 400,
        unitPrice: 1500,
        image: primeImage3,
    },
    {
        id: 8,
        code: 'PDV-008',
        name: 'Bouchons',
        subtitle: 'Sachet x100',
        category: 'Accessoires',
        stock: 600,
        unitPrice: 700,
        image: primeImage4,
    },
    {
        id: 9,
        code: 'PDV-009',
        name: 'Pack de 10',
        subtitle: 'Eau minerale',
        category: 'Packs',
        stock: 180,
        unitPrice: 2000,
        image: primeImage1,
    },
    {
        id: 10,
        code: 'PDV-010',
        name: 'Gallon 5L',
        subtitle: 'Carton x4',
        category: 'Boissons',
        stock: 95,
        unitPrice: 3000,
        image: primeImage2,
    },
    {
        id: 11,
        code: 'PDV-011',
        name: 'Pompe manuelle',
        subtitle: 'Compatible bidons',
        category: 'Accessoires',
        stock: 60,
        unitPrice: 2500,
        image: primeImage3,
    },
    {
        id: 12,
        code: 'PDV-012',
        name: 'Verres carton',
        subtitle: 'Pack x100',
        category: 'Accessoires',
        stock: 220,
        unitPrice: 1800,
        image: primeImage4,
    },
]);

const cartRows = ref<CartRow[]>([
    { productId: 1, quantity: 1 },
    { productId: 3, quantity: 2 },
]);

const categories: ProductCategory[] = [
    'Tous',
    'Packs',
    'Accessoires',
    'Boissons',
];
const saleModes: SaleMode[] = ['Vente rapide', 'Client', 'Livreur'];

const filteredProducts = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return products.value.filter((product) => {
        const inCategory =
            selectedCategory.value === 'Tous' ||
            product.category === selectedCategory.value;

        if (!inCategory) {
            return false;
        }

        if (!query) {
            return true;
        }

        const bag =
            `${product.name} ${product.subtitle} ${product.code}`.toLowerCase();

        return bag.includes(query);
    });
});

const cartItems = computed<CartItem[]>(() => {
    const items: CartItem[] = [];

    for (const row of cartRows.value) {
        const product = products.value.find(
            (item) => item.id === row.productId,
        );
        if (!product) {
            continue;
        }

        items.push({
            ...product,
            quantity: row.quantity,
            lineTotal: product.unitPrice * row.quantity,
        });
    }

    return items;
});

const subtotal = computed(() =>
    cartItems.value.reduce((sum, item) => sum + item.lineTotal, 0),
);

const shippingCost = ref(0);
const taxRate = ref(0.03);
const taxAmount = computed(() => Math.round(subtotal.value * taxRate.value));
const totalAmount = computed(
    () => subtotal.value + shippingCost.value + taxAmount.value,
);

function addToCart(product: Product): void {
    const line = cartRows.value.find((row) => row.productId === product.id);

    if (line) {
        line.quantity += 1;
        return;
    }

    cartRows.value.push({ productId: product.id, quantity: 1 });
}

function increase(productId: number): void {
    const line = cartRows.value.find((row) => row.productId === productId);

    if (!line) {
        return;
    }

    line.quantity += 1;
}

function decrease(productId: number): void {
    const line = cartRows.value.find((row) => row.productId === productId);

    if (!line) {
        return;
    }

    if (line.quantity <= 1) {
        cartRows.value = cartRows.value.filter(
            (row) => row.productId !== productId,
        );
        return;
    }

    line.quantity -= 1;
}

function removeLine(productId: number): void {
    cartRows.value = cartRows.value.filter(
        (row) => row.productId !== productId,
    );
}

function formatGNF(value: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(value)} GNF`;
}
</script>

<template>
    <Head title="PDV" />

    <div
        class="bg-surface-100 dark:bg-surface-950 fixed inset-0 z-[120] overflow-hidden"
    >
        <div class="flex h-full">
            <section class="flex min-w-0 flex-1 flex-col">
                <div
                    class="bg-surface-0 border-surface-200 dark:bg-surface-900 dark:border-surface-700 border-b px-6 py-4"
                >
                    <div class="flex flex-col gap-4">
                        <div
                            class="flex flex-wrap items-center justify-between gap-4"
                        >
                            <div class="flex flex-wrap items-center gap-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-surface-500 dark:text-surface-400 text-xs tracking-wide uppercase"
                                    >
                                        POS
                                    </p>
                                    <h1
                                        class="text-surface-900 dark:text-surface-0 text-xl leading-tight font-semibold"
                                    >
                                        Nouvelle vente
                                    </h1>
                                </div>

                                <label class="w-full sm:w-64 md:w-72">
                                    
                                    <InputText
                                        v-model="searchQuery"
                                        placeholder="Rechercher un produit"
                                        class="mt-1 h-10 w-full rounded-lg text-sm"
                                    />
                                </label>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    v-for="mode in saleModes"
                                    :key="mode"
                                    :label="mode"
                                    :severity="
                                        selectedMode === mode
                                            ? 'primary'
                                            : 'secondary'
                                    "
                                    :outlined="selectedMode !== mode"
                                    size="small"
                                    class="h-9 rounded-full px-3 text-xs font-medium"
                                    @click="selectedMode = mode"
                                />
                            </div>
                        </div>

                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <Button
                                v-for="category in categories"
                                :key="category"
                                :label="category"
                                :severity="
                                    selectedCategory === category
                                        ? 'primary'
                                        : 'secondary'
                                "
                                :outlined="selectedCategory !== category"
                                size="small"
                                class="h-9 rounded-full px-3 text-xs font-medium"
                                @click="selectedCategory = category"
                            />
                        </div>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-auto p-5">
                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                        <article
                            v-for="product in filteredProducts"
                            :key="product.id"
                            class="bg-surface-0 border-surface-200 dark:bg-surface-900 dark:border-surface-700 overflow-hidden rounded-2xl border"
                        >
                            <div class="flex h-full">
                                <div
                                    class="bg-surface-100 dark:bg-surface-800 w-28 min-w-[112px] self-stretch"
                                >
                                    <img
                                        :src="product.image"
                                        :alt="product.name"
                                        class="h-full w-full object-cover"
                                    />
                                </div>

                                <div class="flex min-w-0 flex-1 flex-col p-3">
                                    <div
                                        class="mb-3 flex items-start justify-between gap-3"
                                    >
                                        <div class="min-w-0">
                                            <h3
                                                class="text-surface-900 dark:text-surface-0 truncate text-sm font-semibold"
                                            >
                                                {{ product.name }}
                                            </h3>
                                            <p
                                                class="text-surface-500 dark:text-surface-400 text-xs"
                                            >
                                                {{ product.subtitle }}
                                            </p>
                                            <p
                                                class="text-surface-400 dark:text-surface-500 mt-1 text-xs"
                                            >
                                                Stock: {{ product.stock }}
                                            </p>
                                        </div>
                                        <p
                                            class="text-surface-900 dark:text-surface-0 text-sm font-semibold whitespace-nowrap"
                                        >
                                            {{ formatGNF(product.unitPrice) }}
                                        </p>
                                    </div>

                                    <Button
                                        label="Ajouter"
                                        severity="secondary"
                                        class="!bg-surface-100 !text-surface-600 !border-surface-200 !py-1.4 mt-auto h-8 w-full rounded-lg !px-3 text-xs font-medium"
                                        @click="addToCart(product)"
                                    />
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <aside
                class="bg-surface-0 border-surface-200 dark:bg-surface-900 dark:border-surface-700 flex h-full w-full flex-col border-l md:w-[420px] md:min-w-[420px] xl:w-[480px] xl:min-w-[480px]"
            >
                <div
                    class="border-surface-200 dark:border-surface-700 flex items-center justify-between border-b px-5 py-5"
                >
                    <h2
                        class="text-surface-900 dark:text-surface-0 text-lg font-semibold"
                    >
                        Panier
                    </h2>
                    <Link href="/ventes">
                        <Button
                            icon="pi pi-times"
                            text
                            rounded
                            class="h-9 w-9"
                            aria-label="Fermer"
                        />
                    </Link>
                </div>

                <div class="min-h-0 flex-1 overflow-auto px-5 py-4">
                    <div
                        v-if="cartItems.length === 0"
                        class="border-surface-300 text-surface-500 dark:border-surface-600 dark:text-surface-400 rounded-xl border border-dashed p-6 text-center"
                    >
                        Aucun article dans le panier.
                    </div>

                    <div
                        v-for="item in cartItems"
                        :key="item.id"
                        class="border-surface-200 dark:border-surface-700 border-b py-4"
                    >
                        <div class="flex items-start gap-3">
                            <img
                                :src="item.image"
                                :alt="item.name"
                                class="h-16 w-14 shrink-0 rounded-lg object-cover"
                            />

                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="text-surface-900 dark:text-surface-0 truncate text-sm font-semibold"
                                        >
                                            {{ item.name }}
                                        </p>
                                        <p
                                            class="text-surface-500 dark:text-surface-400 text-xs"
                                        >
                                            {{ item.subtitle }}
                                        </p>
                                    </div>
                                    <div class="flex items-start gap-1">
                                        <p
                                            class="text-surface-900 dark:text-surface-0 text-sm font-semibold whitespace-nowrap"
                                        >
                                            {{ formatGNF(item.lineTotal) }}
                                        </p>
                                        <Button
                                            icon="pi pi-trash"
                                            text
                                            size="small"
                                            class="h-7 w-7 !text-primary"
                                            @click="removeLine(item.id)"
                                        />
                                    </div>
                                </div>

                                <div class="mt-3 flex items-center gap-3">
                                    <Button
                                        icon="pi pi-minus"
                                        text
                                        size="small"
                                        class="h-7 w-7 !text-primary"
                                        @click="decrease(item.id)"
                                    />
                                    <span
                                        class="text-surface-900 dark:text-surface-0 min-w-6 text-center text-sm font-medium"
                                        >{{ item.quantity }}</span
                                    >
                                    <Button
                                        icon="pi pi-plus"
                                        text
                                        size="small"
                                        class="h-7 w-7 !text-primary"
                                        @click="increase(item.id)"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="border-surface-200 dark:border-surface-700 space-y-2 border-t px-5 pt-5 pb-6"
                >
                    <div
                        class="text-surface-700 dark:text-surface-300 flex items-center justify-between text-sm"
                    >
                        <span>Sous-total</span>
                        <span class="font-medium">{{
                            formatGNF(subtotal)
                        }}</span>
                    </div>
                    <div
                        class="text-surface-700 dark:text-surface-300 flex items-center justify-between text-sm"
                    >
                        <span>Livraison</span>
                        <span class="font-medium">{{
                            formatGNF(shippingCost)
                        }}</span>
                    </div>
                    <div
                        class="text-surface-700 dark:text-surface-300 flex items-center justify-between text-sm"
                    >
                        <span>Taxe</span>
                        <span class="font-medium">{{
                            formatGNF(taxAmount)
                        }}</span>
                    </div>

                    <div
                        class="text-surface-900 dark:text-surface-0 mt-2 flex items-center justify-between text-base font-semibold"
                    >
                        <span>Total</span>
                        <span>{{ formatGNF(totalAmount) }}</span>
                    </div>

                    <Button
                        label="Encaisser"
                        severity="contrast"
                        class="mt-2 w-full text-base font-semibold"
                    />
                </div>
            </aside>
        </div>
    </div>
</template>
