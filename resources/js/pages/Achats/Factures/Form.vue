<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Check, Save } from 'lucide-vue-next';
import InputNumber, { type InputNumberInputEvent } from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, reactive } from 'vue';

interface LigneRecue {
    id: string;
    produit_nom: string;
    reference: string | null;
    qte_commandee: number;
    qte_recue: number;
    deja_facture: number;
    facturable: number;
    cout_unitaire: number;
}

interface Reception {
    id: string;
    reference: string;
    date_reception: string;
    lignes: LigneRecue[];
}

interface FactureEdition {
    id: string;
    reference: string;
    numero_facture_fournisseur: string;
    date_facture: string;
    date_echeance: string | null;
    taux_tva: number;
    note: string | null;
    lignes: {
        reception_ligne_id: string;
        qte: number;
        prix_unitaire: number;
    }[];
}

const props = defineProps<{
    facture: FactureEdition | null;
    commande: {
        id: string;
        reference: string;
        fournisseur_id: string;
        fournisseur_nom: string | null;
        site_nom: string | null;
    };
    receptions: Reception[];
}>();

const titre = computed(() =>
    props.facture
        ? `Modifier ${props.facture.reference}`
        : 'Saisir une facture d’achat',
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Achats', href: '/backoffice/achats' },
    {
        title: props.commande.reference,
        href: `/backoffice/achats/${props.commande.id}`,
    },
    { title: props.facture?.reference ?? 'Nouvelle facture', href: '#' },
];

function aujourdhui(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// Saisie par ligne reçue : incluse ou non, quantité, prix. Par défaut (création), chaque ligne encore
// facturable est proposée pour sa quantité restante, au coût de la réception.
const saisie = reactive<
    Record<string, { inclus: boolean; qte: number; prix: number }>
>({});
const existantes = new Map(
    (props.facture?.lignes ?? []).map((l) => [l.reception_ligne_id, l]),
);
for (const r of props.receptions) {
    for (const l of r.lignes) {
        const e = existantes.get(l.id);
        saisie[l.id] = props.facture
            ? {
                  inclus: !!e,
                  qte: e?.qte ?? l.facturable,
                  prix: e?.prix_unitaire ?? l.cout_unitaire,
              }
            : {
                  inclus: l.facturable > 0,
                  qte: l.facturable,
                  prix: l.cout_unitaire,
              };
    }
}

const form = useForm({
    commande_achat_id: props.commande.id,
    fournisseur_id: props.commande.fournisseur_id,
    numero_facture_fournisseur: props.facture?.numero_facture_fournisseur ?? '',
    date_facture: props.facture?.date_facture ?? aujourdhui(),
    date_echeance: props.facture?.date_echeance ?? '',
    taux_tva: props.facture?.taux_tva ?? 0,
    note: props.facture?.note ?? '',
});

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(Math.round(val)) + ' GNF';
}

function maj(id: string, champ: 'qte' | 'prix', e: InputNumberInputEvent) {
    saisie[id][champ] = Number(e.value ?? 0);
}

const lignesIncluses = computed(() =>
    props.receptions
        .flatMap((r) => r.lignes)
        .filter((l) => saisie[l.id]?.inclus),
);

const totalHt = computed(() =>
    lignesIncluses.value.reduce(
        (s, l) => s + saisie[l.id].qte * saisie[l.id].prix,
        0,
    ),
);
const totalTva = computed(
    () => Math.round(totalHt.value * Number(form.taux_tva || 0)) / 100,
);

function depasse(l: LigneRecue): boolean {
    return saisie[l.id].inclus && saisie[l.id].qte > l.facturable;
}

const canSubmit = computed(
    () =>
        !form.processing &&
        form.numero_facture_fournisseur.trim() !== '' &&
        lignesIncluses.value.length > 0 &&
        lignesIncluses.value.every((l) => saisie[l.id].qte > 0 && !depasse(l)),
);

const erreurCommande = computed(
    () => (form.errors as Record<string, string>).commande,
);
const erreurLignes = computed(
    () => (form.errors as Record<string, string>).lignes,
);

function erreurLigne(id: string): string | undefined {
    const index = lignesIncluses.value.findIndex((l) => l.id === id);
    const errs = form.errors as Record<string, string>;
    return (
        errs[`lignes.${index}.qte`] ??
        errs[`lignes.${index}.reception_ligne_id`]
    );
}

function submit() {
    if (!canSubmit.value) return;
    form.transform((data) => ({
        ...data,
        date_echeance: data.date_echeance || null,
        lignes: lignesIncluses.value.map((l) => ({
            reception_ligne_id: l.id,
            qte: saisie[l.id].qte,
            prix_unitaire: saisie[l.id].prix,
        })),
    }));
    if (props.facture) {
        form.put(`/backoffice/achats/factures/${props.facture.id}`);
    } else {
        form.post('/backoffice/achats/factures');
    }
}
</script>

<template>
    <Head :title="titre" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ titre }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Bon {{ commande.reference }} ·
                    {{ commande.fournisseur_nom ?? '—' }} ·
                    {{ commande.site_nom ?? '—' }}. Seules les quantités reçues
                    et pas encore facturées peuvent être facturées.
                </p>
            </div>

            <p
                v-if="erreurCommande"
                class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"
            >
                {{ erreurCommande }}
            </p>

            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <Label class="mb-1.5 block text-sm">Fournisseur</Label>
                        <p class="flex h-10 items-center text-sm font-medium">
                            {{ commande.fournisseur_nom ?? '—' }}
                        </p>
                        <p
                            v-if="form.errors.fournisseur_id"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.fournisseur_id }}
                        </p>
                    </div>
                    <div>
                        <Label for="ff-numero" class="mb-1.5 block text-sm">
                            N° de facture du fournisseur
                            <span class="text-destructive">*</span>
                        </Label>
                        <InputText
                            id="ff-numero"
                            v-model="form.numero_facture_fournisseur"
                            class="w-full"
                            :invalid="!!form.errors.numero_facture_fournisseur"
                        />
                        <p
                            v-if="form.errors.numero_facture_fournisseur"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.numero_facture_fournisseur }}
                        </p>
                    </div>
                    <div>
                        <Label for="ff-date" class="mb-1.5 block text-sm"
                            >Date de facture</Label
                        >
                        <input
                            id="ff-date"
                            v-model="form.date_facture"
                            type="date"
                            :max="aujourdhui()"
                            class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        />
                        <p
                            v-if="form.errors.date_facture"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.date_facture }}
                        </p>
                    </div>
                    <div>
                        <Label for="ff-echeance" class="mb-1.5 block text-sm"
                            >Échéance</Label
                        >
                        <input
                            id="ff-echeance"
                            v-model="form.date_echeance"
                            type="date"
                            :min="form.date_facture"
                            class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        />
                        <p
                            v-if="form.errors.date_echeance"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.date_echeance }}
                        </p>
                    </div>
                    <div>
                        <Label for="ff-tva" class="mb-1.5 block text-sm"
                            >Taux de TVA (%)</Label
                        >
                        <InputNumber
                            v-model="form.taux_tva"
                            input-id="ff-tva"
                            :min="0"
                            :max="100"
                            :max-fraction-digits="2"
                            class="w-full"
                            @input="form.taux_tva = Number($event.value ?? 0)"
                        />
                    </div>
                </div>
                <div class="mt-4">
                    <Label for="ff-note" class="mb-1.5 block text-sm"
                        >Note</Label
                    >
                    <InputText
                        id="ff-note"
                        v-model="form.note"
                        class="w-full"
                    />
                </div>
            </div>

            <p v-if="erreurLignes" class="text-sm text-destructive">
                {{ erreurLignes }}
            </p>

            <div
                v-for="r in receptions"
                :key="r.id"
                class="overflow-x-auto rounded-xl border bg-card shadow-sm"
            >
                <div class="border-b bg-muted/30 px-4 py-2 text-sm font-medium">
                    Réception
                    <span class="font-mono">{{ r.reference }}</span>
                    <span class="text-muted-foreground">
                        · {{ r.date_reception }}</span
                    >
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="w-10 px-3 py-2"></th>
                            <th class="px-3 py-2 font-medium">Produit</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Commandé
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Reçu
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Déjà facturé
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Facturable
                            </th>
                            <th class="w-28 px-3 py-2 font-medium">
                                Qté facturée
                            </th>
                            <th class="w-40 px-3 py-2 font-medium">
                                Prix unitaire
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Total HT
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="l in r.lignes"
                            :key="l.id"
                            :class="{ 'opacity-50': !saisie[l.id].inclus }"
                        >
                            <td class="px-3 py-2">
                                <button
                                    type="button"
                                    class="flex h-5 w-5 items-center justify-center rounded border-2"
                                    :class="
                                        saisie[l.id].inclus
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border'
                                    "
                                    :disabled="
                                        l.facturable === 0 &&
                                        !saisie[l.id].inclus
                                    "
                                    :aria-pressed="saisie[l.id].inclus"
                                    :aria-label="`Facturer ${l.produit_nom}`"
                                    @click="
                                        saisie[l.id].inclus =
                                            !saisie[l.id].inclus
                                    "
                                >
                                    <Check
                                        v-if="saisie[l.id].inclus"
                                        class="h-3 w-3"
                                    />
                                </button>
                            </td>
                            <td class="px-3 py-2 font-medium">
                                {{ l.produit_nom }}
                                <span
                                    v-if="l.reference"
                                    class="block font-mono text-xs font-normal text-muted-foreground"
                                    >{{ l.reference }}</span
                                >
                                <span
                                    v-if="erreurLigne(l.id)"
                                    class="block text-xs font-normal text-destructive"
                                    >{{ erreurLigne(l.id) }}</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ l.qte_commandee }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ l.qte_recue }}
                            </td>
                            <td
                                class="px-3 py-2 text-right text-muted-foreground tabular-nums"
                            >
                                {{ l.deja_facture }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-medium tabular-nums"
                            >
                                {{ l.facturable }}
                            </td>
                            <td class="px-3 py-2">
                                <InputNumber
                                    v-model="saisie[l.id].qte"
                                    :min="0"
                                    :use-grouping="false"
                                    :disabled="!saisie[l.id].inclus"
                                    :invalid="depasse(l)"
                                    :aria-label="`Quantité facturée — ${l.produit_nom}`"
                                    class="w-full"
                                    input-class="w-full text-center"
                                    @input="maj(l.id, 'qte', $event)"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <InputNumber
                                    v-model="saisie[l.id].prix"
                                    :min="0"
                                    :disabled="!saisie[l.id].inclus"
                                    :aria-label="`Prix unitaire — ${l.produit_nom}`"
                                    class="w-full"
                                    input-class="w-full text-right"
                                    @input="maj(l.id, 'prix', $event)"
                                />
                                <span
                                    v-if="
                                        saisie[l.id].inclus &&
                                        saisie[l.id].prix !== l.cout_unitaire
                                    "
                                    class="mt-1 flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400"
                                >
                                    <AlertTriangle class="h-3 w-3" />
                                    Bon : {{ formatGNF(l.cout_unitaire) }}
                                </span>
                            </td>
                            <td
                                class="px-3 py-2 text-right font-medium tabular-nums"
                            >
                                {{
                                    saisie[l.id].inclus
                                        ? formatGNF(
                                              saisie[l.id].qte *
                                                  saisie[l.id].prix,
                                          )
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p
                v-if="receptions.length === 0"
                class="rounded-xl border bg-card p-6 text-center text-sm text-muted-foreground"
            >
                Aucune réception sur ce bon de commande : rien à facturer.
            </p>

            <div
                class="flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-6"
            >
                <div class="grid grid-cols-3 gap-6 text-sm">
                    <div>
                        <p class="text-muted-foreground">Total HT</p>
                        <p class="font-semibold tabular-nums">
                            {{ formatGNF(totalHt) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">TVA</p>
                        <p class="font-semibold tabular-nums">
                            {{ formatGNF(totalTva) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Total TTC</p>
                        <p class="text-lg font-bold tabular-nums">
                            {{ formatGNF(totalHt + totalTva) }}
                        </p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Link
                        :href="
                            facture
                                ? `/backoffice/achats/factures/${facture.id}`
                                : `/backoffice/achats/${commande.id}`
                        "
                    >
                        <Button type="button" variant="outline">Retour</Button>
                    </Link>
                    <Button :disabled="!canSubmit" @click="submit">
                        <i
                            v-if="form.processing"
                            class="pi pi-spin pi-spinner mr-2"
                        />
                        <Save v-else class="mr-2 h-4 w-4" />
                        Enregistrer en brouillon
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
