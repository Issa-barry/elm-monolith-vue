<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
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
    membres: MembreData[];
}

const props = defineProps<{ equipe: EquipeData }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Équipes', href: '/equipes-livraison' },
    { title: props.equipe.nom, href: '#' },
];

const form = useForm({
    nom: props.equipe.nom,
    is_active: Boolean(props.equipe.is_active),
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
                    Modifier les membres et taux.
                </p>
            </div>
            <EquipeForm :form="form" @submit="submit" />
        </div>
    </AppLayout>
</template>
