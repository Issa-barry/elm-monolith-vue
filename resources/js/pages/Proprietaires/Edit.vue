<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle, Save } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import ProprietaireForm from './partials/ProprietaireForm.vue';

interface ProprietaireData {
    id: number;
    nom: string;
    prenom: string;
    email: string | null;
    telephone: string | null;
    adresse: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    code_phone_pays: string | null;
    is_active: boolean;
}

const props = defineProps<{ proprietaire: ProprietaireData }>();
const page = usePage();
const flashSuccess = computed(
    () => (page.props as any).flash?.success as string | undefined,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Propriétaires', href: '/proprietaires' },
    {
        title: `${props.proprietaire.prenom} ${props.proprietaire.nom}`,
        href: '#',
    },
];

const form = useForm({
    nom: props.proprietaire.nom,
    prenom: props.proprietaire.prenom,
    email: props.proprietaire.email,
    telephone: props.proprietaire.telephone,
    adresse: props.proprietaire.adresse,
    ville: props.proprietaire.ville,
    pays: props.proprietaire.pays,
    code_pays: props.proprietaire.code_pays,
    code_phone_pays: props.proprietaire.code_phone_pays,
    is_active: Boolean(props.proprietaire.is_active),
});

watch(
    () => props.proprietaire,
    (p) => {
        Object.assign(form, {
            nom: p.nom,
            prenom: p.prenom,
            email: p.email,
            telephone: p.telephone,
            adresse: p.adresse,
            ville: p.ville,
            pays: p.pays,
            code_pays: p.code_pays,
            code_phone_pays: p.code_phone_pays,
            is_active: Boolean(p.is_active),
        });
    },
    { deep: true },
);

function updateForm(data: Omit<ProprietaireData, 'id'>) {
    form.nom = data.nom;
    form.prenom = data.prenom;
    form.email = data.email;
    form.telephone = data.telephone;
    form.adresse = data.adresse;
    form.ville = data.ville;
    form.pays = data.pays;
    form.code_pays = data.code_pays;
    form.code_phone_pays = data.code_phone_pays;
    form.is_active = data.is_active;
}

function submit() {
    form.put(`/proprietaires/${props.proprietaire.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${proprietaire.prenom} ${proprietaire.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/proprietaires"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Modifier
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ proprietaire.prenom }} {{ proprietaire.nom }}
                    </p>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-2xl pb-6 sm:p-6">
            <div class="mx-auto hidden max-w-2xl px-6 pt-6 pb-0 sm:block">
                <div class="mb-8">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Modifier le propriétaire
                    </h1>
                    <p class="mt-1 text-sm font-medium text-muted-foreground">
                        {{ proprietaire.prenom }} {{ proprietaire.nom }}
                    </p>
                </div>
            </div>

            <div
                v-if="flashSuccess"
                class="mx-6 mb-4 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <ProprietaireForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                @submit="submit"
                @update:form="updateForm"
            />
        </div>

        <!-- Footer sticky mobile -->
        <div
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="proprietaire-form"
                :disabled="form.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </div>
    </AppLayout>
</template>
