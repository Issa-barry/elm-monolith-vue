<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

interface Client {
    id: number;
    nom: string;
    prenom: string | null;
    email: string | null;
    telephone: string | null;
    adresse: string | null;
    is_active: boolean;
}

const props = defineProps<{ client: Client }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Clients', href: '/clients' },
    { title: `${props.client.nom}`, href: '#' },
];
</script>

<template>
    <Head>
        <title>{{ client.nom }} {{ client.prenom ?? '' }}</title>
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-4 sm:p-6">
            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                <h1 class="mb-4 text-xl font-semibold">
                    {{ client.nom }} {{ client.prenom }}
                </h1>
                <dl class="space-y-2 text-sm">
                    <div v-if="client.email" class="flex gap-4">
                        <dt class="w-28 text-muted-foreground">Email</dt>
                        <dd>{{ client.email }}</dd>
                    </div>
                    <div v-if="client.telephone" class="flex gap-4">
                        <dt class="w-28 text-muted-foreground">Téléphone</dt>
                        <dd>{{ client.telephone }}</dd>
                    </div>
                    <div v-if="client.adresse" class="flex gap-4">
                        <dt class="w-28 text-muted-foreground">Adresse</dt>
                        <dd>{{ client.adresse }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </AppLayout>
</template>
