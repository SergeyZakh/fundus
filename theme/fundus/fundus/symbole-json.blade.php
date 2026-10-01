{{-- Symbole (Schlagwort „Symbol“) der Themen und Bereiche als JSON, damit wiki.js sie auch in den
     kompakten Listen der Seitenleisten zeigen kann. Nur Kennung → SVG, keine Namen oder Inhalte, und nur für das,
     was die Person lesen darf: Sonst verrieten Kennung und Symbol („kunde“) Themen, die sie nicht sehen soll. --}}
@php
    $symbolTags = \BookStack\Activity\Models\Tag::query()
        ->whereRaw('LOWER(name) = ?', ['symbol'])
        ->where(fn ($q) => $q
            ->where(fn ($b) => $b->where('entity_type', 'book')
                ->whereIn('entity_id', \BookStack\Entities\Models\Book::query()->scopes('visible')->select('id')))
            ->orWhere(fn ($r) => $r->where('entity_type', 'bookshelf')
                ->whereIn('entity_id', \BookStack\Entities\Models\Bookshelf::query()->scopes('visible')->select('id'))))
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
