<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
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
    capacite_defaut_bouteilles: number | null;
}
interface SiteOption {
    id: string;
    nom: string;
}

const props = defineProps<{
    proprietaires: Option[];
    types: TypeOption[];
    initial_proprietaire_id: string | null;
    sites: SiteOption[];
    default_site_id: string | null;
    can_change_site: boolean;
    default_proprietaire_id: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Véhicules', href: '/backoffice/vehicules' },
    { title: 'Nouveau véhicule', href: '#' },
];

const form = useForm({
    nom_vehicule: '',
    immatriculation: '',
    type_vehicule_id: null as string | null,
    capacite_packs: null as number | null,
    capacite_bouteilles: null as number | null,
    site_id: props.default_site_id,
    proprietaire_id: props.initial_proprietaire_id ?? props.default_proprietaire_id,
    // Par défaut, un nouveau véhicule sert à la vente — l'admin coche
    // logistique en plus si besoin (cf. Vehicule::$livraison_vente par défaut).
    livraison_vente: true,
    livraison_logistique: false,
    photo: null as File | null,
    is_active: true,
});

const canSubmit = computed(() => {
    return (
        !form.processing &&
        !!form.site_id &&
        form.nom_vehicule.trim().length > 0 &&
        form.immatriculation.trim().length > 0 &&
        !!form.type_vehicule_id &&
        (form.livraison_vente || form.livraison_logistique)
    );
});

function submit() {
    form.post('/backoffice/vehicules');
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
                    href="/backoffice/vehicules"
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

        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
            <div class="hidden sm:block">
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
                :types="types"
                :sites="sites"
                :can-change-site="can_change_site"
                :default-proprietaire-id="default_proprietaire_id"
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
                {{ form.processing ? 'Enregistrement…' : 'Créer le véhicule' }}
            </button>
        </div>
    </AppLayout>
</template>
