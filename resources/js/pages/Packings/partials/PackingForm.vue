<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Save } from 'lucide-vue-next';
import Calendar from 'primevue/calendar';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import { computed } from 'vue';

// ── Props / Emits ─────────────────────────────────────────────────────────────
interface Option {
    value: number;
    label: string;
}

interface FormData {
    prestataire_id: number | null;
    date: string;
    nb_rouleaux: number | null;
    prix_par_rouleau: number;
    notes: string | null;
}

const props = defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    prestataires: Option[];
    processing: boolean;
    reference?: string | null;
}>();

const emit = defineEmits<{
    submit: [];
    'update:form': [value: FormData];
}>();

// Montant calculé réactivement
const montantCalcule = computed(() => {
    const nb = props.form.nb_rouleaux ?? 0;
    const prix = props.form.prix_par_rouleau ?? 0;
    return nb * prix;
});

// Conversion date string → Date object pour Calendar
function toDate(val: string): Date | null {
    if (!val) return null;
    const d = new Date(val);
    return isNaN(d.getTime()) ? null : d;
}

function fromDate(val: Date | null): string {
    if (!val) return '';
    const y = val.getFullYear();
    const m = String(val.getMonth() + 1).padStart(2, '0');
    const d = String(val.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="emit('submit')">

        <!-- Section : Informations ──────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Informations
            </h3>

            <div class="grid gap-5 sm:grid-cols-2">

                <!-- Prestataire (pleine largeur) -->
                <div class="sm:col-span-2">
                    <Label class="mb-1.5 block">Prestataire <span class="text-destructive">*</span></Label>
                    <Dropdown
                        :model-value="form.prestataire_id"
                        @update:model-value="$emit('update:form', { ...form, prestataire_id: $event })"
                        :options="prestataires"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un prestataire"
                        filter
                        filter-placeholder="Rechercher..."
                        class="w-full"
                        :class="{ 'p-invalid': errors.prestataire_id }"
                    />
                    <p v-if="errors.prestataire_id" class="mt-1 text-xs text-destructive">{{ errors.prestataire_id }}</p>
                </div>

                <!-- Date -->
                <div>
                    <Label class="mb-1.5 block">Date <span class="text-destructive">*</span></Label>
                    <Calendar
                        :model-value="toDate(form.date)"
                        @update:model-value="$emit('update:form', { ...form, date: fromDate($event as Date | null) })"
                        date-format="dd/mm/yy"
                        :show-icon="true"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': errors.date }"
                    />
                    <p v-if="errors.date" class="mt-1 text-xs text-destructive">{{ errors.date }}</p>
                </div>

                <!-- Référence (lecture seule, affiché uniquement en édition) -->
                <div v-if="reference">
                    <Label class="mb-1.5 block">Référence</Label>
                    <div class="flex h-10 w-full items-center rounded-md border bg-muted/40 px-3 font-mono text-sm tracking-widest text-muted-foreground select-all">
                        {{ reference }}
                    </div>
                </div>

                <!-- Nb rouleaux -->
                <div>
                    <Label class="mb-1.5 block">Nombre de rouleaux <span class="text-destructive">*</span></Label>
                    <InputNumber
                        :model-value="form.nb_rouleaux"
                        @update:model-value="$emit('update:form', { ...form, nb_rouleaux: $event })"
                        :min="1"
                        :max="9999999"
                        :use-grouping="true"
                        locale="fr-FR"
                        :min-fraction-digits="0"
                        :max-fraction-digits="0"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': errors.nb_rouleaux }"
                    />
                    <p v-if="errors.nb_rouleaux" class="mt-1 text-xs text-destructive">{{ errors.nb_rouleaux }}</p>
                </div>

                <!-- Prix par rouleau -->
                <div>
                    <Label class="mb-1.5 block">Prix par rouleau <span class="text-destructive">*</span></Label>
                    <InputNumber
                        :model-value="form.prix_par_rouleau"
                        @update:model-value="$emit('update:form', { ...form, prix_par_rouleau: $event ?? 0 })"
                        :min="0"
                        :max="99999999"
                        :use-grouping="true"
                        locale="fr-FR"
                        :min-fraction-digits="0"
                        :max-fraction-digits="0"
                        suffix=" GNF"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': errors.prix_par_rouleau }"
                    />
                    <p v-if="errors.prix_par_rouleau" class="mt-1 text-xs text-destructive">{{ errors.prix_par_rouleau }}</p>
                </div>

                <!-- Montant calculé (lecture seule) -->
                <div class="sm:col-span-2">
                    <Label class="mb-1.5 block">Montant total</Label>
                    <div class="flex h-10 w-full items-center rounded-md border bg-muted/40 px-3 text-sm font-semibold tabular-nums text-foreground">
                        {{ montantCalcule.toLocaleString('fr-FR') }} GNF
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">Calculé automatiquement : nb rouleaux × prix par rouleau</p>
                </div>

            </div>
        </div>

        <!-- Section : Notes ─────────────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Notes
            </h3>
            <Textarea
                :model-value="form.notes ?? ''"
                @update:model-value="$emit('update:form', { ...form, notes: ($event as string) || null })"
                rows="4"
                placeholder="Observations, informations complémentaires..."
                class="w-full resize-none"
            />
            <p v-if="errors.notes" class="mt-1 text-xs text-destructive">{{ errors.notes }}</p>
        </div>

        <!-- Pied de formulaire ───────────────────────────────────────────────── -->
        <div class="flex items-center justify-between">
            <a href="/packings">
                <Button type="button" variant="outline">
                    <ArrowLeft class="mr-2 h-4 w-4" />
                    Retour
                </Button>
            </a>
            <Button type="submit" :disabled="processing">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
    </form>
</template>
