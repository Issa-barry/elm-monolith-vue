<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Spinner } from '@/components/ui/spinner';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import SiteForm from './partials/SiteForm.vue';

interface Option { value: number | string; label: string }

interface SiteData {
    id: number;
    nom: string;
    code: string;
    type: string | null;
    statut: string | null;
    localisation: string | null;
    pays: string | null;
    ville: string | null;
    description: string | null;
    parent_id: number | null;
    latitude: number | null;
    longitude: number | null;
    telephone: string | null;
    email: string | null;
}

const props = defineProps<{
    site: SiteData;
    types: Option[];
    statuts: Option[];
    parentOptions: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Sites', href: '/sites' },
    { title: props.site.nom, href: '#' },
];

const form = useForm({
    _method:      'PUT',
    nom:          props.site.nom,
    code:         props.site.code,
    type:         props.site.type,
    statut:       props.site.statut,
    localisation: props.site.localisation,
    pays:         props.site.pays,
    ville:        props.site.ville,
    description:  props.site.description,
    parent_id:    props.site.parent_id,
    latitude:     props.site.latitude,
    longitude:    props.site.longitude,
    telephone:    props.site.telephone,
    email:        props.site.email,
});

function submit() {
    form.post(`/sites/${props.site.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${site.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden">
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link href="/sites" class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95">
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] font-semibold leading-tight">Modifier</h1>
                    <p class="text-[11px] text-muted-foreground">{{ site.nom }}</p>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-2xl pb-6 sm:p-6">
            <div class="hidden sm:block mx-auto max-w-2xl px-6 pt-6 pb-0">
                <div class="mb-8">
                    <h1 class="text-2xl font-semibold tracking-tight">Modifier le site</h1>
                    <p class="mt-1 font-mono text-sm font-medium text-muted-foreground">{{ site.code }}</p>
                </div>
            </div>

            <SiteForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                :types="types"
                :statuts="statuts"
                :parent-options="parentOptions"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>

        <!-- Footer sticky mobile -->
        <div class="fixed bottom-0 left-0 right-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden">
            <button type="submit" form="site-form" :disabled="form.processing" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60">
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </div>
    </AppLayout>
</template>
