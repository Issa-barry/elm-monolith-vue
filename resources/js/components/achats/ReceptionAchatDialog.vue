<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm, usePage } from '@inertiajs/vue3';
import { PackageCheck } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { useToast } from 'primevue/usetoast';
import { computed, watch } from 'vue';

export interface LigneAReceptionner {
    id: string;
    produit_nom: string;
    qte: number;
    qte_recue: number;
    reliquat: number;
}

export interface CommandeAReceptionner {
    id: string;
    reference: string;
    site_nom: string | null;
    lignes: LigneAReceptionner[];
}

const props = defineProps<{
    open: boolean;
    commande: CommandeAReceptionner | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const toast = useToast();
const page = usePage();

function aujourdhui(): string {
    const d = new Date();
    const mois = String(d.getMonth() + 1).padStart(2, '0');
    const jour = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mois}-${jour}`;
}

const form = useForm({
    date_reception: aujourdhui(),
    note: '',
    lignes: [] as { id: string; qte_recue: number }[],
});

const lignesOuvertes = computed(() =>
    (props.commande?.lignes ?? []).filter((l) => l.reliquat > 0),
);

watch(
    () => [props.open, props.commande?.id],
    () => {
        if (!props.open || !props.commande) return;
        form.reset();
        form.clearErrors();
        form.date_reception = aujourdhui();
        form.lignes = lignesOuvertes.value.map((l) => ({
            id: l.id,
            qte_recue: l.reliquat,
        }));
    },
    { immediate: true },
);

const totalSaisi = computed(() =>
    form.lignes.reduce((s, l) => s + (l.qte_recue || 0), 0),
);

function erreur(index: number): string | undefined {
    return (form.errors as Record<string, string>)[`lignes.${index}.qte_recue`];
}

function fermer(valeur: boolean) {
    if (!valeur && form.processing) return;
    emit('update:open', valeur);
}

function submit() {
    if (!props.commande || totalSaisi.value <= 0) return;
    form.post(`/backoffice/achats/${props.commande.id}/receptions`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:open', false);
            const flash = (page.props as any).flash ?? {};
            toast.add({
                severity: 'success',
                summary: 'Réception enregistrée',
                detail: flash.success,
                life: 4000,
            });
            if (flash.warning) {
                toast.add({
                    severity: 'warn',
                    summary: 'Prix d’achat',
                    detail: flash.warning,
                    life: 8000,
                });
            }
        },
        onError: (errors) => {
            if (errors.reception || errors.lignes) {
                toast.add({
                    severity: 'error',
                    summary: 'Réception impossible',
                    detail: errors.reception ?? errors.lignes,
                    life: 6000,
                });
            }
        },
    });
}
</script>

<template>
    <Dialog
        :visible="open"
        modal
        :header="`Réceptionner ${commande?.reference ?? ''}`"
        :closable="!form.processing"
        :style="{ width: '620px', maxWidth: '95vw' }"
        @update:visible="fermer"
    >
        <div v-if="commande" class="space-y-4">
            <p class="text-sm text-muted-foreground">
                Saisissez les quantités réellement reçues. Le stock de
                <span class="font-medium text-foreground">{{
                    commande.site_nom ?? '—'
                }}</span>
                augmente dès l'enregistrement. Le reste pourra être reçu plus
                tard.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label for="reception-date" class="mb-1.5 block text-sm"
                        >Date de réception</Label
                    >
                    <input
                        id="reception-date"
                        v-model="form.date_reception"
                        type="date"
                        :max="aujourdhui()"
                        class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                    />
                    <p
                        v-if="form.errors.date_reception"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.date_reception }}
                    </p>
                </div>
                <div>
                    <Label for="reception-note" class="mb-1.5 block text-sm"
                        >Note</Label
                    >
                    <InputText
                        id="reception-note"
                        v-model="form.note"
                        placeholder="Bon de livraison, observation…"
                        class="w-full"
                    />
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-muted-foreground">
                            <th class="px-3 py-2 text-left font-medium">
                                Produit
                            </th>
                            <th class="px-3 py-2 text-center font-medium">
                                Reste
                            </th>
                            <th class="w-32 px-3 py-2 text-center font-medium">
                                Reçu
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(ligne, index) in form.lignes"
                            :key="ligne.id"
                        >
                            <td class="px-3 py-2 font-medium">
                                {{ lignesOuvertes[index]?.produit_nom }}
                                <p
                                    v-if="erreur(index)"
                                    class="mt-1 text-xs font-normal text-destructive"
                                >
                                    {{ erreur(index) }}
                                </p>
                            </td>
                            <td
                                class="px-3 py-2 text-center text-muted-foreground tabular-nums"
                            >
                                {{ lignesOuvertes[index]?.reliquat }}
                            </td>
                            <td class="px-3 py-2">
                                <InputNumber
                                    v-model="ligne.qte_recue"
                                    :min="0"
                                    :max="lignesOuvertes[index]?.reliquat"
                                    :use-grouping="false"
                                    :aria-label="`Quantité reçue — ${lignesOuvertes[index]?.produit_nom}`"
                                    class="w-full"
                                    input-class="w-full text-center"
                                    @input="
                                        ligne.qte_recue = Number(
                                            $event.value ?? 0,
                                        )
                                    "
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="fermer(false)"
                    >Annuler</Button
                >
                <Button
                    class="bg-emerald-600 text-white hover:bg-emerald-700"
                    :disabled="form.processing || totalSaisi <= 0"
                    @click="submit"
                >
                    <i
                        v-if="form.processing"
                        class="pi pi-spin pi-spinner mr-2"
                    />
                    <PackageCheck v-else class="mr-2 h-4 w-4" />
                    Enregistrer la réception
                </Button>
            </div>
        </template>
    </Dialog>
</template>
