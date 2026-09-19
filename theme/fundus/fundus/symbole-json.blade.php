{{-- Symbole (Schlagwort „Symbol“) aller Themen und Bereiche als JSON, damit wiki.js sie auch in den
     kompakten Listen der Seitenleisten zeigen kann. Nur Kennung → SVG, keine Namen oder Inhalte. --}}
@php
    $symbolTags = \BookStack\Activity\Models\Tag::query()
        ->whereIn('entity_type', ['book', 'bookshelf'])
        ->whereRaw('LOWER(name) = ?', ['symbol'])
        ->get(['entity_type', 'entity_id', 'value']);
    $svgs = [];
    $zuordnung = [];
    foreach ($symbolTags as $tag) {
        $name = strtolower(trim((string) $tag->value));
        $datei = preg_match('/^[a-z0-9-]+$/', $name) ? theme_path("symbole/{$name}.svg") : null;
        if (!$datei || !is_file($datei)) {
            continue;
        }
        $svgs[$name] ??= trim(file_get_contents($datei));
        $zuordnung[$tag->entity_type . ':' . $tag->entity_id] = $name;
    }
@endphp
<script type="application/json" id="fundus-symbole">{!! json_encode(['svg' => $svgs, 'zu' => $zuordnung], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
