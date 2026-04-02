<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle, Save } from 'lucide-vue-next';
import { computed } from 'vue';
import LivreurForm from './partials/LivreurForm.vue';

interface LivreurData {
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

const props = defineProps<{ livreur: LivreurData }>();
const page = usePage();
const flashSuccess = computed(
    () => (page.props as any).flash?.success as string | undefined,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Livreurs', href: '/livreurs' },
    { title: `${props.livreur.prenom} ${props.livreur.nom}`, href: '#' },
];

const form = useForm({
    nom: props.livreur.nom,
    prenom: props.livreur.prenom,
    email: props.livreur.email,
    telephone: props.livreur.telephone,
    adresse: props.livreur.adresse,
    ville: props.livreur.ville,
    pays: props.livreur.pays,
    code_pays: props.livreur.code_pays,
    code_phone_pays: props.livreur.code_phone_pays,
    is_active: Boolean(props.livreur.is_active),
});

function submit() {
    form.put(`/livreurs/${props.livreur.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${livreur.prenom} ${livreur.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/livreurs"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Modifier
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ livreur.prenom }} {{ livreur.nom }}
                    </p>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-2xl pb-6 sm:p-6">
            <div class="mx-auto hidden max-w-2xl px-6 pt-6 pb-0 sm:block">
                <div class="mb-8">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Modifier le livreur
                    </h1>
                    <p class="mt-1 text-sm font-medium text-muted-foreground">
                        {{ livreur.prenom }} {{ livreur.nom }}
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

            <LivreurForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
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
                form="livreur-form"
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
