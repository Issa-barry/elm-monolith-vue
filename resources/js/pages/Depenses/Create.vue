<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface DepenseType {
    id: string;
    code: string;
    libelle: string;
    requires_vehicle: boolean;
    requires_comment: boolean;
}
interface Vehicule {
    id: string;
    nom_vehicule: string;
    immatriculation: string;
}
interface Site {
    id: string;
    nom: string;
    type: string;
}

const props = defineProps<{
    types: DepenseType[];
    vehicules: Vehicule[];
    sites: Site[];
    default_site_id: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dépenses', href: '/depenses' },
    { title: 'Nouvelle dépense', href: '/depenses/create' },
];

const form = useForm({
    depense_type_id: '',
    vehicule_id: '',
    site_id: props.default_site_id ?? '',
    montant: '' as number | '',
    date_depense: new Date().toISOString().slice(0, 10),
    commentaire: '',
    statut: 'brouillon' as 'brouillon' | 'soumis',
});

const selectedType = computed(
    () => props.types.find((t) => t.id === form.depense_type_id) ?? null,
);

function submit() {
    form.post('/depenses');
}
</script>

<template>
    <Head title="Nouvelle dépense" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 sm:p-6">
            <div class="mx-auto max-w-xl space-y-6">
                <div>
                    <h1 class="text-xl font-semibold">Nouvelle dépense</h1>
                </div>

                <form class="space-y-5" @submit.prevent="submit">
                    <!-- Type de dépense -->
                    <div>
                        <Label
                            for="dep-type"
                            class="mb-1.5 block text-xs font-medium"
                        >
                            Type de dépense
                            <span class="text-destructive">*</span>
                        </Label>
                        <select
                            id="dep-type"
                            v-model="form.depense_type_id"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                            :class="{
                                'border-destructive':
                                    form.errors.depense_type_id,
                            }"
                        >
                            <option value="">Sélectionner un type…</option>
                            <option
                                v-for="t in types"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.libelle }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.depense_type_id"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.depense_type_id }}
                        </p>
                    </div>

                    <!-- Montant + Date -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label
                                for="dep-montant"
                                class="mb-1.5 block text-xs font-medium"
                            >
                                Montant (GNF)
                                <span class="text-destructive">*</span>
                            </Label>
                            <Input
                                id="dep-montant"
                                v-model.number="form.montant"
                                type="number"
                                min="1"
                                step="1"
                                placeholder="0"
                                :class="{
                                    'border-destructive': form.errors.montant,
                                }"
                            />
                            <p
                                v-if="form.errors.montant"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.montant }}
                            </p>
                        </div>
                        <div>
                            <Label
                                for="dep-date"
                                class="mb-1.5 block text-xs font-medium"
                            >
                                Date <span class="text-destructive">*</span>
                            </Label>
                            <Input
                                id="dep-date"
                                v-model="form.date_depense"
                                type="date"
                                :class="{
                                    'border-destructive':
                                        form.errors.date_depense,
                                }"
                            />
                            <p
                                v-if="form.errors.date_depense"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.date_depense }}
                            </p>
                        </div>
                    </div>

                    <!-- Véhicule (conditionnel) -->
                    <div>
                        <Label
                            for="dep-vehicule"
                            class="mb-1.5 block text-xs font-medium"
                        >
                            Véhicule
                            <span
                                v-if="selectedType?.requires_vehicle"
                                class="text-destructive"
                                >*</span
                            >
                        </Label>
                        <select
                            id="dep-vehicule"
                            v-model="form.vehicule_id"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                            :class="{
                                'border-destructive': form.errors.vehicule_id,
                            }"
                        >
                            <option value="">Aucun véhicule</option>
                            <option
                                v-for="v in vehicules"
                                :key="v.id"
                                :value="v.id"
                            >
                                {{ v.nom_vehicule }} ({{ v.immatriculation }})
                            </option>
                        </select>
                        <p
                            v-if="form.errors.vehicule_id"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.vehicule_id }}
                        </p>
                    </div>

                    <!-- Site -->
                    <div>
                        <Label
                            for="dep-site"
                            class="mb-1.5 block text-xs font-medium"
                            >Site</Label
                        >
                        <select
                            id="dep-site"
                            v-model="form.site_id"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                        >
                            <option value="">Aucun site</option>
                            <option
                                v-for="s in sites"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.nom }}
                            </option>
                        </select>
                    </div>

                    <!-- Commentaire -->
                    <div>
                        <Label
                            for="dep-comment"
                            class="mb-1.5 block text-xs font-medium"
                        >
                            Commentaire
                            <span
                                v-if="selectedType?.requires_comment"
                                class="text-destructive"
                                >*</span
                            >
                        </Label>
                        <textarea
                            id="dep-comment"
                            v-model="form.commentaire"
                            rows="3"
                            placeholder="Détails de la dépense…"
                            class="flex min-h-[72px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            :class="{
                                'border-destructive': form.errors.commentaire,
                            }"
                        />
                        <p
                            v-if="form.errors.commentaire"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.commentaire }}
                        </p>
                    </div>

                    <!-- Statut -->
                    <div>
                        <Label class="mb-2 block text-xs font-medium"
                            >Enregistrer comme</Label
                        >
                        <div class="flex gap-4">
                            <label
                                class="flex cursor-pointer items-center gap-2 text-sm"
                            >
                                <input
                                    v-model="form.statut"
                                    type="radio"
                                    value="brouillon"
                                    class="accent-primary"
                                />
                                Brouillon
                            </label>
                            <label
                                class="flex cursor-pointer items-center gap-2 text-sm"
                            >
                                <input
                                    v-model="form.statut"
                                    type="radio"
                                    value="soumis"
                                    class="accent-primary"
                                />
                                Soumettre pour validation
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-between pt-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <a href="/depenses">Annuler</a>
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="form.processing"
                        >
                            {{
                                form.processing
                                    ? 'Enregistrement…'
                                    : 'Enregistrer'
                            }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
