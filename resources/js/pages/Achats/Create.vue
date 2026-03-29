<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Save, Trash2 } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, onMounted } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface ProduitOption {
    id: number;
    nom: string;
    prix_achat: number;
    qte_stock: number;
}

interface PrestataireOption {
    id: number;
    nom: string;
}

interface LigneForm {
    produit_id: number | null;
    qte: number;
    prix_achat: number;
    total: number;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    produits: ProduitOption[];
    prestataires: PrestataireOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Achats', href: '/achats' },
    { title: 'Nouveau bon de commande', href: '/achats/create' },
];

// ── Form ──────────────────────────────────────────────────────────────────────
const form = useForm({
    prestataire_id: null as number | null,
    note: null as string | null,
    lignes: [
        { produit_id: null, qte: 1, prix_achat: 0, total: 0 },
    ] as LigneForm[],
});

// ── Options ───────────────────────────────────────────────────────────────────
const produitOptions = computed(() =>
    props.produits.map((p) => ({ value: p.id, label: p.nom })),
);

const prestataireOptions = computed(() =>
    props.prestataires.map((p) => ({ value: p.id, label: p.nom })),
);

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// ── Gestion des lignes ────────────────────────────────────────────────────────
function onProduitChange(index: number, produitId: number | null) {
    if (produitId === null) {
        form.lignes[index].produit_id = null;
        form.lignes[index].prix_achat = 0;
        form.lignes[index].total = 0;
        return;
    }

    // Si le produit existe déjà sur une autre ligne → fusionner les quantités
    const existingIndex = form.lignes.findIndex(
        (l, i) => i !== index && l.produit_id === produitId,
    );
    if (existingIndex !== -1) {
        const existing = form.lignes[existingIndex];
        existing.qte += form.lignes[index].qte;
        existing.total = existing.prix_achat * existing.qte;
        form.lignes.splice(index, 1);
        return;
    }

    const ligne = form.lignes[index];
    ligne.produit_id = produitId;
    const produit = props.produits.find((p) => p.id === produitId);
    ligne.prix_achat = produit ? produit.prix_achat : 0;
    ligne.total = ligne.prix_achat * ligne.qte;
}

function onQteChange(index: number, qte: number | null) {
    const ligne = form.lignes[index];
    ligne.qte = qte ?? 1;
    ligne.total = ligne.prix_achat * ligne.qte;
}

function onPrixChange(index: number, prix: number | null) {
    const ligne = form.lignes[index];
    ligne.prix_achat = prix ?? 0;
    ligne.total = ligne.prix_achat * ligne.qte;
}

function addLigne() {
    form.lignes.push({ produit_id: null, qte: 1, prix_achat: 0, total: 0 });
}

function removeLigne(index: number) {
    if (form.lignes.length > 1) {
        form.lignes.splice(index, 1);
    }
}

// ── Total général ─────────────────────────────────────────────────────────────
const totalGeneral = computed(() =>
    form.lignes.reduce((sum, l) => sum + l.total, 0),
);

// ── Reset au montage ──────────────────────────────────────────────────────────
onMounted(() => {
    form.reset();

    if (props.produits.length > 0) {
        const first = props.produits[0];
        form.lignes[0].produit_id = first.id;
        form.lignes[0].prix_achat = first.prix_achat;
        form.lignes[0].total = first.prix_achat * form.lignes[0].qte;
    }
});

// ── Validation locale ─────────────────────────────────────────────────────────
const canSubmit = computed(
    () =>
        form.lignes.some((l) => l.produit_id !== null && l.qte > 0) &&
        !form.processing,
);

// ── Soumission ────────────────────────────────────────────────────────────────
function submit() {
    form.post('/achats');
}
</script>

<template>
    <Head title="Nouveau bon de commande" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Mobile sticky header -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/achats"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Nouveau bon de commande
                    </h1>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-5xl p-4 sm:p-6">
            <div class="mb-6 hidden sm:block">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Nouveau bon de commande
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Créez un bon de commande. Vous pourrez le réceptionner pour
                    mettre à jour le stock.
                </p>
            </div>

            <form id="achat-form" class="space-y-6" @submit.prevent="submit">
                <!-- En-tête -->
                <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                    <h2
                        class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Informations générales
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <!-- Fournisseur -->
                        <div>
                            <Label class="mb-1.5 block text-sm"
                                >Fournisseur</Label
                            >
                            <Dropdown
                                v-model="form.prestataire_id"
                                :options="prestataireOptions"
                                option-label="label"
                                option-value="value"
                                placeholder="— Optionnel —"
                                show-clear
                                filter
                                class="w-full"
                            />
                        </div>

                        <!-- Note -->
                        <div>
                            <Label class="mb-1.5 block text-sm">Note</Label>
                            <InputText
                                v-model="form.note as string"
                                placeholder="Référence fournisseur, commentaire…"
                                class="w-full"
                            />
                        </div>
                    </div>
                </div>

                <!-- Lignes de commande -->
                <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                    <h2
                        class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Produits à commander
                    </h2>

                    <p
                        v-if="form.errors.lignes"
                        class="mb-3 text-xs text-destructive"
                    >
                        {{ form.errors.lignes }}
                    </p>

                    <!-- ── Tableau desktop ── -->
                    <div
                        class="hidden overflow-hidden rounded-lg border sm:block"
                    >
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-muted/40">
                                    <th
                                        class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                    >
                                        Produit
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-center font-medium text-muted-foreground"
                                        style="width: 110px"
                                    >
                                        Qté
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                        style="width: 180px"
                                    >
                                        Prix achat unit.
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                        style="width: 160px"
                                    >
                                        Total
                                    </th>
                                    <th
                                        class="px-4 py-2.5"
                                        style="width: 48px"
                                    ></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="(ligne, index) in form.lignes"
                                    :key="index"
                                    class="hover:bg-muted/10"
                                >
                                    <td class="px-4 py-3">
                                        <Dropdown
                                            :model-value="ligne.produit_id"
                                            @update:model-value="
                                                onProduitChange(index, $event)
                                            "
                                            :options="produitOptions"
                                            option-label="label"
                                            option-value="value"
                                            placeholder="Choisir un produit..."
                                            filter
                                            class="w-full"
                                            :class="{
                                                'p-invalid': (
                                                    form.errors as any
                                                )[`lignes.${index}.produit_id`],
                                            }"
                                        />
                                        <p
                                            v-if="
                                                (form.errors as any)[
                                                    `lignes.${index}.produit_id`
                                                ]
                                            "
                                            class="mt-1 text-xs text-destructive"
                                        >
                                            {{
                                                (form.errors as any)[
                                                    `lignes.${index}.produit_id`
                                                ]
                                            }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <InputNumber
                                            :model-value="ligne.qte"
                                            @update:model-value="
                                                onQteChange(index, $event)
                                            "
                                            :min="1"
                                            :use-grouping="false"
                                            class="w-full"
                                            input-class="w-full text-center"
                                        />
                                    </td>
                                    <td class="px-4 py-3">
                                        <InputNumber
                                            :model-value="ligne.prix_achat"
                                            @update:model-value="
                                                onPrixChange(index, $event)
                                            "
                                            :min="0"
                                            :use-grouping="false"
                                            suffix=" GNF"
                                            class="w-full"
                                            input-class="w-full text-right"
                                        />
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-medium tabular-nums"
                                    >
                                        {{
                                            ligne.total > 0
                                                ? formatGNF(ligne.total)
                                                : '—'
                                        }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="h-7 w-7 text-destructive hover:text-destructive"
                                            :disabled="form.lignes.length <= 1"
                                            @click="removeLigne(index)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── Cards mobile ── -->
                    <div class="space-y-3 sm:hidden">
                        <div
                            v-for="(ligne, index) in form.lignes"
                            :key="index"
                            class="rounded-xl border bg-muted/20 p-3"
                        >
                            <Dropdown
                                :model-value="ligne.produit_id"
                                @update:model-value="
                                    onProduitChange(index, $event)
                                "
                                :options="produitOptions"
                                option-label="label"
                                option-value="value"
                                placeholder="Choisir un produit..."
                                filter
                                class="w-full"
                            />

                            <div class="mt-2.5 grid grid-cols-2 gap-2.5">
                                <div>
                                    <p
                                        class="mb-1 text-[11px] font-medium text-muted-foreground"
                                    >
                                        Quantité
                                    </p>
                                    <InputNumber
                                        :model-value="ligne.qte"
                                        @update:model-value="
                                            onQteChange(index, $event)
                                        "
                                        :min="1"
                                        :use-grouping="false"
                                        class="w-full"
                                        input-class="w-full text-center"
                                    />
                                </div>
                                <div>
                                    <p
                                        class="mb-1 text-[11px] font-medium text-muted-foreground"
                                    >
                                        Prix achat (GNF)
                                    </p>
                                    <InputNumber
                                        :model-value="ligne.prix_achat"
                                        @update:model-value="
                                            onPrixChange(index, $event)
                                        "
                                        :min="0"
                                        :use-grouping="false"
                                        class="w-full"
                                        input-class="w-full"
                                    />
                                </div>
                            </div>

                            <div
                                class="mt-2.5 flex items-center justify-between"
                            >
                                <div>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        Total ligne
                                    </p>
                                    <p
                                        class="text-sm font-semibold tabular-nums"
                                    >
                                        {{
                                            ligne.total > 0
                                                ? formatGNF(ligne.total)
                                                : '—'
                                        }}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 text-destructive hover:text-destructive"
                                    :disabled="form.lignes.length <= 1"
                                    @click="removeLigne(index)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- Ajouter + Total -->
                    <div class="mt-4 flex items-center justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addLigne"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            Ajouter une ligne
                        </Button>
                        <div class="text-right">
                            <p
                                class="text-xs tracking-wider text-muted-foreground uppercase"
                            >
                                Total commande
                            </p>
                            <p class="text-2xl font-bold tabular-nums">
                                {{ formatGNF(totalGeneral) }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Spacer for mobile sticky footer -->
                <div class="h-20 sm:hidden" />

                <!-- Footer desktop -->
                <div class="hidden items-center justify-between sm:flex">
                    <Link href="/achats">
                        <Button type="button" variant="outline">Retour</Button>
                    </Link>
                    <Button type="submit" :disabled="!canSubmit">
                        {{
                            form.processing
                                ? 'Enregistrement…'
                                : 'Enregistrer le bon de commande'
                        }}
                    </Button>
                </div>
            </form>
        </div>

        <!-- Mobile sticky footer -->
        <div
            class="fixed right-0 bottom-0 left-0 z-20 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <Button class="w-full" :disabled="!canSubmit" @click="submit">
                <Save class="mr-2 h-4 w-4" />
                {{
                    form.processing
                        ? 'Enregistrement…'
                        : 'Enregistrer le bon de commande'
                }}
            </Button>
        </div>
    </AppLayout>
</template>
