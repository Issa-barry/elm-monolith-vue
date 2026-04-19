<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Car,
    CircleHelp,
    Pencil,
    Plus,
    UserRound,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ProprietaireData {
    id: number;
    nom: string;
    prenom: string;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    adresse: string | null;
    is_active: boolean;
    vehicules_count: number;
}

interface VehiculeRow {
    id: number;
    nom_vehicule: string;
    immatriculation: string | null;
    type_label: string;
    capacite_packs: number | null;
    categorie: string | null;
    is_active: boolean;
    equipe_nom: string | null;
    livreur_principal_nom: string | null;
}

const props = defineProps<{
    proprietaire: ProprietaireData;
    vehicules: VehiculeRow[];
    can_create_vehicule: boolean;
}>();

const { can } = usePermissions();
const activeTab = ref<'informations' | 'vehicules'>('informations');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Proprietaires', href: '/proprietaires' },
    { title: props.proprietaire.nom_complet, href: '#' },
];

const locationLabel = computed(() => {
    const address = (props.proprietaire.adresse ?? '').trim();
    const city = (props.proprietaire.ville ?? '').trim();
    if (!address && !city) {
        return '-';
    }
    if (!address) {
        return city;
    }
    if (!city) {
        return address;
    }

    return `${address}, ${city}`;
});

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}
</script>

<template>
    <Head :title="`${proprietaire.nom_complet} - Detail proprietaire`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex items-start gap-4">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-full bg-primary text-primary-foreground"
                    >
                        <UserRound class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-semibold tracking-tight">
                                {{ proprietaire.nom_complet }}
                            </h1>
                            <StatusDot
                                :label="
                                    proprietaire.is_active ? 'Actif' : 'Inactif'
                                "
                                :dot-class="
                                    proprietaire.is_active
                                        ? 'bg-emerald-500'
                                        : 'bg-zinc-400 dark:bg-zinc-500'
                                "
                                class="text-sm text-muted-foreground"
                            />
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ proprietaire.vehicules_count }} vehicule{{
                                proprietaire.vehicules_count > 1 ? 's' : ''
                            }}
                            rattache{{
                                proprietaire.vehicules_count > 1 ? 's' : ''
                            }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Link href="/proprietaires">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                    <Link
                        v-if="can('proprietaires.update')"
                        :href="`/proprietaires/${proprietaire.id}/edit`"
                    >
                        <Button size="sm">
                            <Pencil class="mr-1.5 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <aside class="h-fit rounded-xl border bg-card p-2">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'informations'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        @click="activeTab = 'informations'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <CircleHelp class="h-4 w-4" />
                            Informations
                        </span>
                    </button>
                    <button
                        type="button"
                        class="mt-2 flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'vehicules'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        @click="activeTab = 'vehicules'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <Car class="h-4 w-4" />
                            Vehicules
                        </span>
                        <span
                            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px]"
                            :class="
                                activeTab === 'vehicules'
                                    ? 'bg-white/20 text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ vehicules.length }}
                        </span>
                    </button>
                </aside>

                <div
                    v-if="activeTab === 'informations'"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                >
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Informations du proprietaire
                    </h2>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Nom complet
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{ proprietaire.nom_complet }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Telephone
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{
                                    formatPhoneDisplay(
                                        proprietaire.telephone,
                                        proprietaire.code_phone_pays,
                                    ) ?? '-'
                                }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">Email</p>
                            <p class="mt-1 text-sm font-medium">
                                {{ proprietaire.email ?? '-' }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Localisation
                            </p>
                            <div class="mt-1 flex items-center gap-2">
                                <img
                                    v-if="proprietaire.code_pays"
                                    :src="flagUrl(proprietaire.code_pays)"
                                    class="h-4 w-auto rounded-sm shadow-sm"
                                />
                                <p class="text-sm font-medium">
                                    {{ locationLabel }}
                                </p>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ proprietaire.pays ?? '-' }}
                            </p>
                        </div>
                    </div>
                </div>

                <div v-else class="rounded-xl border bg-card p-5 sm:p-6">
                    <div
                        class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Liste des vehicules
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ vehicules.length }} vehicule{{
                                    vehicules.length > 1 ? 's' : ''
                                }}
                            </p>
                        </div>
                        <Link
                            v-if="can_create_vehicule"
                            :href="`/vehicules/create?proprietaire_id=${proprietaire.id}`"
                        >
                            <Button size="sm">
                                <Plus class="mr-1.5 h-4 w-4" />
                                Ajouter un vehicule
                            </Button>
                        </Link>
                    </div>

                    <div
                        v-if="vehicules.length === 0"
                        class="rounded-lg border border-dashed py-10 text-center"
                    >
                        <Car
                            class="mx-auto h-10 w-10 text-muted-foreground/30"
                        />
                        <p class="mt-3 text-sm text-muted-foreground">
                            Aucun vehicule rattache a ce proprietaire.
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-sm">
                            <thead
                                class="bg-muted/30 text-left text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-4 py-3 font-medium">
                                        Vehicule
                                    </th>
                                    <th class="px-4 py-3 font-medium">
                                        Immatriculation
                                    </th>
                                    <th class="px-4 py-3 font-medium">Type</th>
                                    <th class="px-4 py-3 font-medium">
                                        Capacite
                                    </th>
                                    <th class="px-4 py-3 font-medium">
                                        Equipe
                                    </th>
                                    <th class="px-4 py-3 font-medium">
                                        Statut
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="vehicule in vehicules"
                                    :key="vehicule.id"
                                    class="hover:bg-muted/20"
                                >
                                    <td class="px-4 py-3">
                                        <Link
                                            v-if="can('vehicules.read')"
                                            :href="`/vehicules/${vehicule.id}`"
                                            class="font-medium hover:underline"
                                        >
                                            {{ vehicule.nom_vehicule }}
                                        </Link>
                                        <span v-else class="font-medium">{{
                                            vehicule.nom_vehicule
                                        }}</span>
                                        <p
                                            class="text-xs text-muted-foreground capitalize"
                                        >
                                            {{ vehicule.categorie ?? '-' }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-4 py-3 font-mono text-muted-foreground"
                                    >
                                        {{ vehicule.immatriculation ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ vehicule.type_label }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{
                                            vehicule.capacite_packs !== null
                                                ? `${vehicule.capacite_packs} packs`
                                                : '-'
                                        }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <p>{{ vehicule.equipe_nom ?? '-' }}</p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                vehicule.livreur_principal_nom ??
                                                'Sans livreur principal'
                                            }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <StatusDot
                                            :label="
                                                vehicule.is_active
                                                    ? 'Actif'
                                                    : 'Inactif'
                                            "
                                            :dot-class="
                                                vehicule.is_active
                                                    ? 'bg-emerald-500'
                                                    : 'bg-zinc-400 dark:bg-zinc-500'
                                            "
                                            class="text-muted-foreground"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
