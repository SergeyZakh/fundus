{{-- Gibt das SVG zum Schlagwort „Symbol“ eines Regals oder Buchs aus (themes/fundus/symbole),
     sonst nichts. Genutzt von fundus/symbol (Kacheln) und fundus/start (Startseite). --}}
@php
    $symbolName = strtolower(trim((string) optional($entity->tags->first(fn ($t) => strcasecmp($t->name, 'Symbol') === 0))->value));
    $symbolDatei = preg_match('/^[a-z0-9-]+$/', $symbolName) ? theme_path("symbole/{$symbolName}.svg") : null;
@endphp
@if($symbolDatei && is_file($symbolDatei))
{!! file_get_contents($symbolDatei) !!}
@endif
