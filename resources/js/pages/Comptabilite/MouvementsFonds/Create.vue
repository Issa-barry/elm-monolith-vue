<script setup lang="ts">
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface CompteTresorerie {
    id: string;
    site_id: string;
    libelle: string;
    type: string;
}

const props = defineProps<{
    sites: { value: string; label: string }[];
    comptes_tresorerie: CompteTresorerie[];
    site_prerempli: string | null;
    montant_prerempli: string | null;
    echeance_debut_prerempli: string | null;
    echeance_fin_prerempli: string | null;
}>();

useFlashToast('top');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Mouvements de fonds',
        href: '/backoffice/comptabilite/tresorerie/mouvements',
    },
    { title: 'Nouveau', href: '#' },
];

const form = useForm({
    site_origine_id: '',
    site_destination_id: props.site_prerempli ?? '',
    compte_tresorerie_origine_id: '',
    compte_tresorerie_destination_id: '',
    montant: props.montant_prerempli ?? '',
    moyen_transfert: '',
    reference_externe: '',
    echeance_debut: props.echeance_debut_prerempli ?? '',
    echeance_fin: props.echeance_fin_prerempli ?? '',
    commentaire: '',
    justificatif: null as File | null,
});

const comptesOrigine = computed(() =>
    props.comptes_tresorerie.filter((c) => c.site_id === form.site_origine_id),
);
const comptesDestination = computed(() =>
    props.comptes_tresorerie.filter(
        (c) => c.site_id === form.site_destination_id,
    ),
);

function onFileChange(e: Event) {
    const target = e.target as HTMLInputElement;
    form.justificatif = target.files?.[0] ?? null;
}

function submit() {
    form.post('/backoffice/comptabilite/tresorerie/mouvements', {
        forceFormData: true,
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Nouveau mouvement de fonds" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl space-y-6 p-4 sm:p-6">
            <h1 class="text-xl font-semibold">Nouveau mouvement de fonds</h1>

            <form
                class="space-y-5 rounded-xl border bg-card p-6"
                @submit.prevent="submit"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium"
                            >Site d'origine</label
                        >
                        <select
                            v-model="form.site_origine_id"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="" disabled>Sélectionner…</option>
                            <option
                                v-for="s in sites"
                                :key="s.value"
                                :value="s.value"
                            >
                                {{ s.label }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.site_origine_id"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ form.errors.site_origine_id }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium"
                            >Support de trésorerie d'origine</label
                        >
                        <select
                            v-model="form.compte_tresorerie_origine_id"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="" disabled>Sélectionner…</option>
                            <option
                                v-for="c in comptesOrigine"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{ c.libelle }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.compte_tresorerie_origine_id"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ form.errors.compte_tresorerie_origine_id }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium"
                            >Site de destination</label
                        >
                        <select
                            v-model="form.site_destination_id"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="" disabled>Sélectionner…</option>
                            <option
                                v-for="s in sites"
                                :key="s.value"
                                :value="s.value"
                            >
                                {{ s.label }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.site_destination_id"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ form.errors.site_destination_id }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium"
                            >Support de trésorerie de destination</label
                        >
                        <select
                            v-model="form.compte_tresorerie_destination_id"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="" disabled>Sélectionner…</option>
                            <option
                                v-for="c in comptesDestination"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{ c.libelle }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.compte_tresorerie_destination_id"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ form.errors.compte_tresorerie_destination_id }}
                        </p>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-sm font-medium">Montant (GNF)</label>
                    <input
                        v-model="form.montant"
                        type="number"
                        min="1"
                        step="1"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                    />
                    <p
                        v-if="form.errors.montant"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ form.errors.montant }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <label class="text-sm font-medium"
                        >Échéance visée
                        <span class="font-normal text-muted-foreground"
                            >(optionnel — laisser vide pour une remise sans
                            échéance précise)</span
                        ></label
                    >
                    <div class="grid grid-cols-2 gap-4">
                        <input
                            v-model="form.echeance_debut"
                            type="date"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        />
                        <input
                            v-model="form.echeance_fin"
                            type="date"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium"
                            >Moyen de transfert</label
                        >
                        <input
                            v-model="form.moyen_transfert"
                            type="text"
                            placeholder="Espèces, virement…"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium"
                            >Référence externe</label
                        >
                        <input
                            v-model="form.reference_externe"
                            type="text"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        />
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-sm font-medium">Commentaire</label>
                    <textarea
                        v-model="form.commentaire"
                        rows="3"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </div>

                <div class="space-y-1.5">
                    <label class="text-sm font-medium">Justificatif</label>
                    <input type="file" class="text-sm" @change="onFileChange" />
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <a
                        href="/backoffice/comptabilite/tresorerie/mouvements"
                        class="inline-flex h-9 items-center rounded-md border px-4 text-sm"
                    >
                        Annuler
                    </a>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    >
                        Créer le brouillon
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
