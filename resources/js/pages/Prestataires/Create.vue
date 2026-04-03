<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import PrestataireForm from './partials/PrestataireForm.vue';

interface Option {
    value: string;
    label: string;
}

defineProps<{ types: Option[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Prestataires', href: '/prestataires' },
    { title: 'Nouveau prestataire', href: '#' },
];

const form = useForm({
    nom: null as string | null,
    prenom: null as string | null,
    raison_sociale: null as string | null,
    email: null as string | null,
    phone: null as string | null,
    code_phone_pays: '+224',
    code_pays: 'GN',
    pays: 'Guinée',
    ville: null as string | null,
    adresse: null as string | null,
    type: 'fournisseur',
    notes: null as string | null,
    is_active: true,
});

function submit() {
    form.post('/prestataires');
}

function handleFormUpdate(updated: Record<string, unknown>) {
    const changed = Object.keys(updated).filter(
        (k) => (form as Record<string, unknown>)[k] !== updated[k],
    );
    Object.assign(form, updated);
    if (changed.length) form.clearErrors(...changed);
}
</script>

<template>
    <Head title="Nouveau prestataire" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/prestataires"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Nouveau prestataire
                    </h1>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-2xl pb-6 sm:p-6">
            <div class="mx-auto hidden max-w-2xl px-6 pt-6 pb-0 sm:block">
                <div class="mb-8">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Nouveau prestataire
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Ajoutez un prestataire à votre organisation.
                    </p>
                </div>
            </div>

            <PrestataireForm
                :form="form"
                :errors="form.errors"
                :types="types"
                :processing="form.processing"
                @submit="submit"
                @update:form="handleFormUpdate($event)"
            />
        </div>

        <!-- Footer sticky mobile -->
        <div
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="prestataire-form"
                :disabled="form.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{
                    form.processing ? 'Enregistrement…' : 'Créer le prestataire'
                }}
            </button>
        </div>
    </AppLayout>
</template>
