<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Spinner } from '@/components/ui/spinner';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import PrestataireForm from './partials/PrestataireForm.vue';

interface Option { value: string; label: string }

interface PrestataireData {
    id: number;
    reference: string;
    nom: string | null;
    prenom: string | null;
    raison_sociale: string | null;
    email: string | null;
    phone: string | null;
    code_phone_pays: string;
    code_pays: string;
    pays: string;
    ville: string | null;
    adresse: string | null;
    type: string;
    notes: string | null;
    is_active: boolean;
}

const props = defineProps<{ prestataire: PrestataireData; types: Option[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Prestataires', href: '/prestataires' },
    { title: props.prestataire.reference, href: '#' },
];

const form = useForm({
    nom: props.prestataire.nom,
    prenom: props.prestataire.prenom,
    raison_sociale: props.prestataire.raison_sociale,
    email: props.prestataire.email,
    phone: props.prestataire.phone,
    code_phone_pays: props.prestataire.code_phone_pays,
    code_pays: props.prestataire.code_pays,
    pays: props.prestataire.pays,
    ville: props.prestataire.ville,
    adresse: props.prestataire.adresse,
    type: props.prestataire.type,
    notes: props.prestataire.notes,
    is_active: props.prestataire.is_active,
});

function submit() {
    form.put(`/prestataires/${props.prestataire.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${prestataire.reference}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden">
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link href="/prestataires" class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95">
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] font-semibold leading-tight">Modifier</h1>
                    <p class="text-[11px] text-muted-foreground">{{ prestataire.reference }}</p>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-2xl pb-6 sm:p-6">
            <div class="hidden sm:block mx-auto max-w-2xl px-6 pt-6 pb-0">
                <div class="mb-8">
                    <h1 class="text-2xl font-semibold tracking-tight">Modifier le prestataire</h1>
                    <p class="mt-1 text-sm text-muted-foreground font-mono">{{ prestataire.reference }}</p>
                </div>
            </div>

            <PrestataireForm
                :form="form"
                :errors="form.errors"
                :types="types"
                :processing="form.processing"
                :reference="prestataire.reference"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>

        <!-- Footer sticky mobile -->
        <div class="fixed bottom-0 left-0 right-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden">
            <button type="submit" form="prestataire-form" :disabled="form.processing" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60">
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </div>
    </AppLayout>
</template>
