@if($document->parties)
<table class="parties">
    <tbody><tr>
        @foreach($document->parties as $partie)
            <td class="partie{{ $loop->first ? '' : ' suivante' }}">
                <div class="partie-titre">{{ $partie['titre'] }}</div>
                @foreach($partie['lignes'] as $ligne)
                    <p class="coupe">{{ $ligne }}</p>
                @endforeach
            </td>
        @endforeach
        @if(count($document->parties) === 1)
            <td></td>
        @endif
    </tr></tbody>
</table>
@else
    <div class="sans-parties"></div>
@endif
