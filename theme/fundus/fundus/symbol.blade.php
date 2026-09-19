{{-- Wird nach jeder Kachel (entities.grid-item) eingefügt. Hat das Regal oder Buch ein
     Schlagwort „Symbol“, liegt das SVG in einem <template>; wiki.js setzt es in den Kreis
     der Karte. Bei Themen kommt eine Zeile mit Artikelzahl und Stand dazu.
     <template> ist unsichtbar und stört das Kachelraster nicht. --}}
@php
    $symbolSvg = trim(view('fundus.symbol-svg', ['entity' => $entity])->render());
    $kartenMeta = null;
    if ($entity instanceof \BookStack\Entities\Models\Book) {
        $artikel = $entity->pages()->scopes('visible')->where('draft', false)->where('template', false);
        $anzahl = (clone $artikel)->count();
        $stand = (clone $artikel)->max('updated_at');
        $kartenMeta = $anzahl === 0 ? 'Noch leer'
            : $anzahl . ' Artikel · geändert ' . \Carbon\Carbon::parse($stand)->locale(config('app.locale'))->diffForHumans();
    } elseif ($entity instanceof \BookStack\Entities\Models\Bookshelf) {
        $buchIds = $entity->visibleBooks()->pluck('id');
        $anzahl = \BookStack\Entities\Models\Page::query()->scopes('visible')->where('draft', false)->where('template', false)->whereIn('book_id', $buchIds)->count();
        $kartenMeta = $buchIds->count() === 0 ? 'Noch leer'
            : $buchIds->count() . ' ' . ($buchIds->count() === 1 ? 'Thema' : 'Themen') . ' · ' . $anzahl . ' Artikel';
    }
@endphp
@if($symbolSvg !== '' || $kartenMeta)
    <template data-fundus-symbol>{!! $symbolSvg !!}@if($kartenMeta)<p class="fundus-karte-meta">{{ $kartenMeta }}</p>@endif</template>
@endif
