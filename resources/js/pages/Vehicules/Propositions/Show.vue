<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle,
    CheckCircle2,
    Clock,
    MessageSquare,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface PropositionData {
    id: string;
    nom_contact: string | null;
    telephone_contact: string | null;
    nom_vehicule: string | null;
    marque: string | null;
    modele: string | null;
    immatriculation: string;
    type_vehicule: string | null;
    capacite_packs: number | null;
    commentaire: string | null;
    photo_url: string | null;
    statut: string | null;
    statut_label: string;
    statut_color: string;
    decision_note: string | null;
    traitee_at_label: string | null;
    traitee_par_nom: string | null;
    created_at_label: string | null;
    user_name: string | null;
    proprietaire_nom: string | null;
    is_terminal: boolean;
}

interface DoublonData {
    id: string;
    nom_vehicule: string | null;
    immatriculation: string;
}

const props = defineProps<{
    proposition: PropositionData;
    vehicule_doublon: DoublonData | null;
}>();

const page = usePage();
const flashSuccess = computed(
    () => (page.props as { flash?: { success?: string } }).flash?.success,
);
const errors = computed(() => (page.props as any).errors ?? {});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Véhicules', href: '/backoffice/vehicules' },
    { title: 'Propositions', href: '/backoffice/vehicules/propositions' },
    { title: props.proposition.immatriculation, href: '#' },
];

const colorClasses: Record<string, string> = {
    amber: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    blue: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    orange: 'bg-orange-500/15 text-orange-700 dark:text-orange-300',
    red: 'bg-red-500/15 text-red-700 dark:text-red-300',
    emerald: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    gray: 'bg-gray-500/15 text-gray-700 dark:text-gray-300',
};

// Modale pour les actions nécessitant une note
const showModal = ref<'complement' | 'rejeter' | null>(null);
const noteInput = ref('');
const isSubmitting = ref(false);

function openModal(type: 'complement' | 'rejeter') {
    noteInput.value = '';
    showModal.value = type;
}

function closeModal() {
    showModal.value = null;
    noteInput.value = '';
}

function priseEnCharge() {
    router.patch(
        `/backoffice/vehicules/propositions/${props.proposition.id}/prendre-en-charge`,
        {},
        { preserveScroll: true },
    );
}

function submitNote() {
    if (!noteInput.value.trim() || !showModal.value) return;
    isSubmitting.value = true;

    const url =
        showModal.value === 'complement'
            ? `/backoffice/vehicules/propositions/${props.proposition.id}/demander-complement`
            : `/backoffice/vehicules/propositions/${props.proposition.id}/rejeter`;

    router.patch(
        url,
        { decision_note: noteInput.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isSubmitting.value = false;
                closeModal();
            },
        },
    );
}

function valider() {
    if (
        !confirm(
            'Confirmer la conversion de cette proposition en véhicule réel ?',
        )
    )
        return;

    router.post(
        `/backoffice/vehicules/propositions/${props.proposition.id}/valider`,
        {},
        { preserveScroll: false },
    );
}
</script>

<template>
    <Head :title="`Proposition — ${proposition.immatriculation}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
            <!-- Retour + titre -->
            <div class="flex items-center gap-3">
                <Link
                    href="/backoffice/vehicules/propositions"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div>
                    <h1 class="text-xl font-semibold">
                        Proposition
                        <span class="font-mono">{{
                            proposition.immatriculation
                        }}</span>
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Soumise le {{ proposition.created_at_label ?? '—' }}
                        <span v-if="proposition.user_name">
                            par {{ proposition.user_name }}
                        </span>
                    </p>
                </div>
                <span
                    class="ml-auto rounded-full px-3 py-1 text-xs font-semibold"
                    :class="
                        colorClasses[proposition.statut_color] ??
                        colorClasses['gray']
                    "
                >
                    {{ proposition.statut_label }}
                </span>
            </div>

            <!-- Flash success -->
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-300"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <!-- Alerte doublon -->
            <div
                v-if="vehicule_doublon"
                class="flex items-start gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-700 dark:bg-red-950 dark:text-red-300"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <p class="font-semibold">Doublon détecté</p>
                    <p class="mt-0.5">
                        Un véhicule avec l'immatriculation
                        <span class="font-mono font-bold">{{
                            vehicule_doublon.immatriculation
                        }}</span>
                        existe déjà ({{ vehicule_doublon.nom_vehicule }}). La
                        conversion est bloquée.
                    </p>
                </div>
            </div>

            <!-- Erreur de conversion -->
            <div
                v-if="errors.conversion"
                class="rounded-xl border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
            >
                {{ errors.conversion }}
            </div>

            <!-- Infos contact / partenaire -->
            <div class="rounded-xl border bg-card p-5 shadow-sm">
                <h2
                    class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Contact / Partenaire
                </h2>
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-muted-foreground">Nom</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ proposition.nom_contact ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Téléphone</dt>
                        <dd class="mt-0.5 font-mono">
                            {{ proposition.telephone_contact ?? '—' }}
                        </dd>
                    </div>
                    <div v-if="proposition.proprietaire_nom">
                        <dt class="text-xs text-muted-foreground">
                            Propriétaire existant
                        </dt>
                        <dd
                            class="mt-0.5 font-medium text-emerald-700 dark:text-emerald-400"
                        >
                            {{ proposition.proprietaire_nom }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Infos véhicule -->
            <div class="rounded-xl border bg-card p-5 shadow-sm">
                <h2
                    class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Véhicule proposé
                </h2>
                <div class="flex gap-6">
                    <div v-if="proposition.photo_url" class="shrink-0">
                        <img
                            :src="proposition.photo_url"
                            alt="Photo du véhicule"
                            class="h-32 w-32 rounded-xl object-cover"
                        />
                    </div>
                    <dl class="grid flex-1 gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-muted-foreground">Nom</dt>
                            <dd class="mt-0.5 font-medium">
                                {{ proposition.nom_vehicule ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Immatriculation
                            </dt>
                            <dd class="mt-0.5 font-mono font-bold uppercase">
                                {{ proposition.immatriculation }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Type</dt>
                            <dd class="mt-0.5">
                                {{ proposition.type_vehicule ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Capacité (packs)
                            </dt>
                            <dd class="mt-0.5">
                                {{ proposition.capacite_packs ?? '—' }}
                            </dd>
                        </div>
                        <div v-if="proposition.marque || proposition.modele">
                            <dt class="text-xs text-muted-foreground">
                                Marque / Modèle
                            </dt>
                            <dd class="mt-0.5">
                                {{ proposition.marque ?? '' }}
                                {{ proposition.modele ?? '' }}
                            </dd>
                        </div>
                        <div
                            v-if="proposition.commentaire"
                            class="sm:col-span-2"
                        >
                            <dt class="text-xs text-muted-foreground">
                                Commentaire
                            </dt>
                            <dd class="mt-0.5 text-sm">
                                {{ proposition.commentaire }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Note de décision existante -->
            <div
                v-if="proposition.decision_note"
                class="rounded-xl border bg-card p-5 shadow-sm"
            >
                <h2
                    class="mb-3 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Note de décision
                </h2>
                <p class="text-sm">{{ proposition.decision_note }}</p>
                <p
                    v-if="proposition.traitee_at_label"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    Par {{ proposition.traitee_par_nom ?? '—' }} le
                    {{ proposition.traitee_at_label }}
                </p>
            </div>

            <!-- Actions backoffice -->
            <div
                v-if="!proposition.is_terminal"
                class="rounded-xl border bg-card p-5 shadow-sm"
            >
                <h2
                    class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Actions
                </h2>
                <div class="flex flex-wrap gap-3">
                    <!-- Prendre en charge -->
                    <button
                        v-if="proposition.statut === 'soumise'"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-blue-300 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-300 dark:hover:bg-blue-900"
                        @click="priseEnCharge"
                    >
                        <Clock class="h-4 w-4" />
                        Prendre en charge
                    </button>

                    <!-- Demander complément -->
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-orange-300 bg-orange-50 px-4 py-2 text-sm font-medium text-orange-700 hover:bg-orange-100 dark:border-orange-700 dark:bg-orange-950 dark:text-orange-300 dark:hover:bg-orange-900"
                        @click="openModal('complement')"
                    >
                        <MessageSquare class="h-4 w-4" />
                        Demander un complément
                    </button>

                    <!-- Rejeter -->
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 dark:border-red-700 dark:bg-red-950 dark:text-red-300 dark:hover:bg-red-900"
                        @click="openModal('rejeter')"
                    >
                        <XCircle class="h-4 w-4" />
                        Rejeter
                    </button>

                    <!-- Valider / Convertir -->
                    <button
                        type="button"
                        :disabled="!!vehicule_doublon"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="valider"
                    >
                        <CheckCircle2 class="h-4 w-4" />
                        Valider &amp; Convertir
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Modale note de décision -->
    <Teleport to="body">
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeModal"
        >
            <div
                class="w-full max-w-md rounded-xl border bg-card p-6 shadow-xl"
            >
                <h3 class="mb-4 text-lg font-semibold">
                    {{
                        showModal === 'complement'
                            ? 'Demander un complément'
                            : 'Rejeter la proposition'
                    }}
                </h3>
                <label class="mb-1 block text-sm font-medium">
                    {{
                        showModal === 'complement'
                            ? 'Message au partenaire'
                            : 'Motif de rejet'
                    }}
                    <span class="text-destructive">*</span>
                </label>
                <textarea
                    v-model="noteInput"
                    rows="4"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    :placeholder="
                        showModal === 'complement'
                            ? 'Précisez ce que le partenaire doit compléter…'
                            : 'Expliquez pourquoi la proposition est rejetée…'
                    "
                />
                <div class="mt-4 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-md border px-4 py-2 text-sm hover:bg-muted"
                        @click="closeModal"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        :disabled="!noteInput.trim() || isSubmitting"
                        class="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        :class="
                            showModal === 'complement'
                                ? 'bg-orange-600 hover:bg-orange-700'
                                : 'bg-red-600 hover:bg-red-700'
                        "
                        @click="submitNote"
                    >
                        {{ isSubmitting ? 'Envoi…' : 'Confirmer' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
