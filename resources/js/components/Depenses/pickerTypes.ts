export interface PickerField<T> {
    key: string;
    label: string;
    placeholder?: string;
    value: (option: T) => string | null | undefined;
    /** Comparaison sur les chiffres uniquement (insensible aux espaces/tirets). */
    phone?: boolean;
}
