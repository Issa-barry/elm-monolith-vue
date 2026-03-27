<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Spinner } from '@/components/ui/spinner';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import PackingForm from './partials/PackingForm.vue';

interface Option { value: number; label: string }

interface PackingData {
    id: number;
    reference: string;
    prestataire_id: number;
    date: string;
    nb_rouleaux: number;
    prix_par_rouleau: number;
    montant: number;
    notes: string | null;
    statut: string;
    can_edit: boolean;
    can_cancel: boolean;
}

const props = defineProps<{
    packing: PackingData;
    prestataires: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Packings', href: '/packings' },
    { title: props.packing.reference, href: `/packings/${props.packing.id}` },
    { title: 'Modifier', href: '#' },
];

const form = useForm({
    prestataire_id:   props.packing.prestataire_id,
    date:             props.packing.date,
    nb_rouleaux:      props.packing.nb_rouleaux,
    prix_par_rouleau: props.packing.prix_par_rouleau,
    notes:            props.packing.notes,
});

function submit() {
    form.put(`/packings/${props.packing.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${packing.reference}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden">
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link href="/packings" class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95">
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] font-semibold leading-tight">Modifier</h1>
                    <p class="text-[11px] text-muted-foreground">{{ packing.reference }}</p>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-7xl pb-6 sm:p-6">
            <div class="hidden sm:block mx-auto max-w-7xl px-6 pt-6 pb-0">
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold tracking-tight">Modifier le packing</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        <span class="font-mono text-xs font-semibold">{{ packing.reference }}</span>
                    </p>
                </div>
            </div>

            <PackingForm
                :form="form"
                :errors="form.errors"
                :prestataires="prestataires"
                :processing="form.processing"
                :reference="packing.reference"
                @update:form="Object.assign(form, $event)"
                @submit="submit"
            />
        </div>

        <!-- Footer sticky mobile -->
        <div class="fixed bottom-0 left-0 right-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden">
            <button type="submit" form="packing-form" :disabled="form.processing" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60">
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </div>
    </AppLayout>
</template>
