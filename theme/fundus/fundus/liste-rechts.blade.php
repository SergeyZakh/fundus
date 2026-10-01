{{-- Rechte Spalte der Listen aller Themen (/books) und Bereiche (/shelves). Die Aktionen stellt wiki.js in
     die Leiste über dem Inhalt; ohne diesen Baustein bliebe die Spalte leer.
     Alles mit BookStacks Rechtefilter: Gezählt und gezeigt wird nur, was die Person lesen darf. --}}
@if(request()->is('books') || request()->is('shelves'))
    @php
        $sichtbar = fn () => \BookStack\Entities\Models\Page::query()->scopes('visible')
            ->where('draft', false)->where('template', false);
        $geaendert = $sichtbar()->with(['book', 'updatedBy'])->orderByDesc('updated_at')->limit(5)->get();
        // Wer in den letzten 30 Tagen an Artikeln gearbeitet hat, die man sehen darf.
        $aktiv = $sichtbar()->where('updated_at', '>=', now()->subDays(30))->whereNotNull('updated_by')
            ->get(['id', 'updated_by'])->countBy('updated_by')->sortDesc()->take(5);
        $aktivePersonen = \BookStack\Users\Models\User::query()->whereIn('id', $aktiv->keys())->get()->keyBy('id');
    @endphp
    @include('fundus.symbole-json')
    <section class="fundus-seitenkarte" aria-labelledby="fundus-rechts-geaendert">
        <h5 id="fundus-rechts-geaendert">Zuletzt geändert</h5>
        @forelse($geaendert as $artikel)
            <a class="fundus-seitenzeile" href="{{ $artikel->getUrl() }}">
                <span class="fundus-seitenzeile-text">
                    <span class="name">{{ $artikel->name }}</span>
                    <span class="meta">{{ $artikel->book?->name }} · {{ $artikel->updated_at->locale(config('app.locale'))->shortRelativeToNowDiffForHumans() }}</span>
                </span>
            </a>
        @empty
            <p class="fundus-leer">Noch keine Artikel.</p>
        @endforelse
        <a class="fundus-mehr" href="{{ url('/pages/recently-updated') }}">Alle Änderungen</a>
    </section>

    @if($aktiv->isNotEmpty())
        <section class="fundus-seitenkarte" aria-labelledby="fundus-rechts-aktiv">
            <h5 id="fundus-rechts-aktiv">Aktiv in den letzten 30 Tagen</h5>
            @foreach($aktiv as $personId => $anzahl)
                @continue(!$aktivePersonen->has($personId))
                @php($person = $aktivePersonen[$personId])
                <a class="fundus-seitenzeile fundus-person-zeile" href="{{ $person->getProfileUrl() }}">
                    <span class="fundus-seitenzeile-text">
                        <span class="name">{{ $person->name }}</span>
                        <span class="meta">{{ $anzahl }} Artikel bearbeitet</span>
                    </span>
                </a>
            @endforeach
        </section>
    @endif
@endif
