<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import VehiculeForm from './partials/VehiculeForm.vue';

interface Option {
    value: number | string;
    label: string;
}
interface TypeOption {
    value: string;
    label: string;
    capacite_defaut: number;
}

const props = defineProps<{
    proprietaires: Option[];
    livreurs: Option[];
    types: TypeOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
    { title: 'Nouveau véhicule', href: '#' },
];

const form = useForm({
    nom_vehicule: '',
    immatriculation: '',
    type_vehicule: null as string | null,
    capacite_packs: null as number | null,
    proprietaire_id: null as number | null,
    livreur_principal_id: null as number | null,
    pris_en_charge_par_usine: false,
    taux_commission_livreur: null as number | null,
    taux_commission_proprietaire: null as number | null,
    photo: null as File | null,
    is_active: true,
});

function submit() {
    form.post('/vehicules');
}
</script>

<template>
    <Head title="Nouveau véhicule" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/vehicules"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Nouveau véhicule
                    </h1>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-3xl pb-6 sm:p-6">
            <div class="mx-auto hidden max-w-3xl px-6 pt-6 pb-0 sm:block">
                <div class="mb-8">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Nouveau véhicule
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Ajoutez un véhicule à votre flotte.
                    </p>
                </div>
            </div>

            <VehiculeForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                :proprietaires="proprietaires"
                :livreurs="livreurs"
                :types="types"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>

        <!-- Footer sticky mobile -->
        <div
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="vehicule-form"
                :disabled="form.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Créer le véhicule' }}
            </button>
        </div>
    </AppLayout>
</template>
