<script setup lang="ts">
import DetailHeader from '@/components/DetailHeader.vue';
import ImageLightbox from '@/components/ImageLightbox.vue';
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
    ExternalLink,
    Pencil,
    Plus,
    Receipt,
    UserRound,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
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

interface EquipeMembre {
    nom: string;
    telephone: string | null;
}

interface EquipeDetail {
    nom: string;
    taux_commission_proprietaire: number | null;
    chauffeur: EquipeMembre | null;
    convoyeurs: EquipeMembre[];
}

interface VehiculeRow {
    id: number;
    nom_vehicule: string;
    immatriculation: string | null;
    photo_url: string | null;
    type_label: string;
    capacite_packs: number | null;
    categorie: string | null;
    is_active: boolean;
    equipe_detail: EquipeDetail | null;
}

interface DepenseRow {
    id: string;
    libelle: string;
    montant: number;
    date_depense: string | null;
    statut: string;
    commentaire: string | null;
}

const props = defineProps<{
    proprietaire: ProprietaireData;
    vehicules: VehiculeRow[];
    depenses: DepenseRow[];
    can_create_vehicule: boolean;
}>();

const { can } = usePermissions();
const activeTab = ref<'informations' | 'vehicules' | 'depenses'>(
    'informations',
);

const statutLabel: Record<string, string> = {
    brouillon: 'Brouillon',
    soumis: 'Soumis',
    approuve: 'Approuvé',
    rejete: 'Rejeté',
};

const totalApprouve = computed(() =>
    props.depenses
        .filter((d) => d.statut === 'approuve')
        .reduce((s, d) => s + d.montant, 0),
);

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

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

function chauffeurCountLabel(equipe: EquipeDetail | null): string {
    const count = equipe?.chauffeur ? 1 : 0;
    return `${count} chauffeur${count > 1 ? 's' : ''}`;
}

function convoyeurCountLabel(equipe: EquipeDetail | null): string {
    const count = equipe?.convoyeurs.length ?? 0;
    return `${count} convoyeur${count > 1 ? 's' : ''}`;
}

const equipeDialogVisible = ref(false);
const selectedVehiculeForEquipe = ref<VehiculeRow | null>(null);

function openEquipeDialog(vehicule: VehiculeRow) {
    selectedVehiculeForEquipe.value = vehicule;
    equipeDialogVisible.value = true;
}

const lightboxUrl = ref<string | null>(null);
const lightboxAlt = ref('');

function openLightbox(url: string, alt: string) {
    lightboxUrl.value = url;
    lightboxAlt.value = alt;
}

function closeLightbox() {
    lightboxUrl.value = null;
}
</script>

<template>
    <Head :title="`${proprietaire.nom_complet} - Detail proprietaire`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Propriétaire"
                :title="proprietaire.nom_complet"
                :icon="UserRound"
                :status-label="proprietaire.is_active ? 'Actif' : 'Inactif'"
                :status-dot-class="
                    proprietaire.is_active
                        ? 'bg-emerald-500'
                        : 'bg-zinc-400 dark:bg-zinc-500'
                "
            >
                <template #subtitle>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ proprietaire.vehicules_count }} vehicule{{
                            proprietaire.vehicules_count > 1 ? 's' : ''
                        }}
                        rattache{{
                            proprietaire.vehicules_count > 1 ? 's' : ''
                        }}
                    </p>
                </template>
                <template #actions>
                    <Link href="/proprietaires">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

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
                        data-testid="owner-vehicles-tab"
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
                    <button
                        type="button"
                        data-testid="owner-depenses-tab"
                        class="mt-2 flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'depenses'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        @click="activeTab = 'depenses'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <Receipt class="h-4 w-4" />
                            Dépenses
                        </span>
                        <span
                            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px]"
                            :class="
                                activeTab === 'depenses'
                                    ? 'bg-white/20 text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ depenses.length }}
                        </span>
                    </button>
                </aside>

                <div
                    v-if="activeTab === 'informations'"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Informations du proprietaire
                        </h2>
                        <Link
                            v-if="can('proprietaires.update')"
                            :href="`/proprietaires/${proprietaire.id}/edit`"
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

                <div
                    v-else-if="activeTab === 'vehicules'"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                >
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
                            data-testid="add-owner-vehicle-btn"
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
                                    <th class="px-4 py-3 font-medium">Photo</th>
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
                                    <th class="px-4 py-3 font-medium">
                                        Action
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
                                        <div
                                            class="h-10 w-10 overflow-hidden rounded-lg border bg-muted"
                                            :class="
                                                vehicule.photo_url
                                                    ? 'cursor-zoom-in'
                                                    : ''
                                            "
                                            @click="
                                                vehicule.photo_url &&
                                                openLightbox(
                                                    vehicule.photo_url,
                                                    vehicule.nom_vehicule,
                                                )
                                            "
                                        >
                                            <img
                                                v-if="vehicule.photo_url"
                                                :src="vehicule.photo_url"
                                                :alt="vehicule.nom_vehicule"
                                                class="h-full w-full object-cover"
                                            />
                                            <div
                                                v-else
                                                class="flex h-full w-full items-center justify-center"
                                            >
                                                <Car
                                                    class="h-5 w-5 text-muted-foreground/40"
                                                />
                                            </div>
                                        </div>
                                    </td>
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
                                        <div class="flex items-center gap-1.5">
                                            <p>
                                                {{
                                                    chauffeurCountLabel(
                                                        vehicule.equipe_detail,
                                                    )
                                                }}
                                            </p>
                                            <button
                                                v-if="vehicule.equipe_detail"
                                                type="button"
                                                class="inline-flex shrink-0 items-center text-primary focus:outline-none"
                                                @click="
                                                    openEquipeDialog(vehicule)
                                                "
                                            >
                                                <ExternalLink
                                                    class="h-3.5 w-3.5"
                                                />
                                            </button>
                                        </div>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                convoyeurCountLabel(
                                                    vehicule.equipe_detail,
                                                )
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
                                    <td class="px-4 py-3">
                                        <Link
                                            v-if="can('vehicules.update')"
                                            :href="`/vehicules/${vehicule.id}/edit`"
                                        >
                                            <Button size="sm" variant="outline">
                                                <Pencil
                                                    class="mr-1.5 h-4 w-4"
                                                />
                                                Modifier
                                            </Button>
                                        </Link>
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >-</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Dépenses tab -->
                <div v-else class="rounded-xl border bg-card p-5 sm:p-6">
                    <div
                        class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Dépenses du propriétaire
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Frais opérationnels gérés via le module
                                Dépenses.
                            </p>
                        </div>
                        <span
                            v-if="totalApprouve > 0"
                            class="shrink-0 rounded-lg bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-700 tabular-nums"
                        >
                            Approuvés : {{ formatGNF(totalApprouve) }}
                        </span>
                    </div>

                    <div
                        v-if="!depenses.length"
                        class="rounded-lg border border-dashed py-10 text-center"
                    >
                        <p class="text-sm text-muted-foreground">
                            Aucune dépense enregistrée pour ce propriétaire.
                        </p>
                    </div>

                    <div v-else class="divide-y rounded-lg border">
                        <div
                            v-for="d in depenses"
                            :key="d.id"
                            class="flex items-center gap-4 px-4 py-3 hover:bg-muted/30"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold tabular-nums">
                                    {{ formatGNF(d.montant) }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ d.libelle }}
                                    <span v-if="d.commentaire">
                                        · {{ d.commentaire }}</span
                                    >
                                </div>
                            </div>
                            <div
                                class="hidden text-xs text-muted-foreground sm:block"
                            >
                                {{ d.date_depense ?? '—' }}
                            </div>
                            <StatusDot
                                :status="d.statut"
                                :label="statutLabel[d.statut] ?? d.statut"
                                class="shrink-0"
                            />
                            <Link
                                v-if="can('depenses.update')"
                                :href="`/depenses/${d.id}/edit`"
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                            >
                                <Pencil class="h-3.5 w-3.5" />
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <ImageLightbox
            :url="lightboxUrl"
            :alt="lightboxAlt"
            @close="closeLightbox"
        />

        <Dialog
            v-model:visible="equipeDialogVisible"
            modal
            header="Équipe de livraison"
            :style="{ width: '30rem' }"
        >
            <div class="space-y-4 px-1 py-2">
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Équipe</span>
                    <span class="text-sm font-medium">{{
                        selectedVehiculeForEquipe?.equipe_detail?.nom ?? '—'
                    }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Véhicule</span>
                    <span class="text-sm font-medium">{{
                        selectedVehiculeForEquipe?.nom_vehicule ?? '—'
                    }}</span>
                </div>
                <div
                    v-if="
                        selectedVehiculeForEquipe?.equipe_detail
                            ?.taux_commission_proprietaire != null
                    "
                    class="flex justify-between"
                >
                    <span class="text-sm text-muted-foreground"
                        >Taux propriétaire</span
                    >
                    <span class="text-sm font-medium"
                        >{{
                            selectedVehiculeForEquipe.equipe_detail
                                .taux_commission_proprietaire
                        }}
                        %</span
                    >
                </div>
                <div
                    v-if="selectedVehiculeForEquipe?.equipe_detail?.chauffeur"
                    class="border-t pt-3"
                >
                    <p
                        class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Chauffeur principal
                    </p>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{
                            selectedVehiculeForEquipe.equipe_detail.chauffeur
                                .nom
                        }}</span>
                        <span class="text-sm text-muted-foreground">
                            {{
                                formatPhoneDisplay(
                                    selectedVehiculeForEquipe.equipe_detail
                                        .chauffeur.telephone,
                                )
                            }}
                        </span>
                    </div>
                </div>
                <div
                    v-if="
                        selectedVehiculeForEquipe?.equipe_detail?.convoyeurs
                            ?.length
                    "
                    class="border-t pt-3"
                >
                    <p
                        class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Convoyeurs
                    </p>
                    <div
                        v-for="conv in selectedVehiculeForEquipe.equipe_detail
                            .convoyeurs"
                        :key="conv.nom"
                        class="flex items-center justify-between py-1"
                    >
                        <span class="text-sm font-medium">{{ conv.nom }}</span>
                        <span class="text-sm text-muted-foreground">{{
                            formatPhoneDisplay(conv.telephone)
                        }}</span>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    size="sm"
                    @click="equipeDialogVisible = false"
                    >Fermer</Button
                >
            </template>
        </Dialog>
    </AppLayout>
</template>
