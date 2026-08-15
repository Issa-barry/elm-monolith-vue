<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { paysOptionsByCode } from '@/lib/pays';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle,
    Gift,
    Save,
    TrendingUp,
} from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import { computed, reactive, watch } from 'vue';
import ClientForm from './partials/ClientForm.vue';

const PAYS_OPTIONS = paysOptionsByCode;

interface ClientData {
    id: number;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    adresse: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    code_phone_pays: string | null;
    is_active: boolean;
    type: string;
    type_label: string;
    cashback_eligible: boolean;
}

interface CashbackSolde {
    cumul_achats: number;
    cashback_en_attente: number;
    total_cashback_gagne: number;
    total_cashback_verse: number;
}

interface TypeOption {
    value: string;
    label: string;
}

interface ClientVehicule {
    id: number;
    nom_vehicule: string | null;
    immatriculation: string | null;
    chauffeur_nom: string | null;
    chauffeur_telephone: string | null;
    chauffeur_code_pays: string | null;
}

const props = defineProps<{
    client: ClientData;
    types: TypeOption[];
    vehicules: ClientVehicule[];
    cashback_solde: CashbackSolde | null;
}>();

const page = usePage();
const flashSuccess = computed(
    () => (page.props as any).flash?.success as string | undefined,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Clients', href: '/backoffice/clients' },
    {
        title: props.client.nom_complet,
        href: '#',
    },
];

const form = useForm({
    nom_complet: props.client.nom_complet,
    email: props.client.email,
    telephone: props.client.telephone,
    adresse: props.client.adresse,
    ville: props.client.ville,
    pays: props.client.pays,
    code_pays: props.client.code_pays,
    code_phone_pays: props.client.code_phone_pays,
    is_active: Boolean(props.client.is_active),
    type: props.client.type,
    cashback_eligible: Boolean(props.client.cashback_eligible),
});

watch(
    () => props.client,
    (c) => {
        form.defaults({
            nom_complet: c.nom_complet,
            email: c.email,
            telephone: c.telephone,
            adresse: c.adresse,
            ville: c.ville,
            pays: c.pays,
            code_pays: c.code_pays,
            code_phone_pays: c.code_phone_pays,
            is_active: Boolean(c.is_active),
            type: c.type,
            cashback_eligible: Boolean(c.cashback_eligible),
        }).reset();
    },
);

function submit() {
    form.put(`/backoffice/clients/${props.client.id}`);
}

function formatMontant(v: number): string {
    return new Intl.NumberFormat('fr-GN').format(v) + ' GNF';
}

// ── Véhicules externes — toujours facultatifs (cf. ClientVehicle) ───────────
const isExterne = computed(() => props.client.type === 'externe');
const showVehiculeForm = reactive({ open: false });
const vehiculeForm = useForm({
    nom_vehicule: '',
    immatriculation: '',
    chauffeur_nom: '',
    chauffeur_telephone: '',
    chauffeur_code_pays: 'GN',
});

function submitVehicule() {
    vehiculeForm.post(`/backoffice/clients/${props.client.id}/vehicules`, {
        onSuccess: () => {
            vehiculeForm.reset();
            showVehiculeForm.open = false;
        },
    });
}

function destroyVehicule(vehiculeId: number) {
    router.delete(
        `/backoffice/clients/${props.client.id}/vehicules/${vehiculeId}`,
    );
}
</script>

<template>
    <Head :title="`Modifier — ${client.nom_complet}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/backoffice/clients"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Modifier
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ client.nom_complet }}
                    </p>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-2xl pb-6 sm:p-6">
            <div class="mx-auto hidden max-w-2xl px-6 pt-6 pb-0 sm:block">
                <div class="mb-8">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Modifier le client
                    </h1>
                    <p class="mt-1 text-sm font-medium text-muted-foreground">
                        {{ client.nom_complet }}
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
                        href="/backoffice/cashback"
                        class="ml-1 underline hover:no-underline"
                        >Gérer →</a
                    >
                </div>
            </div>

            <ClientForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                :types="types"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />

            <!-- Véhicules externes : toujours facultatifs, jamais un prérequis -->
            <div
                v-if="isExterne"
                class="mx-6 mt-4 rounded-xl border bg-card p-4 shadow-sm sm:mx-0 sm:p-6"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Véhicules externes
                    </h3>
                    <button
                        type="button"
                        class="text-xs font-medium text-primary hover:underline"
                        @click="showVehiculeForm.open = !showVehiculeForm.open"
                    >
                        {{ showVehiculeForm.open ? 'Annuler' : '+ Ajouter' }}
                    </button>
                </div>

                <p
                    v-if="vehicules.length === 0 && !showVehiculeForm.open"
                    class="text-sm text-muted-foreground"
                >
                    Aucun véhicule renseigné — facultatif, ce client peut
                    commander sans véhicule.
                </p>

                <ul v-else class="mb-3 space-y-2">
                    <li
                        v-for="v in vehicules"
                        :key="v.id"
                        class="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                    >
                        <div>
                            <span class="font-medium">{{
                                v.nom_vehicule || 'Véhicule'
                            }}</span>
                            <span
                                v-if="v.immatriculation"
                                class="ml-2 font-mono text-xs text-muted-foreground"
                                >{{ v.immatriculation }}</span
                            >
                            <span
                                v-if="v.chauffeur_nom"
                                class="ml-2 text-xs text-muted-foreground"
                                >— {{ v.chauffeur_nom }}</span
                            >
                        </div>
                        <button
                            type="button"
                            class="text-xs text-destructive hover:underline"
                            @click="destroyVehicule(v.id)"
                        >
                            Retirer
                        </button>
                    </li>
                </ul>

                <form
                    v-if="showVehiculeForm.open"
                    class="grid gap-3 border-t pt-3 sm:grid-cols-2"
                    @submit.prevent="submitVehicule"
                >
                    <input
                        v-model="vehiculeForm.nom_vehicule"
                        type="text"
                        placeholder="Nom du véhicule (facultatif)"
                        class="rounded-md border bg-background px-3 py-2 text-sm"
                    />
                    <input
                        v-model="vehiculeForm.immatriculation"
                        type="text"
                        placeholder="Immatriculation (facultatif)"
                        class="rounded-md border bg-background px-3 py-2 text-sm"
                    />
                    <input
                        v-model="vehiculeForm.chauffeur_nom"
                        type="text"
                        placeholder="Chauffeur (facultatif)"
                        class="rounded-md border bg-background px-3 py-2 text-sm"
                    />
                    <div class="flex gap-2">
                        <Dropdown
                            v-model="vehiculeForm.chauffeur_code_pays"
                            :options="PAYS_OPTIONS"
                            option-label="code"
                            option-value="value"
                            class="w-24 shrink-0"
                        >
                            <template #value="{ value }">
                                <span>{{ value || 'GN' }}</span>
                            </template>
                            <template #option="{ option }">
                                <span
                                    >{{ option.code }} ({{ option.dial }})</span
                                >
                            </template>
                        </Dropdown>
                        <input
                            v-model="vehiculeForm.chauffeur_telephone"
                            type="text"
                            placeholder="Téléphone chauffeur (facultatif)"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <p
                        v-if="vehiculeForm.errors.chauffeur_telephone"
                        class="text-xs text-destructive sm:col-span-2"
                    >
                        {{ vehiculeForm.errors.chauffeur_telephone }}
                    </p>
                    <div class="sm:col-span-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="vehiculeForm.processing"
                        >
                            Enregistrer le véhicule
                        </Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Footer sticky mobile -->
        <div
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
