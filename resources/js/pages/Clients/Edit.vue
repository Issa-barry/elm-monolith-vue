<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle,
    Gift,
    Pencil,
    Save,
    TrendingUp,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import ClientForm from './partials/ClientForm.vue';

interface ClientData {
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
    cashback_eligible: boolean;
}

interface CashbackSolde {
    cumul_achats: number;
    cashback_en_attente: number;
    total_cashback_gagne: number;
    total_cashback_verse: number;
}

const props = defineProps<{
    client: ClientData;
    cashback_solde: CashbackSolde | null;
}>();

const page = usePage();
const flashSuccess = computed(
    () => (page.props as any).flash?.success as string | undefined,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Clients', href: '/clients' },
    {
        title: `${props.client.prenom} ${props.client.nom}`,
        href: '#',
    },
];

const form = useForm({
    nom: props.client.nom,
    prenom: props.client.prenom,
    email: props.client.email,
    telephone: props.client.telephone,
    adresse: props.client.adresse,
    ville: props.client.ville,
    pays: props.client.pays,
    code_pays: props.client.code_pays,
    code_phone_pays: props.client.code_phone_pays,
    is_active: Boolean(props.client.is_active),
    cashback_eligible: Boolean(props.client.cashback_eligible),
});

watch(
    () => props.client,
    (c) => {
        form.defaults({
            nom: c.nom,
            prenom: c.prenom,
            email: c.email,
            telephone: c.telephone,
            adresse: c.adresse,
            ville: c.ville,
            pays: c.pays,
            code_pays: c.code_pays,
            code_phone_pays: c.code_phone_pays,
            is_active: Boolean(c.is_active),
            cashback_eligible: Boolean(c.cashback_eligible),
        }).reset();
    },
);

function submit() {
    form.put(`/clients/${props.client.id}`);
}

const isReadOnly = ref(true);

function enableEditing() {
    isReadOnly.value = false;
}

function formatMontant(v: number): string {
    return new Intl.NumberFormat('fr-GN').format(v) + ' GNF';
}
</script>

<template>
    <Head :title="`Voir — ${client.prenom} ${client.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/clients"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Voir
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ client.prenom }} {{ client.nom }}
                    </p>
                </div>
                <button
                    v-if="isReadOnly"
                    type="button"
                    class="absolute right-4 inline-flex h-9 items-center gap-1.5 rounded-md border px-3 text-xs font-medium text-foreground"
                    @click="enableEditing"
                >
                    <Pencil class="h-3.5 w-3.5" />
                    Modifier
                </button>
            </div>
        </div>

        <div class="mx-auto max-w-2xl pb-6 sm:p-6">
            <div class="mx-auto hidden max-w-2xl px-6 pt-6 pb-0 sm:block">
                <div class="mb-8 flex items-start justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">
                            Voir le client
                        </h1>
                        <p
                            class="mt-1 text-sm font-medium text-muted-foreground"
                        >
                            {{ client.prenom }} {{ client.nom }}
                        </p>
                    </div>
                    <Button
                        v-if="isReadOnly"
                        type="button"
                        variant="outline"
                        class="gap-2"
                        @click="enableEditing"
                    >
                        <Pencil class="h-4 w-4" />
                        Modifier
                    </Button>
                </div>
            </div>

            <div
                v-if="flashSuccess"
                class="mx-6 mb-4 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <!-- Widget cashback (affiché uniquement si le module est actif) -->
            <div
                v-if="cashback_solde !== null"
                class="mx-6 mb-6 overflow-hidden rounded-xl border bg-card"
            >
                <div
                    class="flex items-center gap-2 border-b bg-muted/30 px-4 py-2.5"
                >
                    <Gift class="h-4 w-4 text-primary" />
                    <span class="text-sm font-semibold">Cashback</span>
                </div>
                <div class="grid grid-cols-2 divide-x sm:grid-cols-4">
                    <div class="px-4 py-3 text-center">
                        <p class="text-xs text-muted-foreground">
                            Cumul achats
                        </p>
                        <p class="mt-0.5 text-sm font-semibold">
                            {{ formatMontant(cashback_solde.cumul_achats) }}
                        </p>
                    </div>
                    <div class="px-4 py-3 text-center">
                        <p class="text-xs text-muted-foreground">En attente</p>
                        <p
                            class="mt-0.5 text-sm font-semibold"
                            :class="
                                cashback_solde.cashback_en_attente > 0
                                    ? 'text-amber-600'
                                    : ''
                            "
                        >
                            {{
                                formatMontant(
                                    cashback_solde.cashback_en_attente,
                                )
                            }}
                        </p>
                    </div>
                    <div class="px-4 py-3 text-center">
                        <p class="text-xs text-muted-foreground">Total gagné</p>
                        <p class="mt-0.5 text-sm font-semibold text-primary">
                            {{
                                formatMontant(
                                    cashback_solde.total_cashback_gagne,
                                )
                            }}
                        </p>
                    </div>
                    <div class="px-4 py-3 text-center">
                        <p class="text-xs text-muted-foreground">Total versé</p>
                        <p class="mt-0.5 text-sm font-semibold text-green-600">
                            {{
                                formatMontant(
                                    cashback_solde.total_cashback_verse,
                                )
                            }}
                        </p>
                    </div>
                </div>
                <div
                    v-if="cashback_solde.cashback_en_attente > 0"
                    class="border-t bg-amber-50 px-4 py-2 text-xs text-amber-700"
                >
                    <TrendingUp class="mr-1 inline h-3 w-3" />
                    Ce client a un cashback de
                    <strong>{{
                        formatMontant(cashback_solde.cashback_en_attente)
                    }}</strong>
                    à verser.
                    <a
                        href="/cashback"
                        class="ml-1 underline hover:no-underline"
                        >Gérer →</a
                    >
                </div>
            </div>

            <ClientForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                :readonly="isReadOnly"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>

        <!-- Footer sticky mobile -->
        <div
            v-if="!isReadOnly"
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="client-form"
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
