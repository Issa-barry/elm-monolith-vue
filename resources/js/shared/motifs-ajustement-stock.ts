export interface MotifOption {
    label: string;
    value: string;
}

export const MOTIFS_AUGMENTATION: MotifOption[] = [
    { label: 'Après production', value: 'apres_production' },
    { label: 'Retour', value: 'retour' },
    { label: 'Correction de stock', value: 'correction_stock' },
    { label: 'Autre', value: 'autre' },
];

export const MOTIFS_DIMINUTION: MotifOption[] = [
    { label: 'Perte', value: 'perte' },
    { label: 'Casse', value: 'casse' },
    { label: 'Don', value: 'don' },
    { label: 'Correction de stock', value: 'correction_stock' },
    { label: 'Sortie exceptionnelle', value: 'sortie_exceptionnelle' },
    { label: 'Autre', value: 'autre' },
];
