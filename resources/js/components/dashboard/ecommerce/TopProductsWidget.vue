<script setup lang="ts">
import { ProductService } from '@/service/ProductService';
import { onMounted, ref } from 'vue';

const products = ref([]);

onMounted(() => {
    ProductService.getProductsSmall().then((data) => (products.value = data));
});
</script>

<template>
    <div class="card h-full">
        <div
            class="text-surface-900 dark:text-surface-0 mb-4 text-xl font-semibold"
        >
            Top Products
        </div>
        <ul class="m-0 list-none p-0">
            <template v-for="(product, i) in products" :key="{ i }">
                <li v-if="i < 6" class="flex items-center justify-between p-4">
                    <div class="inline-flex items-center">
                        <img
                            :src="`/demo/images/product/${product.image}`"
                            :alt="product.name"
                            width="75"
                            class="flex-shrink-0 shadow"
                        />
                        <div class="ml-4 flex flex-col">
                            <span class="mb-1 text-lg font-medium">{{
                                product.name
                            }}</span>
                            <Rating
                                v-model="product.rating"
                                readonly
                                :cancel="false"
                            ></Rating>
                        </div>
                    </div>
                    <span class="p-text-secondary ml-auto text-xl font-semibold"
                        >${{ product.price }}</span
                    >
                </li>
            </template>
        </ul>
    </div>
</template>
