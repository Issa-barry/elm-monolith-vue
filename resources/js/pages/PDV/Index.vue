<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AutoComplete from 'primevue/autocomplete';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { useToast } from 'primevue/usetoast';
import QRCode from 'qrcode';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Product {
    id: number;
    code: string;
    name: string;
    subtitle: string;
    category: 'Packs' | 'Accessoires' | 'Boissons' | null;
    stock: number;
    unitPrice: number;
    image: string | undefined;
}

interface CartRow {
    productId: number;
    quantity: number;
}

interface VehiculeOption {
    id: string;
    nom_vehicule: string;
    immatriculation: string;
    capacite_packs: number | null;
    livreur_nom: string | null;
    livreur_telephone: string | null;
    display?: string;
}

interface ClientOption {
    id: string | number;
    nom: string;
    prenom: string | null;
    telephone: string | null;
}

type CartItem = Product & {
    quantity: number;
    lineTotal: number;
};

type SaleMode = 'Vente rapide' | 'Client' | 'Livreur';
type ProductCategory = 'Tous' | 'Packs' | 'Accessoires' | 'Boissons';

interface TicketLigne {
    nom: string;
    qte: number;
    prix_vente: number;
    total: number;
}

interface TicketCommande {
    commande_id: string;
    reference: string;
    created_at: string;
    org_nom: string;
    total_commande: number;
    lignes: TicketLigne[];
}

const props = defineProps<{
    produits?: Product[];
    vehicules?: VehiculeOption[];
    clients?: ClientOption[];
}>();

const searchQuery = ref('');
const selectedCategory = ref<ProductCategory>('Tous');
const selectedMode = ref<SaleMode>('Vente rapide');
const vehiculeSelected = ref<VehiculeOption | null>(null);
const selectedVehiculeId = ref<string | null>(null);
const vehiculeSuggests = ref<VehiculeOption[]>([]);
const clientSelected = ref<ClientOption | null>(null);
const selectedClientId = ref<string | number | null>(null);
const clientSuggests = ref<ClientOption[]>(props.clients ?? []);

const products = ref<Product[]>(props.produits ?? []);

const cartRows = ref<CartRow[]>([]);

const categories: ProductCategory[] = [
    'Tous',
    'Packs',
    'Accessoires',
    'Boissons',
];
const saleModes: SaleMode[] = ['Vente rapide', 'Client', 'Livreur'];

function sanitizeText(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    return value
        .replace(/â€”/g, '-')
        .replace(/â€“/g, '-')
        .replace(/Â·/g, '-')
        .replace(/Â/g, '')
        .trim();
}

function formatPhone(value: string | null | undefined): string {
    const raw = sanitizeText(value);
    if (!raw) {
        return '';
    }

    const digits = raw.replace(/\D/g, '');
    if (!digits) {
        return raw;
    }

    if (digits.startsWith('224') && digits.length >= 12) {
        const local = digits.slice(3, 12);
        const head = local.slice(0, 3);
        const g1 = local.slice(3, 5);
        const g2 = local.slice(5, 7);
        const g3 = local.slice(7, 9);

        return `+224 ${head} ${g1} ${g2} ${g3}`.trim();
    }

    if (raw.startsWith('+')) {
        return `+${digits}`;
    }

    return digits;
}

function normalizeVehicule(v: VehiculeOption): VehiculeOption {
    const nomVehicule = sanitizeText(v.nom_vehicule);
    const immatriculation = sanitizeText(v.immatriculation);
    const livreurNom = v.livreur_nom ? sanitizeText(v.livreur_nom) : null;
    const livreurTelephone = v.livreur_telephone
        ? sanitizeText(v.livreur_telephone)
        : null;

    return {
        ...v,
        nom_vehicule: nomVehicule,
        immatriculation,
        livreur_nom: livreurNom,
        livreur_telephone: livreurTelephone,
        display: `${nomVehicule} - ${immatriculation}`,
    };
}

const vehiculesNormalized = computed<VehiculeOption[]>(() =>
    (props.vehicules ?? []).map(normalizeVehicule),
);

vehiculeSuggests.value = [...vehiculesNormalized.value];

function searchVehicule(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    const vehicules = vehiculesNormalized.value;

    vehiculeSuggests.value = q
        ? vehicules.filter(
              (v) =>
                  v.nom_vehicule.toLowerCase().includes(q) ||
                  v.immatriculation.toLowerCase().includes(q) ||
                  (v.livreur_nom && v.livreur_nom.toLowerCase().includes(q)) ||
                  (v.livreur_telephone &&
                      v.livreur_telephone.toLowerCase().includes(q)),
          )
        : [...vehicules];
}

function onVehiculeSelect(v: VehiculeOption | null) {
    const normalized = v ? normalizeVehicule(v) : null;
    vehiculeSelected.value = normalized;
    selectedVehiculeId.value = normalized?.id ?? null;
}

function onVehiculeClear() {
    selectedVehiculeId.value = null;
    vehiculeSelected.value = null;
}

function searchClient(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    const clients = props.clients ?? [];

    clientSuggests.value = q
        ? clients.filter(
              (c) =>
                  c.nom.toLowerCase().includes(q) ||
                  (c.prenom && c.prenom.toLowerCase().includes(q)) ||
                  (c.telephone && c.telephone.includes(q)),
          )
        : [...clients];
}

function onClientSelect(c: ClientOption | null) {
    selectedClientId.value = c?.id ?? null;
}

function onClientClear() {
    selectedClientId.value = null;
    clientSelected.value = null;
}

function clientLabel(c: ClientOption): string {
    return [c.prenom, c.nom].filter(Boolean).join(' ');
}

const filteredProducts = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return products.value.filter((product) => {
        const inCategory =
            selectedCategory.value === 'Tous' ||
            product.category === selectedCategory.value;

        if (!inCategory) {
            return false;
        }

        if (!query) {
            return true;
        }

        const bag =
            `${product.name} ${product.subtitle} ${product.code}`.toLowerCase();

        return bag.includes(query);
    });
});

const cartItems = computed<CartItem[]>(() => {
    const items: CartItem[] = [];

    for (const row of cartRows.value) {
        const product = products.value.find(
            (item) => item.id === row.productId,
        );
        if (!product) {
            continue;
        }

        items.push({
            ...product,
            quantity: row.quantity,
            lineTotal: product.unitPrice * row.quantity,
        });
    }

    return items;
});

const subtotal = computed(() =>
    cartItems.value.reduce((sum, item) => sum + item.lineTotal, 0),
);

const shippingCost = ref(0);
const totalAmount = computed(() => subtotal.value + shippingCost.value);

function addToCart(product: Product): void {
    const line = cartRows.value.find((row) => row.productId === product.id);

    if (line) {
        line.quantity += 1;
        return;
    }

    cartRows.value.push({ productId: product.id, quantity: 1 });
}

function increase(productId: number): void {
    const line = cartRows.value.find((row) => row.productId === productId);
    if (!line) {
        return;
    }
    setQuantity(productId, line.quantity + 1);
}

function decrease(productId: number): void {
    const line = cartRows.value.find((row) => row.productId === productId);

    if (!line) {
        return;
    }

    if (line.quantity <= 1) {
        cartRows.value = cartRows.value.filter(
            (row) => row.productId !== productId,
        );
        return;
    }

    setQuantity(productId, line.quantity - 1);
}

function setQuantity(productId: number, rawValue: number): void {
    const line = cartRows.value.find((row) => row.productId === productId);
    if (!line) {
        return;
    }

    const qty = Math.floor(rawValue);
    if (!Number.isFinite(qty)) {
        return;
    }

    if (qty <= 0) {
        removeLine(productId);
        return;
    }

    line.quantity = qty;
}

function onQuantityInput(productId: number, event: Event): void {
    const target = event.target as HTMLInputElement | null;
    if (!target) {
        return;
    }

    const raw = Number(target.value);
    if (!Number.isFinite(raw)) {
        return;
    }

    setQuantity(productId, raw);
}

function removeLine(productId: number): void {
    cartRows.value = cartRows.value.filter(
        (row) => row.productId !== productId,
    );
}

function formatGNF(value: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(value)} GNF`;
}

const toast = useToast();

const showTicket = ref(false);
const ticketCommande = ref<TicketCommande | null>(null);
const ticketQrDataUrl = ref('');

watch(ticketCommande, async (commande) => {
    if (!commande) {
        ticketQrDataUrl.value = '';
        return;
    }
    const url = `${window.location.origin}/ventes/${commande.commande_id}`;
    ticketQrDataUrl.value = await QRCode.toDataURL(url, {
        width: 96,
        margin: 1,
        color: { dark: '#000000', light: '#ffffff' },
    });
});

function ensureModeSelection(): boolean {
    if (selectedMode.value === 'Client' && !selectedClientId.value) {
        toast.add({
            severity: 'warn',
            summary: 'Client requis',
            detail: 'Veuillez sélectionner un client.',
            life: 4000,
        });
        return false;
    }

    if (selectedMode.value === 'Livreur' && !selectedVehiculeId.value) {
        toast.add({
            severity: 'warn',
            summary: 'Véhicule requis',
            detail: 'Veuillez sélectionner un véhicule.',
            life: 4000,
        });
        return false;
    }

    return true;
}

function closeTicket(): void {
    showTicket.value = false;
}

function printTicket(): void {
    const el = document.getElementById('pdv-ticket-print');
    if (!el) return;

    const win = window.open('', '_blank', 'width=320,height=600');
    if (!win) return;

    const styleText = `
        @page { size: 80mm auto; margin: 0; }
        html, body { width: 80mm; margin: 0; padding: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: monospace;
            font-size: 11px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        #ticket-root { width: 72mm; margin: 0 auto; padding: 3mm 2mm; }
        .text-center { text-align: center; }
        .text-base { font-size: 14px; }
        .text-sm { font-size: 12px; }
        .text-xs { font-size: 10px; }
        .font-bold { font-weight: bold; }
        .font-semibold { font-weight: 600; }
        .font-medium { font-weight: 500; }
        .uppercase { text-transform: uppercase; }
        .tracking-wide { letter-spacing: 0.05em; }
        .truncate { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .flex { display: flex; }
        .flex-col { flex-direction: column; }
        .justify-between { justify-content: space-between; }
        .space-y-1 > * + * { margin-top: 4px; }
        .my-2 { margin: 6px 0; }
        .my-3 { margin: 8px 0; }
        .mt-0\\.5 { margin-top: 2px; }
        .mt-1 { margin-top: 4px; }
        .border-t { border-top: 1px dashed #999; }
        .border-dashed { border-style: dashed; }
        .text-surface-400, .text-surface-500 { color: #888; }
        .text-surface-900 { color: #111; }
        .dark\\:text-surface-0 { color: #111; }
        img { display: block; width: 96px; height: 96px; }
        .h-24 { height: 96px; } .w-24 { width: 96px; }
        .text-\\[10px\\] { font-size: 10px; }
    `;
    const html = `<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <title>Ticket</title>
    <style>${styleText}</style>
  </head>
  <body>
    <div id="ticket-root">${el.innerHTML}</div>
  </body>
</html>`;

    win.document.open();
    win.document.write(html);
    win.document.close();

    win.onafterprint = () => {
        win.close();
    };

    const doPrint = () => {
        win.focus();
        win.print();
    };

    if (win.document.readyState === 'complete') {
        window.setTimeout(doPrint, 80);
    } else {
        win.addEventListener(
            'load',
            () => {
                window.setTimeout(doPrint, 80);
            },
            { once: true },
        );
    }
}

const lightboxUrl = ref<string | null>(null);
const lightboxAlt = ref('');

function openLightbox(url: string, alt: string): void {
    lightboxUrl.value = url;
    lightboxAlt.value = alt;
}

function closeLightbox(): void {
    lightboxUrl.value = null;
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') closeLightbox();
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

const checkoutForm = useForm({
    mode: '' as SaleMode,
    client_id: null as string | number | null,
    vehicule_id: null as string | null,
    action: 'encaisser' as 'encaisser' | 'commande',
    lignes: [] as Array<{ produit_id: number; quantite: number }>,
});
const checkoutAction = ref<'encaisser' | 'commande' | null>(null);

function submit(action: 'encaisser' | 'commande'): void {
    if (cartRows.value.length === 0) return;

    if (!ensureModeSelection()) {
        return;
    }

    checkoutForm.mode = selectedMode.value;
    checkoutForm.client_id = selectedClientId.value;
    checkoutForm.vehicule_id = selectedVehiculeId.value;
    checkoutForm.action = action;
    checkoutForm.lignes = cartRows.value.map((r) => ({
        produit_id: r.productId,
        quantite: r.quantity,
    }));
    checkoutAction.value = action;

    checkoutForm.post('/pdv/checkout', {
        preserveState: true,
        onSuccess: (page) => {
            const flash = (page.props as Record<string, unknown>).flash as
                | Record<string, unknown>
                | undefined;
            const commande = flash?.pdv_commande as TicketCommande | undefined;
            if (commande) {
                ticketCommande.value = commande;
                showTicket.value = true;
                cartRows.value = [];
            }
        },
        onError: (errors) => {
            const first = Object.values(errors)[0];
            toast.add({
                severity: 'error',
                summary: 'Erreur',
                detail: first ?? 'Une erreur est survenue.',
                life: 5000,
            });
        },
        onFinish: () => {
            checkoutAction.value = null;
        },
    });
}
</script>

<template>
    <Head title="PDV" />

    <div
        class="bg-surface-100 dark:bg-surface-950 fixed inset-0 z-[120] overflow-hidden"
    >
        <div class="flex h-full">
            <section class="flex min-w-0 flex-1 flex-col">
                <div
                    class="bg-surface-0 border-surface-200 dark:bg-surface-900 dark:border-surface-700 border-b px-6 py-4"
                >
                    <div class="flex flex-col gap-4">
                        <div
                            class="flex flex-wrap items-start justify-between gap-4"
                        >
                            <div class="flex flex-wrap items-end gap-4">
                                <div class="flex flex-col">
                                    <h1
                                        class="text-surface-900 dark:text-surface-0 text-xl leading-tight font-semibold"
                                    >
                                        Point de vente
                                    </h1>
                                </div>

                                <div
                                    class="mb-0.5 flex flex-wrap items-center gap-1.5"
                                >
                                    <Button
                                        v-for="mode in saleModes"
                                        :key="mode"
                                        :label="mode"
                                        :severity="
                                            selectedMode === mode
                                                ? 'primary'
                                                : 'secondary'
                                        "
                                        :outlined="selectedMode !== mode"
                                        size="small"
                                        class="!border-surface-300 dark:!border-surface-600 !h-8 !rounded-full !px-2.5 !text-[12px] !font-medium"
                                        @click="selectedMode = mode"
                                    />
                                </div>
                            </div>

                            <div
                                v-if="selectedMode !== 'Vente rapide'"
                                class="flex w-full flex-col items-end gap-2 sm:w-72 md:w-[26rem]"
                            >
                                <AutoComplete
                                    v-if="selectedMode === 'Client'"
                                    v-model="clientSelected"
                                    :suggestions="clientSuggests"
                                    :option-label="clientLabel"
                                    @complete="searchClient"
                                    @item-select="
                                        onClientSelect(clientSelected)
                                    "
                                    @clear="onClientClear"
                                    placeholder="Choisir un client..."
                                    class="w-full"
                                    input-class="w-full h-8 text-sm"
                                    dropdown
                                    force-selection
                                >
                                    <template #option="{ option }">
                                        <div class="py-0.5">
                                            <div
                                                class="leading-tight font-medium"
                                            >
                                                {{
                                                    [option.prenom, option.nom]
                                                        .filter(Boolean)
                                                        .join(' ')
                                                }}
                                            </div>
                                            <div
                                                v-if="option.telephone"
                                                class="text-surface-500 dark:text-surface-400 mt-0.5 text-xs"
                                            >
                                                {{ option.telephone }}
                                            </div>
                                        </div>
                                    </template>
                                </AutoComplete>

                                <div
                                    v-if="
                                        selectedMode === 'Client' &&
                                        selectedClientId &&
                                        clientSelected
                                    "
                                    class="bg-surface-50 dark:bg-surface-800 border-surface-200 dark:border-surface-700 rounded-full border px-3 py-1.5 text-xs"
                                >
                                    <span
                                        class="text-surface-500 dark:text-surface-400"
                                    >
                                        Client
                                    </span>
                                    <span
                                        class="text-surface-900 dark:text-surface-0 ml-1 font-medium"
                                    >
                                        {{
                                            [
                                                clientSelected.prenom,
                                                clientSelected.nom,
                                            ]
                                                .filter(Boolean)
                                                .join(' ')
                                        }}
                                    </span>
                                    <span
                                        v-if="clientSelected.telephone"
                                        class="text-surface-500 dark:text-surface-400 ml-1"
                                    >
                                        {{
                                            formatPhone(
                                                clientSelected.telephone,
                                            )
                                        }}
                                    </span>
                                </div>

                                <AutoComplete
                                    v-if="selectedMode === 'Livreur'"
                                    v-model="vehiculeSelected"
                                    :suggestions="vehiculeSuggests"
                                    option-label="display"
                                    @complete="searchVehicule"
                                    @item-select="
                                        onVehiculeSelect(vehiculeSelected)
                                    "
                                    @clear="onVehiculeClear"
                                    placeholder="Choisir un vehicule..."
                                    class="w-full"
                                    input-class="w-full h-8 text-sm"
                                    dropdown
                                    force-selection
                                >
                                    <template #option="{ option }">
                                        <div class="py-0.5">
                                            <div
                                                class="leading-tight font-medium"
                                            >
                                                {{ option.nom_vehicule }}
                                            </div>
                                            <div
                                                class="text-surface-500 dark:text-surface-400 mt-0.5 flex items-center gap-2 text-xs"
                                            >
                                                <span class="font-mono">{{
                                                    option.immatriculation
                                                }}</span>
                                                <span
                                                    v-if="
                                                        option.capacite_packs !==
                                                        null
                                                    "
                                                >
                                                    -
                                                    {{ option.capacite_packs }}
                                                    packs
                                                </span>
                                                <span v-if="option.livreur_nom">
                                                    - {{ option.livreur_nom }}
                                                </span>
                                                <span
                                                    v-if="
                                                        option.livreur_telephone
                                                    "
                                                >
                                                    ({{
                                                        formatPhone(
                                                            option.livreur_telephone,
                                                        )
                                                    }})
                                                </span>
                                            </div>
                                        </div>
                                    </template>
                                </AutoComplete>

                                <div
                                    v-if="
                                        selectedMode === 'Livreur' &&
                                        selectedVehiculeId &&
                                        vehiculeSelected?.livreur_nom
                                    "
                                    class="bg-surface-50 dark:bg-surface-800 border-surface-200 dark:border-surface-700 rounded-full border px-3 py-1.5 text-xs"
                                >
                                    <span
                                        class="text-surface-500 dark:text-surface-400"
                                    >
                                        Livreur
                                    </span>
                                    <span
                                        class="text-surface-900 dark:text-surface-0 ml-1 font-medium"
                                    >
                                        {{ vehiculeSelected.livreur_nom }}
                                    </span>
                                    <span
                                        v-if="
                                            vehiculeSelected.livreur_telephone
                                        "
                                        class="text-surface-500 dark:text-surface-400 ml-1"
                                    >
                                        {{
                                            formatPhone(
                                                vehiculeSelected.livreur_telephone,
                                            )
                                        }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-1 flex flex-wrap items-center justify-between gap-2"
                        >
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Button
                                    v-for="category in categories"
                                    :key="category"
                                    :label="category"
                                    :severity="
                                        selectedCategory === category
                                            ? 'primary'
                                            : 'secondary'
                                    "
                                    :outlined="selectedCategory !== category"
                                    size="small"
                                    class="!border-surface-300 dark:!border-surface-600 !h-8 !rounded-full !px-2.5 !text-[12px] !font-medium"
                                    @click="selectedCategory = category"
                                />
                            </div>

                            <label class="w-full sm:w-64 md:w-72">
                                <InputText
                                    v-model="searchQuery"
                                    placeholder="Rechercher un produit"
                                    class="h-8 w-full rounded-lg text-sm"
                                    aria-label="Rechercher un produit"
                                />
                            </label>
                        </div>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-auto p-5">
                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                        <article
                            v-for="product in filteredProducts"
                            :key="product.id"
                            class="bg-surface-0 border-surface-200 dark:bg-surface-900 dark:border-surface-700 overflow-hidden rounded-2xl border"
                        >
                            <div class="flex h-full">
                                <div
                                    class="bg-surface-100 dark:bg-surface-800 w-28 min-w-[112px] self-stretch overflow-hidden"
                                    :class="
                                        product.image ? 'cursor-zoom-in' : ''
                                    "
                                    @click="
                                        product.image &&
                                        openLightbox(
                                            product.image,
                                            product.name,
                                        )
                                    "
                                >
                                    <img
                                        v-if="product.image"
                                        :src="product.image"
                                        :alt="product.name"
                                        class="h-full w-full object-cover transition-transform duration-300 hover:scale-110"
                                    />
                                    <div
                                        v-else
                                        class="flex h-full w-full items-center justify-center"
                                    >
                                        <i
                                            class="pi pi-box text-surface-400 dark:text-surface-500 text-3xl"
                                        />
                                    </div>
                                </div>

                                <div class="flex min-w-0 flex-1 flex-col p-3">
                                    <div class="mb-3 min-w-0">
                                        <h3
                                            class="text-surface-900 dark:text-surface-0 truncate text-sm font-semibold"
                                        >
                                            {{ product.name }}
                                        </h3>
                                        <p
                                            class="text-surface-900 dark:text-surface-0 mt-0.5 text-sm font-semibold"
                                        >
                                            {{ formatGNF(product.unitPrice) }}
                                        </p>
                                        <p
                                            class="text-surface-500 dark:text-surface-400 mt-0.5 text-xs"
                                        >
                                            {{ product.subtitle }}
                                        </p>
                                        <p
                                            class="text-surface-400 dark:text-surface-500 mt-2 text-xs"
                                        >
                                            Stock: {{ product.stock }}
                                        </p>
                                    </div>

                                    <Button
                                        label="Ajouter"
                                        severity="secondary"
                                        class="!bg-surface-100 !text-surface-600 !border-surface-200 !py-1.4 mt-auto h-8 w-full rounded-lg !px-3 text-xs font-medium"
                                        @click="addToCart(product)"
                                    />
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <aside
                class="bg-surface-0 border-surface-200 dark:bg-surface-900 dark:border-surface-700 flex h-full w-full flex-col border-l md:w-[420px] md:min-w-[420px] xl:w-[480px] xl:min-w-[480px]"
            >
                <div
                    class="border-surface-200 dark:border-surface-700 flex items-center justify-between border-b px-5 py-5"
                >
                    <h2
                        class="text-surface-900 dark:text-surface-0 text-lg font-semibold"
                    >
                        Panier
                    </h2>
                    <Link href="/ventes">
                        <Button
                            icon="pi pi-times"
                            text
                            rounded
                            class="h-9 w-9"
                            aria-label="Fermer"
                        />
                    </Link>
                </div>

                <div class="min-h-0 flex-1 overflow-auto px-5 py-4">
                    <div
                        v-if="cartItems.length === 0"
                        class="border-surface-300 text-surface-500 dark:border-surface-600 dark:text-surface-400 rounded-xl border border-dashed p-6 text-center"
                    >
                        Aucun article dans le panier.
                    </div>

                    <div
                        v-for="item in cartItems"
                        :key="item.id"
                        class="border-surface-200 dark:border-surface-700 border-b py-4"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="bg-surface-100 dark:bg-surface-800 h-16 w-14 shrink-0 overflow-hidden rounded-lg"
                            >
                                <img
                                    v-if="item.image"
                                    :src="item.image"
                                    :alt="item.name"
                                    class="h-full w-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center"
                                >
                                    <i
                                        class="pi pi-box text-surface-400 dark:text-surface-500 text-2xl"
                                    />
                                </div>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="text-surface-900 dark:text-surface-0 truncate text-sm font-semibold"
                                        >
                                            {{ item.name }}
                                        </p>
                                        <p
                                            class="text-surface-500 dark:text-surface-400 text-xs"
                                        >
                                            {{ item.subtitle }}
                                        </p>
                                    </div>
                                    <div class="flex items-start gap-1">
                                        <p
                                            class="text-surface-900 dark:text-surface-0 text-sm font-semibold whitespace-nowrap"
                                        >
                                            {{ formatGNF(item.lineTotal) }}
                                        </p>
                                        <Button
                                            icon="pi pi-trash"
                                            text
                                            size="small"
                                            class="h-7 w-7 !text-primary"
                                            @click="removeLine(item.id)"
                                        />
                                    </div>
                                </div>

                                <div class="mt-3 flex items-center gap-3">
                                    <Button
                                        icon="pi pi-minus"
                                        text
                                        size="small"
                                        class="h-7 w-7 !text-primary"
                                        @click="decrease(item.id)"
                                    />
                                    <InputText
                                        :model-value="String(item.quantity)"
                                        type="number"
                                        min="1"
                                        max="999"
                                        step="1"
                                        inputmode="numeric"
                                        class="h-7 w-20 text-center text-sm"
                                        @input="
                                            onQuantityInput(item.id, $event)
                                        "
                                    />
                                    <Button
                                        icon="pi pi-plus"
                                        text
                                        size="small"
                                        class="h-7 w-7 !text-primary"
                                        @click="increase(item.id)"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="border-surface-200 dark:border-surface-700 space-y-2 border-t px-5 pt-5 pb-6"
                >
                    <div
                        class="text-surface-700 dark:text-surface-300 flex items-center justify-between text-sm"
                    >
                        <span>Sous-total</span>
                        <span class="font-medium">{{
                            formatGNF(subtotal)
                        }}</span>
                    </div>
                    <div
                        class="text-surface-700 dark:text-surface-300 flex items-center justify-between text-sm"
                    >
                        <span>Livraison</span>
                        <span class="font-medium">{{
                            formatGNF(shippingCost)
                        }}</span>
                    </div>

                    <div
                        class="text-surface-900 dark:text-surface-0 mt-2 flex items-center justify-between text-base font-semibold"
                    >
                        <span>Total</span>
                        <span>{{ formatGNF(totalAmount) }}</span>
                    </div>

                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <Button
                            label="Encaisser"
                            severity="secondary"
                            outlined
                            class="w-full text-base font-semibold"
                            :disabled="
                                cartRows.length === 0 || checkoutForm.processing
                            "
                            :loading="
                                checkoutForm.processing &&
                                checkoutAction === 'encaisser'
                            "
                            @click="submit('encaisser')"
                        />
                        <Button
                            label="Créer commande"
                            severity="contrast"
                            class="w-full text-base font-semibold"
                            :disabled="
                                cartRows.length === 0 || checkoutForm.processing
                            "
                            :loading="
                                checkoutForm.processing &&
                                checkoutAction === 'commande'
                            "
                            @click="submit('commande')"
                        />
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <!-- ── Ticket caisse ─────────────────────────────────────────────────── -->
    <Dialog
        v-model:visible="showTicket"
        modal
        :closable="true"
        :style="{ width: '340px' }"
        :pt="{ header: { class: 'pb-2' }, content: { class: 'px-4 pb-4' } }"
        @hide="closeTicket"
    >
        <template #header>
            <span class="text-sm font-semibold">Ticket de caisse</span>
        </template>

        <div
            v-if="ticketCommande"
            id="pdv-ticket-print"
            class="font-mono text-xs"
        >
            <!-- En-tête -->
            <div class="mb-3 text-center">
                <p class="text-base font-bold uppercase">
                    {{ ticketCommande.org_nom }}
                </p>
                <p class="text-surface-500 mt-0.5">
                    {{ ticketCommande.created_at }}
                </p>
                <p class="mt-1 font-semibold tracking-wide">
                    {{ ticketCommande.reference }}
                </p>
            </div>

            <div
                class="border-surface-300 dark:border-surface-600 my-2 border-t border-dashed"
            />

            <!-- Lignes -->
            <div class="space-y-1">
                <div
                    v-for="ligne in ticketCommande.lignes"
                    :key="ligne.nom"
                    class="flex flex-col"
                >
                    <span class="truncate font-medium">{{ ligne.nom }}</span>
                    <div
                        class="text-surface-500 dark:text-surface-400 flex justify-between"
                    >
                        <span
                            >{{ ligne.qte }} ×
                            {{ formatGNF(ligne.prix_vente) }}</span
                        >
                        <span
                            class="text-surface-900 dark:text-surface-0 font-semibold"
                            >{{ formatGNF(ligne.total) }}</span
                        >
                    </div>
                </div>
            </div>

            <div
                class="border-surface-300 dark:border-surface-600 my-2 border-t border-dashed"
            />

            <!-- Total -->
            <div class="flex justify-between text-sm font-bold">
                <span>TOTAL</span>
                <span>{{ formatGNF(ticketCommande.total_commande) }}</span>
            </div>

            <div
                class="border-surface-300 dark:border-surface-600 my-3 border-t border-dashed"
            />

            <div
                v-if="ticketQrDataUrl"
                class="my-3 flex flex-col items-center gap-1"
            >
                <img
                    :src="ticketQrDataUrl"
                    alt="QR commande"
                    class="h-24 w-24"
                />
                <p class="text-surface-400 dark:text-surface-500 text-[10px]">
                    Scanner pour retrouver la commande
                </p>
            </div>

            <p class="text-surface-400 dark:text-surface-500 text-center">
                Merci pour votre achat !
            </p>
        </div>

        <template #footer>
            <div class="flex items-center justify-center gap-2">
                <Button
                    label="Imprimer"
                    icon="pi pi-print"
                    severity="secondary"
                    outlined
                    size="small"
                    class="h-8 px-3"
                    @click="
                        () => {
                            if (ticketCommande) printTicket();
                        }
                    "
                />
            </div>
        </template>
    </Dialog>

    <Teleport to="body">
        <div
            v-if="lightboxUrl"
            class="fixed inset-0 z-[200] flex items-center justify-center bg-black/80 p-4"
            @click.self="closeLightbox"
        >
            <div class="relative max-h-full max-w-3xl">
                <button
                    type="button"
                    class="absolute -top-3 -right-3 flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                    @click="closeLightbox"
                >
                    <i class="pi pi-times" />
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
</template>
