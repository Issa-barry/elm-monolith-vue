<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import EquipeForm from './partials/EquipeForm.vue';

interface ProprietaireOption {
    value: number;
    label: string;
    telephone?: string | null;
}

interface VehiculeOption {
    value: number;
    label: string;
    immatriculation: string;
    categorie: string;
    type_label: string;
    proprietaire_id: number | null;
    proprietaire_nom: string | null;
}

const props = defineProps<{
    proprietaires: ProprietaireOption[];
    vehicules: VehiculeOption[];
    tauxProprietaireDefaut: number;
    currentSiteName: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Équipes', href: '/equipes-livraison' },
    { title: 'Nouvelle équipe', href: '#' },
];

const form = useForm({
    nom: '',
    is_active: true,
    vehicule_id: null as number | null,
    proprietaire_id: null as number | null,
    taux_commission_proprietaire: props.tauxProprietaireDefaut as number | null,
    membres: [] as {
        livreur_id: number | null;
        nom: string;
        prenom: string;
        telephone: string;
        role: string;
        taux_commission: number;
        ordre: number;
    }[],
});

function submit() {
    form.post('/equipes-livraison');
}
</script>

<template>
    <Head>
        <title>Nouvelle équipe</title>
    </Head>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
            <div class="hidden sm:block">
                <Link
                    href="/equipes-livraison"
                    class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="h-4 w-4" /> Équipes
                </Link>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Nouvelle équipe
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Définissez le véhicule, les membres et leurs taux.
                </p>
            </div>
            <EquipeForm
                :form="form"
                :proprietaires="proprietaires"
                :vehicules="vehicules"
                :taux-proprietaire-defaut="tauxProprietaireDefaut"
                :current-site-name="currentSiteName"
                @submit="submit"
            />
        </div>
    </AppLayout>
</template>
