<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Coins, Pencil, Plus } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Cible {
    code: string;
    libelle: string;
}
interface Montant {
    montant: number;
    effective_from: string;
    regle_id: string;
}
interface Ligne {
    scope_type: 'global' | 'categorie';
    scope_id: string | null;
    libelle: string;
    montants: Record<string, Montant | null>;
}

const props = defineProps<{
    lignes: Ligne[];
    cibles: Cible[];
    moteurActif: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Commissions', href: '/settings/commissions' },
];

const toast = useToast();

// ── Dialog : définir / modifier un montant ──────────────────────────────────

const showDialog = ref(false);
const editingLigne = ref<Ligne | null>(null);
const editingCible = ref<Cible | null>(null);

const form = useForm({
    cible_type: '',
    scope_type: 'global' as 'global' | 'categorie',
    categorie_id: null as string | null,
    montant: '' as number | string,
});

function openEdit(ligne: Ligne, cible: Cible) {
    editingLigne.value = ligne;
    editingCible.value = cible;
    form.reset();
    form.cible_type = cible.code;
    form.scope_type = ligne.scope_type;
    form.categorie_id = ligne.scope_id;
    form.montant = ligne.montants[cible.code]?.montant ?? '';
    showDialog.value = true;
}

const dialogTitle = computed(() => {
    if (!editingLigne.value || !editingCible.value) return '';

    return `${editingCible.value.libelle} — ${editingLigne.value.libelle}`;
});

function submit() {
    form.post('/settings/commissions', {
        preserveScroll: true,
        onSuccess: () => {
            showDialog.value = false;
            toast.add({ severity: 'success', summary: 'Barème enregistré', life: 3000 });
        },
    });
}

function formatMontant(m: Montant | null): string {
    if (!m) return '—';

    return `${new Intl.NumberFormat('fr-FR').format(m.montant)} GNF`;
}
</script>

<template>
    <Head title="Paramètres commissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :wide="true">
            <div class="space-y-6">
                <div class="flex items-start justify-between gap-4">
                    <HeadingSmall
                        title="Commissions"
                        description="Montant fixe par unité vendue, propriétaire et livraison, par catégorie de produit."
                    />
                </div>

                <p
                    v-if="!props.moteurActif"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
                >
                    Ces barèmes sont enregistrés mais pas encore appliqués aux
                    ventes — le nouveau moteur de commissions n'est pas encore
                    activé pour votre organisation.
                </p>

                <div class="overflow-hidden rounded-xl border bg-card">
                    <DataTable
                        :value="props.lignes"
                        data-key="scope_id"
                        striped-rows
                        class="text-sm"
                        :pt="{
                            root: { class: 'w-full' },
                            tbody: { class: 'divide-y' },
                        }"
                    >
                        <Column
                            field="libelle"
                            header="Catégorie"
                            style="min-width: 220px"
                        >
                            <template #body="{ data }">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border bg-muted/30"
                                    >
                                        <Coins
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                    </div>
                                    <span
                                        :class="[
                                            'font-medium',
                                            data.scope_type === 'global' &&
                                                'text-muted-foreground italic',
                                        ]"
                                    >
                                        {{ data.libelle }}
                                    </span>
                                </div>
                            </template>
                        </Column>

                        <Column
                            v-for="cible in props.cibles"
                            :key="cible.code"
                            :header="cible.libelle"
                            style="width: 220px"
                        >
                            <template #body="{ data }">
                                <button
                                    type="button"
                                    class="group flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left transition-colors hover:bg-muted"
                                    @click="openEdit(data, cible)"
                                >
                                    <template v-if="data.montants[cible.code]">
                                        <span class="font-medium">
                                            {{
                                                formatMontant(
                                                    data.montants[cible.code],
                                                )
                                            }}
                                        </span>
                                        <Pencil
                                            class="h-3.5 w-3.5 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100"
                                        />
                                    </template>
                                    <template v-else>
                                        <span
                                            class="text-muted-foreground group-hover:hidden"
                                            >—</span
                                        >
                                        <span
                                            class="hidden items-center gap-1 text-sm font-medium text-primary group-hover:flex"
                                        >
                                            <Plus class="h-3.5 w-3.5" />
                                            Définir
                                        </span>
                                    </template>
                                </button>
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>

    <Dialog
        v-model:visible="showDialog"
        modal
        :header="dialogTitle"
        :style="{ width: 'min(420px, 95vw)' }"
        :dismissable-mask="true"
    >
        <form class="space-y-4 pt-2 pb-1" @submit.prevent="submit">
            <div>
                <Label for="cr-montant" class="mb-1.5 block text-xs font-medium">
                    Montant (GNF / unité) <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="cr-montant"
                    v-model="form.montant"
                    type="number"
                    min="0"
                    step="1"
                    placeholder="ex: 600"
                    :class="{ 'border-destructive': form.errors.montant }"
                />
                <p v-if="form.errors.montant" class="mt-1 text-xs text-destructive">
                    {{ form.errors.montant }}
                </p>
                <p class="mt-1.5 text-xs text-muted-foreground">
                    S'applique aux ventes à partir d'aujourd'hui. Les
                    commissions déjà générées ne changent jamais.
                </p>
            </div>

            <div class="flex justify-between pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="showDialog = false"
                    >Annuler</Button
                >
                <Button type="submit" size="sm" :disabled="form.processing">
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
                </Button>
            </div>
        </form>
    </Dialog>
</template>
