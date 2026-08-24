<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>Types de dépense</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; background: #fff; }
    .page { padding: 24px 28px; }

    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; }
    .org-name { font-size: 16px; font-weight: 700; color: #1e3a8a; }
    .doc-title { text-align: right; }
    .doc-type { font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.05em; }
    .doc-date { font-size: 7.5px; color: #6b7280; margin-top: 3px; }

    .meta { font-size: 8px; color: #4b5563; margin-bottom: 14px; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; table-layout: fixed; }
    thead tr { background: #1e40af; }
    thead th { padding: 5px 7px; text-align: left; font-size: 7.5px; font-weight: 700; text-transform: uppercase; color: #fff; letter-spacing: 0.04em; }
    thead th.center { text-align: center; }
    tbody tr { border-bottom: 1px solid #e5e7eb; }
    tbody tr:nth-child(even) { background: #f9fafb; }
    tbody td { padding: 4px 7px; font-size: 8.5px; }
    tbody td.center { text-align: center; }
    tbody td.name { font-weight: 600; }
    .req { color: #b45309; font-weight: 600; }
    .muted { color: #9ca3af; }
    .inactive { color: #b91c1c; }
    .active { color: #047857; }

    .footer { font-size: 7px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 6px; margin-top: 24px; display: flex; justify-content: space-between; }
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <div class="org-name">{{ $org_nom ?: 'ELM' }}</div>
            <div style="font-size: 8px; color: #6b7280; margin-top: 3px;">Module Dépenses · Types de dépense</div>
        </div>
        <div class="doc-title">
            <div class="doc-type">Types de dépense</div>
            <div class="doc-date">Généré le {{ $generated_at }}</div>
        </div>
    </div>

    <div class="meta">Filtres appliqués : {{ $filtres }} — {{ count($types) }} type{{ count($types) > 1 ? 's' : '' }}</div>

    <table>
        <thead>
            <tr>
                <th style="width:28%">Libellé</th>
                <th style="width:18%">Concerné</th>
                <th class="center" style="width:18%">Commentaire</th>
                <th class="center" style="width:18%">Justificatif</th>
                <th style="width:18%">Statut</th>
            </tr>
        </thead>
        <tbody>
            @forelse($types as $t)
            <tr>
                <td class="name">{{ $t->libelle }}</td>
                <td>{{ $t->categorie->label() }}</td>
                <td class="center">
                    <span class="{{ $t->commentaire_obligatoire ? 'req' : 'muted' }}">
                        {{ $t->commentaire_obligatoire ? 'Requis' : '—' }}
                    </span>
                </td>
                <td class="center">
                    <span class="{{ $t->justificatif_obligatoire ? 'req' : 'muted' }}">
                        {{ $t->justificatif_obligatoire ? 'Requis' : '—' }}
                    </span>
                </td>
                <td>
                    <span class="{{ $t->is_active ? 'active' : 'inactive' }}">
                        {{ $t->is_active ? 'Actif' : 'Inactif' }}
                    </span>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="muted" style="text-align: center; padding: 16px;">Aucun type de dépense pour ces filtres.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <span>{{ $org_nom ?: 'ELM' }} · Types de dépense</span>
        <span>Généré le {{ $generated_at }}</span>
    </div>
</div>
</body>
</html>
