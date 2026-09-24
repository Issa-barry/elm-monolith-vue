import {
    FileText,
    Landmark,
    type LucideIcon,
    Smartphone,
    Wallet,
} from 'lucide-vue-next';

/**
 * Moyen d'encaissement hors espèces, tel que fourni par le backend
 * (App\Services\Tresorerie\MoyensEncaissementResolver) : un par support de trésorerie actif de
 * l'agence de la facture. Jamais une liste codée en dur côté frontend — un opérateur sans support
 * dans l'agence n'apparaît tout simplement pas (décision du 24/09/2026, cf. docs/encaissements.md).
 */
export interface MoyenEncaissement {
    key: string;
    label: string;
    /** especes/mobile_money/virement/cheque — jamais un opérateur. */
    mode_paiement: string;
    operateur_mobile_money: string | null;
    compte_tresorerie_id: string;
    reference_requise: boolean;
}

export interface ModeOption {
    key: string;
    label: string;
    mode_paiement: string;
    /** Support choisi — absent pour les espèces (routées vers la caisse dédiée de l'auteur). */
    compte_tresorerie_id?: string;
    requiresReference: boolean;
    /** Espèces : possibles seulement avec une caisse dédiée active de l'utilisateur. */
    requiresCaisse?: boolean;
    icon: LucideIcon;
    /** Repère visuel (pas un logo de marque). */
    badgeClass: string;
    referencePlaceholder?: string;
}

export type EncaissementPayload = {
    montant: number;
    mode_paiement: string;
    compte_tresorerie_id?: string;
    reference_paiement?: string;
};

const ESPECES: ModeOption = {
    key: 'especes',
    label: 'Espèces',
    mode_paiement: 'especes',
    requiresReference: false,
    requiresCaisse: true,
    icon: Wallet,
    badgeClass:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

const STYLE_OPERATEUR: Record<
    string,
    { badgeClass: string; referencePlaceholder: string }
> = {
    orange_money: {
        badgeClass:
            'bg-orange-100 text-orange-600 dark:bg-orange-900/40 dark:text-orange-300',
        referencePlaceholder: 'Ex. OM123456789',
    },
    kulu: {
        badgeClass:
            'bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-300',
        referencePlaceholder: 'Ex. KU123456789',
    },
    soutra_money: {
        badgeClass:
            'bg-cyan-100 text-cyan-600 dark:bg-cyan-900/40 dark:text-cyan-300',
        referencePlaceholder: 'Ex. SM123456789',
    },
    momo: {
        badgeClass:
            'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
        referencePlaceholder: 'Ex. MTN123456789',
    },
    paycard: {
        badgeClass:
            'bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300',
        referencePlaceholder: 'Ex. PC123456789',
    },
};

function versOption(moyen: MoyenEncaissement): ModeOption {
    const base = {
        key: moyen.key,
        label: moyen.label,
        mode_paiement: moyen.mode_paiement,
        compte_tresorerie_id: moyen.compte_tresorerie_id,
        requiresReference: moyen.reference_requise,
    };

    if (moyen.mode_paiement === 'mobile_money') {
        const style = STYLE_OPERATEUR[moyen.operateur_mobile_money ?? ''];
        return {
            ...base,
            icon: Smartphone,
            badgeClass:
                style?.badgeClass ??
                'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
            referencePlaceholder: style?.referencePlaceholder,
        };
    }

    if (moyen.mode_paiement === 'virement') {
        return {
            ...base,
            icon: Landmark,
            badgeClass:
                'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300',
            referencePlaceholder: 'Ex. VIR20260915001',
        };
    }

    return {
        ...base,
        icon: FileText,
        badgeClass:
            'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    };
}

/** Espèces en tête (règle propre à l'utilisateur), puis les moyens de l'agence, dans l'ordre reçu. */
export function construireOptions(moyens: MoyenEncaissement[]): ModeOption[] {
    return [ESPECES, ...moyens.map(versOption)];
}
