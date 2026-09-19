{{-- Gibt ein SVG aus themes/fundus/symbole nach Dateinamen aus (ohne Endung), sonst nichts. --}}
@php($symbolDatei = preg_match('/^[a-z0-9-]+$/', $name) ? theme_path("symbole/{$name}.svg") : null)
@if($symbolDatei && is_file($symbolDatei))
{!! file_get_contents($symbolDatei) !!}
@endif
