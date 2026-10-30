{{-- Blocs « libellé : valeur », deux par ligne. --}}
@foreach(array_chunk($document->sections, 2) as $rangee)
<table class="sections">
    <tbody><tr>
        @foreach($rangee as $section)
            <td class="section{{ $loop->first ? '' : ' suivante' }}">
                <div class="section-titre">{{ $section['titre'] }}</div>
                <table class="section-lignes">
                    <tbody>
                        @foreach($section['lignes'] as $ligne)
                            <tr>
                                <td class="cle" style="width: 46%;">{{ $ligne['cle'] }}</td>
                                <td class="val coupe" style="width: 54%;">{{ $ligne['valeur'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
        @endforeach
        @if(count($rangee) === 1)
            <td class="section suivante"></td>
        @endif
    </tr></tbody>
</table>
@endforeach
