export interface CountryOption {
    label: string;
    value: string;
    code: string;
    dial: string;
    localLength: number;
}

const PAYS_DATA = [
    { label: 'Guinée',               code: 'GN', dial: '+224', localLength: 9  },
    { label: 'Guinée-Bissau',        code: 'GW', dial: '+245', localLength: 7  },
    { label: 'Sénégal',              code: 'SN', dial: '+221', localLength: 9  },
    { label: 'Mali',                 code: 'ML', dial: '+223', localLength: 8  },
    { label: "Côte d'Ivoire",        code: 'CI', dial: '+225', localLength: 10 },
    { label: 'Liberia',              code: 'LR', dial: '+231', localLength: 8  },
    { label: 'Sierra Leone',         code: 'SL', dial: '+232', localLength: 8  },
    { label: 'France',               code: 'FR', dial: '+33',  localLength: 9  },
    { label: 'Chine',                code: 'CN', dial: '+86',  localLength: 11 },
    { label: 'Émirats arabes unis',  code: 'AE', dial: '+971', localLength: 9  },
    { label: 'Inde',                 code: 'IN', dial: '+91',  localLength: 10 },
] as const;

/** Options where `value` is the ISO country code (e.g. 'GN'). Used by UserForm. */
export const paysOptionsByCode: CountryOption[] = PAYS_DATA.map((p) => ({
    ...p,
    value: p.code,
}));

/** Options where `value` is the country name (e.g. 'Guinée'). Used by LivreurForm, ProprietaireForm. */
export const paysOptionsByName: CountryOption[] = PAYS_DATA.map((p) => ({
    ...p,
    value: p.label,
}));
