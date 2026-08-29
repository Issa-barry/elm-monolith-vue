<script setup lang="ts">
import DerogationImpayesCard from '@/components/DerogationImpayesCard.vue';
import DetailHeader from '@/components/DetailHeader.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF, formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleHelp,
    Gift,
    Pencil,
    TrendingUp,
    UserRound,
} from 'lucide-vue-next';
import Toast from 'primevue/toast';
import { computed, ref } from 'vue';

interface ClientData {
    id: number;
    nom_complet: string;
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
    cashback_montant_par_pack: number | null;
    derogation_impayes_autorisee: boolean;
    seuil_derogation_impayes: number | null;
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
    seuil_global_impayes: number;
}>();

const { can } = usePermissions();
const activeTab = ref<'informations' | 'cashback'>('informations');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Clients', href: '/backoffice/clients' },
    { title: props.client.nom_complet, href: '#' },
];

const locationLabel = computed(() => {
    const address = (props.client.adresse ?? '').trim();
    const city = (props.client.ville ?? '').trim();

    if (!address && !city) return '—';
    if (!address) return city;
    if (!city) return address;

    return address + ', ' + city;
});

function flagUrl(code: string): string {
    return 'https://flagcdn.com/20x15/' + code.toLowerCase() + '.png';
}
</script>

<template>
    <Head :title="client.nom_complet + ' — Détail client'" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Client"
                :title="client.nom_complet"
                :icon="UserRound"
                :status-label="client.is_active ? 'Actif' : 'Inactif'"
                :status-dot-class="
                    client.is_active
                        ? 'bg-emerald-500'
                        : 'bg-zinc-400 dark:bg-zinc-500'
                "
                data-testid="client-detail-header"
            >
                <template #subtitle>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium"
                            data-testid="client-type-badge"
                        >
                            {{ client.type_label }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{
                                formatPhoneDisplay(
                                    client.telephone,
                                    client.code_phone_pays,
                                )
                            }}
                        </span>
                    </div>
                </template>

                <template #actions>
                    <Link href="/backoffice/clients">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Liste des clients
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <aside
                    class="grid h-fit grid-cols-2 gap-2 rounded-xl border bg-card p-2 lg:block"
                    aria-label="Sections de la fiche client"
                    data-testid="client-detail-navigation"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'informations'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        data-testid="client-informations-tab"
                        @click="activeTab = 'informations'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <CircleHelp class="h-4 w-4" />
                            Informations
                        </span>
                    </button>

                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors lg:mt-2"
                        :class="
                            activeTab === 'cashback'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        data-testid="client-cashback-tab"
                        @click="activeTab = 'cashback'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <Gift class="h-4 w-4" />
                            Cashback
                        </span>
                    </button>
                </aside>

                <div
                    v-if="activeTab === 'informations'"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                    data-testid="client-informations-panel"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Informations du client
                        </h2>
                        <Link
                            v-if="can('clients.update')"
                            :href="'/backoffice/clients/' + client.id + '/edit'"
                            data-testid="client-edit-button"
                        >
                            <Button size="sm" variant="outline">
                                <Pencil class="mr-1.5 h-4 w-4" />
                                Modifier
                            </Button>
                        </Link>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Nom complet
                            </p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="client-name"
                            >
                                {{ client.nom_complet }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Nature du client
                            </p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="client-type"
                            >
                                {{ client.type_label }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Téléphone
                            </p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="client-phone"
                            >
                                {{
                                    formatPhoneDisplay(
                                        client.telephone,
                                        client.code_phone_pays,
                                    )
                                }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Localisation
                            </p>
                            <div class="mt-1 flex items-center gap-2">
                                <img
                                    v-if="client.code_pays"
                                    :src="flagUrl(client.code_pays)"
                                    :alt="client.pays ?? 'Pays du client'"
                                    class="h-4 w-auto rounded-sm shadow-sm"
                                />
                                <p
                                    class="text-sm font-medium"
                                    data-testid="client-location"
                                >
                                    {{ locationLabel }}
                                </p>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ client.pays || 'Pays non renseigné' }}
                            </p>
                        </div>

                        <DerogationImpayesCard
                            class="sm:col-span-2"
                            :active="client.derogation_impayes_autorisee"
                            :seuil="client.seuil_derogation_impayes"
                            :seuil-global="seuil_global_impayes"
                            :update-url="
                                '/backoffice/clients/' +
                                client.id +
                                '/derogation-impayes'
                            "
                            :can-update="can('clients.update')"
                            entite-label="ce client"
                        />
                    </div>
                </div>

                <div
                    v-else
                    class="space-y-6"
                    data-testid="client-cashback-panel"
                >
                    <div
                        v-if="cashback_solde !== null"
                        class="rounded-xl border bg-card p-5 sm:p-6"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Synthèse cashback
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Vue d'ensemble des achats et des gains de ce
                                client.
                            </p>
                        </div>

                        <div
                            class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                        >
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Cumul des achats
                                </p>
                                <p
                                    class="mt-1 text-lg font-semibold tabular-nums"
                                    data-testid="cashback-purchases-total"
                                >
                                    {{ formatGNF(cashback_solde.cumul_achats) }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    En attente
                                </p>
                                <p
                                    class="mt-1 text-lg font-semibold tabular-nums"
                                    :class="
                                        cashback_solde.cashback_en_attente > 0
                                            ? 'text-amber-600 dark:text-amber-400'
                                            : ''
                                    "
                                    data-testid="cashback-pending-total"
                                >
                                    {{
                                        formatGNF(
                                            cashback_solde.cashback_en_attente,
                                        )
                                    }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Total gagné
                                </p>
                                <p
                                    class="mt-1 text-lg font-semibold text-primary tabular-nums"
                                    data-testid="cashback-earned-total"
                                >
                                    {{
                                        formatGNF(
                                            cashback_solde.total_cashback_gagne,
                                        )
                                    }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Total versé
                                </p>
                                <p
                                    class="mt-1 text-lg font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                                    data-testid="cashback-paid-total"
                                >
                                    {{
                                        formatGNF(
                                            cashback_solde.total_cashback_verse,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="cashback_solde.cashback_en_attente > 0"
                            class="mt-4 flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                            data-testid="cashback-pending-alert"
                        >
                            <p class="flex items-start gap-2">
                                <TrendingUp class="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    <strong>{{
                                        formatGNF(
                                            cashback_solde.cashback_en_attente,
                                        )
                                    }}</strong>
                                    de cashback sont à verser à ce client.
                                </span>
                            </p>
                            <Link
                                href="/backoffice/cashback"
                                class="shrink-0 font-medium underline underline-offset-4 hover:no-underline"
                            >
                                Gérer le cashback
                            </Link>
                        </div>
                    </div>

                    <div
                        v-else
                        class="rounded-xl border bg-card p-5 sm:p-6"
                        data-testid="cashback-module-disabled"
                    >
                        <div
                            class="flex min-h-40 flex-col items-center justify-center rounded-lg border border-dashed p-6 text-center"
                        >
                            <Gift class="h-8 w-8 text-muted-foreground/60" />
                            <h2 class="mt-3 text-sm font-semibold">
                                Module cashback désactivé
                            </h2>
                            <p
                                class="mt-1 max-w-md text-sm text-muted-foreground"
                            >
                                La synthèse cashback n'est pas disponible pour
                                cette organisation.
                            </p>
                        </div>
                    </div>

                    <div class="rounded-xl border bg-card p-5 sm:p-6">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2
                                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Configuration cashback
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Paramétrage actuellement appliqué à ce
                                    client.
                                </p>
                            </div>
                            <Link
                                v-if="can('clients.update')"
                                :href="
                                    '/backoffice/clients/' + client.id + '/edit'
                                "
                                data-testid="client-cashback-edit-button"
                            >
                                <Button size="sm" variant="outline">
                                    <Pencil class="mr-1.5 h-4 w-4" />
                                    Modifier
                                </Button>
                            </Link>
                        </div>

                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Éligible au cashback
                                </p>
                                <p
                                    class="mt-1 text-sm font-medium"
                                    data-testid="cashback-eligibility"
                                >
                                    {{
                                        client.cashback_eligible ? 'Oui' : 'Non'
                                    }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Montant par pack
                                </p>
                                <p
                                    class="mt-1 text-sm font-medium tabular-nums"
                                    data-testid="cashback-amount-per-pack"
                                >
                                    {{
                                        client.cashback_eligible &&
                                        client.cashback_montant_par_pack !==
                                            null
                                            ? formatGNF(
                                                  client.cashback_montant_par_pack,
                                              )
                                            : '—'
                                    }}
                                </p>
                            </div>
                        </div>

                        <p class="mt-4 text-xs text-muted-foreground">
                            Les montants déjà gagnés conservent leur valeur
                            historique ; ce paramétrage concerne les prochains
                            gains.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>

    <Toast group="top" position="top-right" />
</template>
