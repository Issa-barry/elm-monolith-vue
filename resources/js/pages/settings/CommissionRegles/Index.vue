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

const MONTANT_INVALIDE = 'Saisissez un montant entier, 0 ou plus.';

// Bloque à la frappe tout caractère qui ne serait de toute façon jamais un
// entier positif valide (signe, séparateur décimal, lettres...) — la
// prévention prime sur la validation après coup (cf. GNF sans subdivision).
function blockNonIntegerKeydown(e: KeyboardEvent) {
    const pass = [
        'Backspace',
        'Delete',
        'Tab',
        'Escape',
        'Enter',
        'ArrowLeft',
        'ArrowRight',
        'Home',
        'End',
    ];
    if (pass.includes(e.key)) return;
    if (
        (e.ctrlKey || e.metaKey) &&
        ['a', 'c', 'v', 'x'].includes(e.key.toLowerCase())
    )
        return;
    if (!/^\d$/.test(e.key)) e.preventDefault();
}

// Le blocage à la frappe ne couvre pas le collage — un texte collé "5+555"
// contourne la frappe caractère par caractère. On remplace le champ entier
// par les seuls chiffres du texte collé, jamais une insertion partielle.
function onMontantPaste(e: ClipboardEvent) {
    e.preventDefault();
    const pasted = e.clipboardData?.getData('text') ?? '';
    form.montant = pasted.replace(/\D/g, '');
}

function submit() {
    form.clearErrors('montant');
    // Filet de sécurité (ex: collage au clavier) — le blocage à la frappe
    // empêche déjà la quasi-totalité des saisies invalides. 0 est autorisé
    // (valeur métier légitime, ex: exclure explicitement une catégorie).
    if (!/^\d+$/.test(String(form.montant).trim())) {
        form.setError('montant', MONTANT_INVALIDE);
        return;
    }

    form.post('/settings/commissions', {
        preserveScroll: true,
        onSuccess: () => {
            showDialog.value = false;
            toast.add({
                severity: 'success',
                summary: 'Barème enregistré',
                life: 3000,
            });
        },
    });
}

function formatMontant(m: Montant): string {
    return `${new Intl.NumberFormat('fr-FR').format(m.montant)} GNF`;
}

function cellLabel(ligne: Ligne, cible: Cible): string {
    const m = ligne.montants[cible.code];
    return m ? formatMontant(m) : 'Définir';
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
                        description="Montants de commission par catégorie pour le propriétaire, la livraison et le site de l'opération."
                    />
                </div>

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
                                    class="group flex w-full items-center justify-between gap-2 rounded-md border border-transparent px-2 py-1.5 text-left transition-colors outline-none hover:border-border hover:bg-muted focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    @click="openEdit(data, cible)"
                                >
                                    <span
                                        :class="[
                                            data.montants[cible.code]
                                                ? 'font-medium'
                                                : 'text-muted-foreground',
                                        ]"
                                    >
                                        {{ cellLabel(data, cible) }}
                                    </span>
                                    <Pencil
                                        class="h-3.5 w-3.5 shrink-0 text-primary"
                                    />
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
                <Label
                    for="cr-montant"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Montant (GNF / unité)
                    <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="cr-montant"
                    v-model="form.montant"
                    type="text"
                    inputmode="numeric"
                    placeholder="ex: 600"
                    :class="{ 'border-destructive': form.errors.montant }"
                    @keydown="blockNonIntegerKeydown"
                    @paste="onMontantPaste"
                />
                <p
                    v-if="form.errors.montant"
                    class="mt-1 text-xs text-destructive"
                >
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
