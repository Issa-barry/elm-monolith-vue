<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Spinner } from '@/components/ui/spinner';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import PackingForm from './partials/PackingForm.vue';

interface Option { value: number; label: string }

const props = defineProps<{
    prestataires: Option[];
    prix_defaut?: number;
    statuts: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Packings', href: '/packings' },
    { title: 'Nouveau packing', href: '/packings/create' },
];

const form = useForm({
    prestataire_id:   null as number | null,
    date:             new Date().toISOString().slice(0, 10),
    nb_rouleaux:      null as number | null,
    prix_par_rouleau: props.prix_defaut ?? 0,
    notes:            null as string | null,
});

function submit() {
    form.post('/packings');
}
</script>

<template>
    <Head title="Nouveau packing" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden">
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link href="/packings" class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95">
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] font-semibold leading-tight">Nouveau packing</h1>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-7xl pb-6 sm:p-6">
            <div class="hidden sm:block mx-auto max-w-7xl px-6 pt-6 pb-0">
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold tracking-tight">Nouveau packing</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Enregistrez un nouveau packing pour votre organisation.
                    </p>
                </div>
            </div>

            <PackingForm
                :form="form"
                :errors="form.errors"
                :prestataires="prestataires"
                :processing="form.processing"
                @update:form="Object.assign(form, $event)"
                @submit="submit"
            />
        </div>

        <!-- Footer sticky mobile -->
        <div class="fixed bottom-0 left-0 right-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden">
            <button type="submit" form="packing-form" :disabled="form.processing" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60">
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Créer le packing' }}
            </button>
        </div>
    </AppLayout>
</template>
