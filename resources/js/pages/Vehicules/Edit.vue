<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle, Save } from 'lucide-vue-next';
import { computed } from 'vue';
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

interface VehiculeData {
    id: number;
    nom_vehicule: string;
    immatriculation: string;
    type_vehicule: string | null;
    categorie: string | null;
    capacite_packs: number | null;
    proprietaire_id: number | null;
    pris_en_charge_par_usine: boolean;
    photo_url: string | null;
    is_active: boolean;
}

const props = defineProps<{
    vehicule: VehiculeData;
    proprietaires: Option[];
    types: TypeOption[];
    currentSiteName: string;
}>();
const page = usePage();
const flashSuccess = computed(
    () => (page.props as { flash?: { success?: string } }).flash?.success,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
    { title: props.vehicule.nom_vehicule, href: '#' },
];

const form = useForm({
    _method: 'PUT',
    nom_vehicule: props.vehicule.nom_vehicule,
    immatriculation: props.vehicule.immatriculation,
    type_vehicule: props.vehicule.type_vehicule,
    categorie: props.vehicule.categorie,
    capacite_packs: props.vehicule.capacite_packs,
    proprietaire_id: props.vehicule.proprietaire_id,
    pris_en_charge_par_usine: props.vehicule.pris_en_charge_par_usine,
    photo: null as File | null,
    is_active: props.vehicule.is_active,
});

const canSubmit = computed(() => {
    return (
        !form.processing &&
        !!form.categorie &&
        (form.categorie === 'interne' || !!form.proprietaire_id) &&
        form.nom_vehicule.trim().length > 0 &&
        form.immatriculation.trim().length > 0 &&
        !!form.type_vehicule
    );
});

function submit() {
    form.post(`/vehicules/${props.vehicule.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${vehicule.nom_vehicule}`" />

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
                        Modifier
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ vehicule.immatriculation }}
                    </p>
                </div>
            </div>
        </div>

        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
            <div class="hidden sm:block">
                <div class="mb-8">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Modifier le véhicule
                    </h1>
                    <p
                        class="mt-1 font-mono text-sm font-medium text-muted-foreground"
                    >
                        {{ vehicule.immatriculation }}
                    </p>
                </div>
            </div>

            <div
                v-if="flashSuccess"
                class="mb-4 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <VehiculeForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                :proprietaires="proprietaires"
                :types="types"
                :photo-url="vehicule.photo_url"
                :current-site-name="currentSiteName"
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
                :disabled="!canSubmit"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </div>
    </AppLayout>
</template>
