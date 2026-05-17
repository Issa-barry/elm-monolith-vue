export type KpiWidgetVariant = 'default' | 'primary-wave';
export type KpiWidgetAlign = 'left' | 'center';

export interface KpiWidgetItem {
    id: string;
    title: string;
    value: string;
    subtitle?: string;
    note?: string;
    titleClass?: string;
    valueClass?: string;
    subtitleClass?: string;
    noteClass?: string;
    align?: KpiWidgetAlign;
    variant?: KpiWidgetVariant;
    cardClass?: string;
    desktopClass?: string;
}
