<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import VehiculeForm from './partials/VehiculeForm.vue';

interface Option { value: number | string; label: string }
interface TypeOption { value: string; label: string; capacite_defaut: number }

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
    nom_vehicule:             '',
    marque:                   null as string | null,
    modele:                   null as string | null,
    immatriculation:          '',
    type_vehicule:            null as string | null,
    capacite_packs:           null as number | null,
    proprietaire_id:          null as number | null,
    livreur_principal_id:     null as number | null,
    pris_en_charge_par_usine: false,
    taux_commission_livreur:  null as number | null,
    commission_active:        false,
    photo:                    null as File | null,
    is_active:                true,
});

function submit() {
    form.post('/vehicules');
}
</script>

<template>
    <Head title="Nouveau véhicule" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Nouveau véhicule</h1>
                <p class="mt-1 text-sm text-muted-foreground">Ajoutez un véhicule à votre flotte.</p>
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
    </AppLayout>
</template>
