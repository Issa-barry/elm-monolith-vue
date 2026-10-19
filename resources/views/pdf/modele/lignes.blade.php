<table class="lignes">
    <thead><tr>
        @foreach($document->colonnes as $colonne)
            <th class="{{ $colonne['numerique'] ? 'num' : 'description' }}{{ $loop->last ? ' dernier' : '' }}" style="width: {{ $colonne['largeur'] }};">{{ $colonne['libelle'] }}</th>
        @endforeach
    </tr></thead>
    <tbody>
        @forelse($document->lignes as $ligne)
            <tr>
                @foreach($ligne['cellules'] as $i => $cellule)
                    @if($i === 0)
                        <td class="description coupe">
                            {{ $cellule }}
                            @if($ligne['detail'])<span class="detail">{{ $ligne['detail'] }}</span>@endif
                        </td>
                    @else
                        <td class="num{{ $loop->last ? ' dernier' : '' }}">{{ $cellule }}</td>
                    @endif
                @endforeach
            </tr>
        @empty
            <tr><td class="vide" colspan="{{ count($document->colonnes) }}">Aucune ligne.</td></tr>
        @endforelse
    </tbody>
</table>
