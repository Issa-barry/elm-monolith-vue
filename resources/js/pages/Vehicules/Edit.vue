<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import VehiculeForm from './partials/VehiculeForm.vue';

interface Option { value: number | string; label: string }
interface TypeOption { value: string; label: string; capacite_defaut: number }

interface VehiculeData {
    id: number;
    nom_vehicule: string;
    immatriculation: string;
    type_vehicule: string | null;
    capacite_packs: number | null;
    proprietaire_id: number | null;
    livreur_principal_id: number | null;
    pris_en_charge_par_usine: boolean;
    taux_commission_livreur: number | null;
    commission_active: boolean;
    photo_url: string | null;
    is_active: boolean;
}

const props = defineProps<{
    vehicule: VehiculeData;
    proprietaires: Option[];
    livreurs: Option[];
    types: TypeOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
    { title: props.vehicule.nom_vehicule, href: '#' },
];

const form = useForm({
    _method:                  'PUT',
    nom_vehicule:             props.vehicule.nom_vehicule,
    immatriculation:          props.vehicule.immatriculation,
    type_vehicule:            props.vehicule.type_vehicule,
    capacite_packs:           props.vehicule.capacite_packs,
    proprietaire_id:          props.vehicule.proprietaire_id,
    livreur_principal_id:     props.vehicule.livreur_principal_id,
    pris_en_charge_par_usine: props.vehicule.pris_en_charge_par_usine,
    taux_commission_livreur:  props.vehicule.taux_commission_livreur,
    commission_active:        props.vehicule.commission_active,
    photo:                    null as File | null,
    is_active:                props.vehicule.is_active,
});

function submit() {
    form.post(`/vehicules/${props.vehicule.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${vehicule.nom_vehicule}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1280px] p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Modifier le véhicule</h1>
                <p class="mt-1 text-sm font-medium text-muted-foreground font-mono">{{ vehicule.immatriculation }}</p>
            </div>

            <VehiculeForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                :proprietaires="proprietaires"
                :livreurs="livreurs"
                :types="types"
                :photo-url="vehicule.photo_url"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>
    </AppLayout>
</template>
