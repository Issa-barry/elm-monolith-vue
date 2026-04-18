<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import type {
    ActorPayload,
    TypeVehiculeOption,
    VehicleProposal,
} from '@/types/client-space';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    actor: ActorPayload;
    type_vehicule_options: TypeVehiculeOption[];
    vehicle_proposals: VehicleProposal[];
}>();

const page = usePage();
const flash = computed(
    () => (page.props as { flash?: { success?: string } }).flash,
);

const form = useForm({
    nom_vehicule: '',
    marque: '',
    modele: '',
    immatriculation: '',
    type_vehicule: '',
    capacite_packs: null as number | null,
    commentaire: '',
});

const typeLabelMap = computed(
    () =>
        new Map(
            props.type_vehicule_options.map((option) => [
                option.value,
                option.label,
            ]),
        ),
);

function onTypeVehiculeChange() {
    const selectedType = props.type_vehicule_options.find(
        (option) => option.value === form.type_vehicule,
    );

    if (selectedType && !form.capacite_packs) {
        form.capacite_packs = selectedType.capacite_defaut;
    }
}

function submitProposal() {
    form.post('/client/propositions-vehicules', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.clearErrors();
        },
    });
}
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Proposer vehicule" />

        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold">Proposer un vehicule</h1>
                <p class="mt-1 text-muted-foreground">
                    Envoyez vos vehicules pour devenir partenaire.
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        v-for="profile in actor.profiles"
                        :key="profile"
                        class="rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary"
                    >
                        {{ profile }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{
                            actor.organization_name
                                ? `Organisation: ${actor.organization_name}`
                                : 'Organisation non rattachee'
                        }}
                    </span>
                </div>
            </div>

            <div
                v-if="flash?.success"
                class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
            >
                {{ flash.success }}
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-border bg-card p-5">
                    <h2 class="text-lg font-semibold">Nouvelle proposition</h2>
                    <form
                        class="mt-4 space-y-4"
                        @submit.prevent="submitProposal"
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label
                                    class="text-sm font-medium text-foreground"
                                    >Nom du vehicule *</label
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
                                    >Immatriculation *</label
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
                                    >Type de vehicule *</label
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
                                <input
                                    v-model.number="form.capacite_packs"
                                    type="number"
                                    min="1"
                                    class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                    placeholder="Ex: 120"
                                />
                                <p
                                    v-if="form.errors.capacite_packs"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ form.errors.capacite_packs }}
                                </p>
                            </div>
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
                    </form>
                </div>

                <div class="rounded-xl border border-border bg-card p-5">
                    <h2 class="text-lg font-semibold">Vos propositions</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Suivi des propositions envoyees.
                    </p>

                    <div
                        v-if="vehicle_proposals.length === 0"
                        class="mt-4 text-sm text-muted-foreground"
                    >
                        Aucune proposition envoyee pour le moment.
                    </div>

                    <div v-else class="mt-4 space-y-3">
                        <div
                            v-for="proposal in vehicle_proposals"
                            :key="proposal.id"
                            class="rounded-lg border border-border p-3"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium text-foreground">
                                        {{ proposal.nom_vehicule }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ proposal.immatriculation }}
                                        <span v-if="proposal.type_vehicule">
                                            -
                                            {{
                                                typeLabelMap.get(
                                                    proposal.type_vehicule,
                                                ) ?? proposal.type_vehicule
                                            }}
                                        </span>
                                    </p>
                                </div>
                                <span
                                    class="rounded-full px-2 py-1 text-xs font-medium"
                                    :class="{
                                        'bg-amber-500/15 text-amber-700 dark:text-amber-300':
                                            proposal.statut === 'pending',
                                        'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300':
                                            proposal.statut === 'approved',
                                        'bg-red-500/15 text-red-700 dark:text-red-300':
                                            proposal.statut === 'rejected',
                                    }"
                                >
                                    {{ proposal.statut_label }}
                                </span>
                            </div>

                            <p class="mt-2 text-xs text-muted-foreground">
                                Envoye le {{ proposal.created_at_label ?? '-' }}
                            </p>
                            <p
                                v-if="proposal.decision_note"
                                class="mt-2 text-xs text-muted-foreground"
                            >
                                Note: {{ proposal.decision_note }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </ClientLayout>
</template>
