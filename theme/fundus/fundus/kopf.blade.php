{{-- Wird nach layouts.parts.custom-head eingefügt, also in jede normale Seite.
     Der Zeitstempel im Link umgeht den Tages-Cache nach einer Theme-Änderung. --}}
<link rel="preload" href="{{ url('/theme/fundus/fonts/instrument-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ url('/theme/fundus/wiki.css') }}?v={{ filemtime(theme_path('public/wiki.css')) }}">
{{-- Vor dem ersten Zeichnen: eingeklappte Seitenleisten setzen und „fundus-laedt“, bis wiki.js Pfad, Aktionen
     und Details umgestellt hat (wiki.css zeigt solange Platzhalter). Nach 2,5 s fällt die Klasse in jedem Fall,
     damit ein Skriptfehler die Seite nicht versteckt lässt. --}}
<script nonce="{{ $cspNonce ?? '' }}">(function () {
  var h = document.documentElement;
  try { if (localStorage.getItem('fundus-zen') === '1') h.classList.add('fundus-zen'); } catch (e) {}
  // War der Chat als Seitenleiste offen, rückt der Inhalt schon vor dem ersten Zeichnen zur Seite (wiki.js: kiChat).
  try {
    var ki = JSON.parse(sessionStorage.getItem('fundus-ki') || 'null');
    var breite = parseInt(localStorage.getItem('fundus-ki-breite') || '', 10);
    if (breite >= 320) h.style.setProperty('--ki-breite', breite + 'px');
    if (ki && ki.offen && !ki.gross) {
      h.classList.add('fundus-ki-offen');
      if (window.innerWidth - (breite >= 320 ? breite : 420) < 1400) h.classList.add('fundus-ki-eng');
    }
  } catch (e) {}
  h.classList.add('fundus-laedt');
  setTimeout(function () { h.classList.remove('fundus-laedt'); }, 2500);
})();</script>
{{-- Personen für Profilbilder und Titel neben Namen (wiki.js: personenSchmuecken). Nur Name, Profiladresse,
     hochgeladenes Bild und Titel (aus dem Anmeldedienst, sonst theme/fundus/titel.php), keine E-Mail-Adressen.
     Nicht für Gäste bei öffentlichem Zugriff: Die Liste nennt alle Konten, auch solche ohne Artikel. --}}
@if(user()->hasAppAccess() && !user()->isGuest())
    @php
        $konten = \BookStack\Users\Models\User::query()->whereNull('system_name')->limit(1000)
            ->get(['id', 'name', 'slug', 'email', 'image_id']);
        $titel = \FundusAnmeldung\Anmeldung::titelFuer($konten);
        $personen = $konten->mapWithKeys(fn ($p) => [$p->slug => [
                'name' => $p->name,
                'bild' => $p->image_id ? $p->getAvatar(64) : null,
                'titel' => $titel[$p->id]['titel'] ?? null,
                'stufe' => $titel[$p->id]['stufe'] ?? 'team',
            ]]);
    @endphp
    <script type="application/json" id="fundus-personen">{!! json_encode(['ich' => user()->slug, 'personen' => $personen], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endif
{{-- Das Skript steht direkt in der Seite: BookStack rät den Dateityp von Theme-Dateien aus dem
     Inhalt und hielt wiki.js für HTML; der Browser hätte es dann nicht ausgeführt. --}}
<script nonce="{{ $cspNonce ?? '' }}">{!! file_get_contents(theme_path('public/wiki.js')) !!}</script>
