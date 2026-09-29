<?php
/**
 * Tiny SVG helpers shared by the VulcaTrack paper-diagram generators.
 *
 * Each generator (erd.php, activity-otg.php, dfd-level1.php, sequence-pos.php)
 * prints one SVG to stdout; build.sh writes it next to the old file names and
 * renders the high-resolution PNG. Plain PHP, no dependencies.
 *
 * Sizing rule used by every diagram (paper readability): the canvas is sized for
 * the page it goes on — about 648 pt wide on a landscape page or 468 pt on a
 * portrait page — so FONT / CANVAS WIDTH, not the raw pixel size, decides how big
 * the printed text is. Body text is kept at ≈ 9–10 pt once printed.
 */

const FONT = "Segoe UI, Arial, Helvetica, sans-serif";

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Multi-line text. $lines: string (use "\n") or array. $y is the first baseline. */
function txt(float $x, float $y, $lines, float $size, array $o = []): string
{
    $lines  = is_array($lines) ? $lines : explode("\n", $lines);
    $anchor = $o['anchor'] ?? 'start';
    $weight = $o['weight'] ?? '400';
    $fill   = $o['fill'] ?? '#111';
    $lh     = $o['lh'] ?? $size * 1.22;
    $style  = !empty($o['italic']) ? ' font-style="italic"' : '';
    if (!empty($o['halo'])) { // white outline so a label stays readable where it crosses a line
        $style .= ' stroke="#fff" stroke-width="6" stroke-linejoin="round" paint-order="stroke"';
    }
    if (!empty($o['middle'])) { // vertically centre the block on $y
        $y = $y - ($lh * (count($lines) - 1)) / 2 + $size * 0.35;
    }
    $out = sprintf('<text x="%s" y="%s" font-size="%s" font-weight="%s" fill="%s" text-anchor="%s"%s>',
        r($x), r($y), r($size), $weight, $fill, $anchor, $style);
    foreach ($lines as $i => $line) {
        $out .= sprintf('<tspan x="%s" dy="%s">%s</tspan>', r($x), $i === 0 ? 0 : r($lh), esc($line));
    }
    return $out . "</text>\n";
}

function rect(float $x, float $y, float $w, float $h, array $o = []): string
{
    return sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="%s" fill="%s" stroke="%s" stroke-width="%s"%s/>' . "\n",
        r($x), r($y), r($w), r($h), r($o['rx'] ?? 0), $o['fill'] ?? '#fff', $o['stroke'] ?? '#1a1a1a',
        r($o['sw'] ?? 2), isset($o['dash']) ? ' stroke-dasharray="' . $o['dash'] . '"' : '');
}

/** Polyline through points [[x,y],...]; $o['end'] / $o['start'] = marker id. */
function poly(array $pts, array $o = []): string
{
    $d = [];
    foreach ($pts as $i => [$x, $y]) {
        $d[] = ($i === 0 ? 'M' : 'L') . r($x) . ',' . r($y);
    }
    return sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="%s"%s%s%s/>' . "\n",
        implode(' ', $d), $o['stroke'] ?? '#1a1a1a', r($o['sw'] ?? 2),
        isset($o['end']) ? ' marker-end="url(#' . $o['end'] . ')"' : '',
        isset($o['start']) ? ' marker-start="url(#' . $o['start'] . ')"' : '',
        isset($o['dash']) ? ' stroke-dasharray="' . $o['dash'] . '"' : '');
}

function r(float $v): string
{
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
}

function svg_open(int $w, int $h, string $defs = ''): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h
        . '" font-family="' . FONT . "\">\n<defs>\n" . $defs . "</defs>\n"
        . '<rect width="' . $w . '" height="' . $h . "\" fill=\"#ffffff\"/>\n";
}

/** A filled triangular arrowhead marker. */
function arrow_marker(string $id, string $fill = '#1a1a1a', float $size = 10): string
{
    return '<marker id="' . $id . '" viewBox="0 0 12 12" refX="11" refY="6" markerWidth="' . r($size) . '" markerHeight="' . r($size)
        . '" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M0,0 L12,6 L0,12 z" fill="' . $fill . "\"/></marker>\n";
}
