{{-- Rechte Leiste eines Artikels, unter „Details“: Auswertung der Rückmeldungen. Nur für Personen,
     die den Artikel bearbeiten dürfen; sie können offene Hinweise als erledigt markieren. --}}
@if(isset($page) && $page instanceof \BookStack\Entities\Models\Page && userCan(\BookStack\Permissions\Permission::PageUpdate, $page))
    @php($rueckmeldung = \FundusRueckmeldung\Rueckmeldung::fuerSeite($page))
    <section class="fundus-rueckmeldung-auswertung" aria-labelledby="fundus-rueckmeldung-titel">
        <h5 id="fundus-rueckmeldung-titel">Rückmeldungen</h5>
        <p class="zahlen">
            <span title="Fanden den Artikel hilfreich">{{ $rueckmeldung['ja'] }} × hilfreich</span>
            <span title="Fanden den Artikel nicht hilfreich">{{ $rueckmeldung['nein'] }} × nicht hilfreich</span>
        </p>
        @if($rueckmeldung['hinweise']->isEmpty())
            <p class="leer">Keine offenen Hinweise.</p>
        @else
            <ul class="hinweise">
                @foreach($rueckmeldung['hinweise'] as $hinweis)
                    <li data-hinweis="{{ $hinweis->id }}">
                        <div class="plaketten">
                            <span class="grund">{{ \FundusRueckmeldung\Rueckmeldung::GRUENDE[$hinweis->grund ?? 'veraltet'][0] ?? 'Hinweis' }}</span>
                            @if($hinweis->abschnitt)<span class="stelle" title="Abschnitt im Artikel">{{ $hinweis->abschnitt }}</span>@endif
                        </div>
                        <p>{{ $hinweis->text }}</p>
                        <div class="meta">
                            @if($hinweis->slug)<a href="{{ url('/user/' . $hinweis->slug) }}">{{ $hinweis->name }}</a>@endif
                            · {{ \Carbon\Carbon::parse($hinweis->created_at)->locale(config('app.locale'))->diffForHumans() }}
                            <button type="button" data-erledigt="{{ url('/fundus/rueckmeldung/' . $hinweis->id . '/erledigt') }}">Erledigt</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
