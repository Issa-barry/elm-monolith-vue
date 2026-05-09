<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import type {
    ActorPayload,
    TypeVehiculeOption,
    VehiculeOption,
} from '@/types/client-space';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Car, Lock, Plus, X } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{
    actor: ActorPayload;
    owner_vehicules: VehiculeOption[];
    type_vehicule_options: TypeVehiculeOption[];
}>();

const page = usePage();
const flash = computed(
    () => (page.props as { flash?: { success?: string } }).flash,
);

const lightboxUrl = ref<string | null>(null);
const lightboxAlt = ref('');
const showProposalModal = ref(false);

const form = useForm({
    nom_vehicule: '',
    marque: '',
    modele: '',
    immatriculation: '',
    type_vehicule: '',
    capacite_packs: null as number | null,
    commentaire: '',
    photo: null as File | null,
});

const photoPreview = ref<string | null>(null);

function onPhotoChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.photo = file;
    photoPreview.value = file ? URL.createObjectURL(file) : null;
}

function openLightbox(url: string, alt: string) {
    lightboxUrl.value = url;
    lightboxAlt.value = alt;
}

function closeLightbox() {
    lightboxUrl.value = null;
}

function openProposalModal() {
    showProposalModal.value = true;
}

function closeProposalModal() {
    if (form.processing) {
        return;
    }
    showProposalModal.value = false;
    form.clearErrors();
}

function onTypeVehiculeChange() {
    const selectedType = props.type_vehicule_options.find(
        (option) => option.value === form.type_vehicule,
    );

    if (selectedType) {
        form.capacite_packs = selectedType.capacite_defaut;
    }
}

function submitProposal() {
    form.post('/client/propositions-vehicules', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            showProposalModal.value = false;
            photoPreview.value = null;
            form.reset();
            form.clearErrors();
        },
        onError: () => {
            showProposalModal.value = true;
        },
    });
}

function onKeydown(e: KeyboardEvent) {
    if (e.key !== 'Escape') {
        return;
    }

    if (showProposalModal.value) {
        closeProposalModal();

        return;
    }

    closeLightbox();
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

onMounted(() => {
    const query = new URLSearchParams(window.location.search);
    if (query.get('openProposal') === '1') {
        openProposalModal();
        query.delete('openProposal');
        const cleanUrl = `${window.location.pathname}${query.toString() ? `?${query.toString()}` : ''}${window.location.hash}`;
        window.history.replaceState({}, '', cleanUrl);
    }
});
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Vehicules" />

        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-semibold">Mes vehicules</h1>
                <button
                    type="button"
                    class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
                    @click="openProposalModal"
                >
                    <Plus class="mr-2 h-4 w-4" />
                    Proposer un vehicule
                </button>
            </div>

            <div
                v-if="flash?.success"
                class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
            >
                {{ flash.success }}
            </div>

            <div
                v-if="!actor.proprietaire_id"
                class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300"
            >
                Votre compte n'est pas lie a un profil proprietaire.
            </div>

            <div v-else class="rounded-xl border border-border bg-card p-5">
                <div
                    v-if="owner_vehicules.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Aucun vehicule proprietaire trouve pour le moment.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-muted-foreground"
                            >
                                <th class="py-2 pr-4 font-medium">Photo</th>
                                <th class="py-2 pr-4 font-medium">
                                    Nom vehicule
                                </th>
                                <th class="py-2 pr-4 font-medium">
                                    Immatriculation
                                </th>
                                <th class="py-2 pr-4 font-medium">Type</th>
                                <th class="py-2 pr-4 font-medium">
                                    Capacite (packs)
                                </th>
                                <th class="py-2 pr-0 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="vehicule in owner_vehicules"
                                :key="vehicule.id"
                                class="border-b border-border/70"
                            >
                                <td class="py-2 pr-4">
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
                                <td class="py-2 pr-4">
                                    {{ vehicule.nom_vehicule }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ vehicule.immatriculation ?? '-' }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ vehicule.type_label }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ vehicule.capacite_packs ?? '-' }}
                                </td>
                                <td class="py-2 pr-0">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium"
                                        :class="
                                            vehicule.is_active
                                                ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                                                : 'bg-zinc-500/15 text-zinc-700 dark:text-zinc-300'
                                        "
                                    >
                                        {{
                                            vehicule.is_active
                                                ? 'Actif'
                                                : 'Inactif'
                                        }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="showProposalModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/65 p-4"
                @click.self="closeProposalModal"
            >
                <div
                    class="w-full max-w-3xl rounded-xl border border-border bg-card p-5 shadow-2xl"
                >
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold">
                            Proposer un vehicule
                        </h2>
                        <button
                            type="button"
                            class="rounded-full p-1 text-muted-foreground transition-colors hover:bg-muted"
                            @click="closeProposalModal"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="space-y-4" @submit.prevent="submitProposal">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label
                                    class="text-sm font-medium text-foreground"
                                    >Nom du vehicule</label
                                >
                                <input
                                    v-model="form.nom_vehicule"
                                    type="text"
                                    class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                    placeholder="Ex: Camion Matoto 1"
                                />
                                <p
                                    v-if="form.errors.nom_vehicule"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ form.errors.nom_vehicule }}
                                </p>
                            </div>

                            <div>
                                <label
                                    class="text-sm font-medium text-foreground"
                                    >Immatriculation
                                    <span class="text-red-500">*</span></label
                                >
                                <input
                                    v-model="form.immatriculation"
                                    type="text"
                                    class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm uppercase"
                                    placeholder="RC-001-GN"
                                />
                                <p
                                    v-if="form.errors.immatriculation"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ form.errors.immatriculation }}
                                </p>
                            </div>

                            <div>
                                <label
                                    class="text-sm font-medium text-foreground"
                                    >Marque</label
                                >
                                <input
                                    v-model="form.marque"
                                    type="text"
                                    class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                    placeholder="Ex: Hyundai"
                                />
                            </div>

                            <div>
                                <label
                                    class="text-sm font-medium text-foreground"
                                    >Modele</label
                                >
                                <input
                                    v-model="form.modele"
                                    type="text"
                                    class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                    placeholder="Ex: Porter"
                                />
                            </div>

                            <div>
                                <label
                                    class="text-sm font-medium text-foreground"
                                    >Type de vehicule
                                    <span class="text-red-500">*</span></label
                                >
                                <select
                                    v-model="form.type_vehicule"
                                    class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                    @change="onTypeVehiculeChange"
                                >
                                    <option disabled value="">
                                        Selectionner un type
                                    </option>
                                    <option
                                        v-for="option in type_vehicule_options"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <p
                                    v-if="form.errors.type_vehicule"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ form.errors.type_vehicule }}
                                </p>
                            </div>

                            <div>
                                <label
                                    class="text-sm font-medium text-foreground"
                                    >Capacite (packs)</label
                                >
                                <div class="relative mt-1">
                                    <input
                                        :value="form.capacite_packs ?? ''"
                                        type="number"
                                        readonly
                                        tabindex="-1"
                                        class="w-full cursor-not-allowed rounded-md border border-border bg-muted px-3 py-2 pr-9 text-sm text-muted-foreground select-none"
                                        placeholder="Défini par le type"
                                    />
                                    <Lock
                                        class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground/60"
                                    />
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-foreground"
                                >Photo du vehicule
                                <span class="text-red-500">*</span></label
                            >
                            <div
                                class="mt-1 flex cursor-pointer flex-col items-center justify-center rounded-md border-2 border-dashed border-border bg-background px-4 py-5 transition hover:border-primary/50"
                                @click="
                                    (
                                        $refs.photoInput as HTMLInputElement
                                    ).click()
                                "
                            >
                                <img
                                    v-if="photoPreview"
                                    :src="photoPreview"
                                    class="mb-3 max-h-40 rounded-md object-contain"
                                    alt="Apercu"
                                />
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        form.photo
                                            ? form.photo.name
                                            : 'Cliquez pour choisir une image (JPG, PNG, WEBP — max 5 Mo)'
                                    }}
                                </p>
                            </div>
                            <input
                                ref="photoInput"
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="onPhotoChange"
                            />
                            <p
                                v-if="form.errors.photo"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.photo }}
                            </p>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-foreground"
                                >Commentaire</label
                            >
                            <textarea
                                v-model="form.commentaire"
                                rows="3"
                                class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                placeholder="Informations utiles"
                            />
                            <p
                                v-if="form.errors.commentaire"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.commentaire }}
                            </p>
                        </div>

                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-border px-4 py-2 text-sm font-medium text-muted-foreground hover:bg-muted"
                                @click="closeProposalModal"
                            >
                                Annuler
                            </button>
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="form.processing"
                            >
                                {{
                                    form.processing
                                        ? 'Envoi...'
                                        : 'Envoyer la proposition'
                                }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="lightboxUrl"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                @click.self="closeLightbox"
            >
                <div class="relative max-h-full max-w-3xl">
                    <button
                        type="button"
                        class="absolute -top-3 -right-3 flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                        @click="closeLightbox"
                    >
                        <X class="h-5 w-5" />
                    </button>
                    <img
                        :src="lightboxUrl"
                        :alt="lightboxAlt"
                        class="max-h-[80vh] max-w-full rounded-xl object-contain shadow-2xl"
                    />
                    <p class="mt-2 text-center text-sm text-white/70">
                        {{ lightboxAlt }}
                    </p>
                </div>
            </div>
        </Teleport>
    </ClientLayout>
</template>
