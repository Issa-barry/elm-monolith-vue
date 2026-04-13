<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import EquipeForm from './partials/EquipeForm.vue';

interface MembreData {
    livreur_id: number | null;
    nom: string;
    prenom: string;
    telephone: string;
    role: string;
    taux_commission: number;
    ordre: number;
}

interface EquipeData {
    id: number;
    nom: string;
    is_active: boolean;
    vehicule_id: number | null;
    proprietaire_id: number | null;
    taux_commission_proprietaire: number | null;
    membres: MembreData[];
}

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
}

const props = defineProps<{
    equipe: EquipeData;
    proprietaires: ProprietaireOption[];
    vehicules: VehiculeOption[];
    tauxProprietaireDefaut: number;
}>();

const page = usePage();
const flashSuccess = computed(
    () => (page.props as any).flash?.success as string | undefined,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Équipes', href: '/equipes-livraison' },
    { title: props.equipe.nom, href: '#' },
];

const form = useForm({
    nom: props.equipe.nom,
    is_active: Boolean(props.equipe.is_active),
    vehicule_id: props.equipe.vehicule_id,
    proprietaire_id: props.equipe.proprietaire_id,
    taux_commission_proprietaire:
        props.equipe.taux_commission_proprietaire ??
        props.tauxProprietaireDefaut,
    membres: props.equipe.membres.map((m) => ({
        livreur_id: m.livreur_id,
        nom: m.nom,
        prenom: m.prenom,
        telephone: m.telephone,
        role: m.role,
        taux_commission: m.taux_commission,
        ordre: m.ordre,
    })),
});

function submit() {
    form.patch(`/equipes-livraison/${props.equipe.id}`);
}
</script>

<template>
    <Head>
        <title>Équipe {{ equipe.nom }}</title>
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
                    {{ equipe.nom }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Modifier le véhicule, les membres et taux.
                </p>
            </div>
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <EquipeForm
                :form="form"
                :proprietaires="proprietaires"
                :vehicules="vehicules"
                :taux-proprietaire-defaut="tauxProprietaireDefaut"
                @submit="submit"
            />
        </div>
    </AppLayout>
</template>
