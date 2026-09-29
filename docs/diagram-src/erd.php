<?php
/**
 * VulcaTrack ERD (paper version) — generated from docs/ERD/schema.dbml.
 *
 * Tables, columns and PK / FK / UNIQUE markers are READ FROM schema.dbml, so the
 * picture cannot drift from the schema; only the layout (table positions and
 * connector routes) and the relationship cardinalities below are written here.
 * Cardinalities follow the FK nullability in schema.dbml (Decisions 14, 70-76):
 * a nullable FK is drawn optional ("zero or one") on the parent side.
 *
 *   php docs/diagram-src/erd.php > docs/ERD/VulcaTrack-ERD_1.svg
 */
require __DIR__ . '/lib.php';

// ---- parse schema.dbml ------------------------------------------------------
$dbml = file_get_contents(__DIR__ . '/../ERD/schema.dbml');
preg_match_all('/^Table (\w+) \{(.*?)^\}/ms', $dbml, $tm, PREG_SET_ORDER);
$tables = [];
foreach ($tm as [, $name, $body]) {
    $cols = [];
    foreach (explode("\n", $body) as $line) {
        if (!preg_match('/^\s+(\w+)\s+([\w(),]+)\s*(\[(.*)\])?\s*$/', $line, $c) || $c[1] === 'Note') {
            continue;
        }
        $set = $c[4] ?? '';
        $set = preg_replace("/note:\s*('([^'\\\\]|\\\\.)*'|\"[^\"]*\")/", '', $set); // drop notes
        $cols[] = [
            'name' => $c[1],
            'pk'   => (bool) preg_match('/\bpk\b/', $set),
            'fk'   => (bool) preg_match('/\bref:/', $set),
            'uq'   => (bool) preg_match('/\bunique\b/', $set),
        ];
    }
    $tables[$name] = $cols;
}
if (count($tables) !== 8) {
    fwrite(STDERR, 'expected 8 tables in schema.dbml, found ' . count($tables) . "\n");
    exit(1);
}

// ---- layout (units: 1360 wide ≈ a 9-inch landscape page → 20u text ≈ 9.5 pt) -
const TW = 330, HEAD = 38, ROW = 28, TOP = 130;
$pos = [ // table => [x, y]
    'customers'        => [40, TOP],
    'vehicles'         => [40, TOP + 38 + 8 * 28 + 60],
    'tiremen'          => [40, TOP + 2 * 38 + 17 * 28 + 120],
    'service_requests' => [520, TOP],
    'admins'           => [520, TOP + 38 + 15 * 28 + 60],
    'sales'            => [1000, TOP],
    'sale_items'       => [1000, TOP + 38 + 7 * 28 + 60],
    'items'            => [1000, TOP + 2 * 38 + 13 * 28 + 120],
];
$W = 1360;
$H = max(
    max(array_map(fn ($t) => $pos[$t][1] + HEAD + count($tables[$t]) * ROW, array_keys($pos))) + 40,
    $pos['admins'][1] + HEAD + count($tables['admins']) * ROW + 42 + 94 + 3 * 22 + 30 // legend under admins
);

/** y of the centre of a column row in a table. */
$rowY = function (string $t, string $col) use ($tables, $pos): float {
    foreach ($tables[$t] as $i => $c) {
        if ($c['name'] === $col) {
            return $pos[$t][1] + HEAD + ROW * $i + ROW / 2;
        }
    }
    throw new RuntimeException("no column {$t}.{$col} in schema.dbml");
};
$top    = fn (string $t): float => $pos[$t][1];
$bottom = fn (string $t): float => $pos[$t][1] + HEAD + count($tables[$t]) * ROW;
$left   = fn (string $t): float => $pos[$t][0];
$right  = fn (string $t): float => $pos[$t][0] + TW;
$midX   = fn (string $t): float => $pos[$t][0] + TW / 2;

// ---- crow's-foot end symbols -------------------------------------------------
/** Symbol at $p (on the table edge), $q = the next point along the connector. */
function endmark(array $p, array $q, string $kind): string
{
    [$px, $py] = $p; [$qx, $qy] = $q;
    $len = hypot($qx - $px, $qy - $py) ?: 1;
    $ux = ($qx - $px) / $len; $uy = ($qy - $py) / $len;      // away from the table
    $nx = -$uy; $ny = $ux;                                     // perpendicular
    $at = fn (float $d, float $s = 0) => [$px + $ux * $d + $nx * $s, $py + $uy * $d + $ny * $s];
    $bar = function (float $d) use ($at) { [$a, $b] = $at($d, -9); [$c, $e] = $at($d, 9); return poly([[$a, $b], [$c, $e]], ['sw' => 2.2]); };
    $circle = function (float $d) use ($at) { [$x, $y] = $at($d); return '<circle cx="' . r($x) . '" cy="' . r($y) . '" r="6" fill="#fff" stroke="#1a1a1a" stroke-width="2.2"/>' . "\n"; };
    $crow = function () use ($at) { $o = ''; foreach ([-10, 0, 10] as $s) { $o .= poly([$at(18), $at(0, $s)], ['sw' => 2.2]); } return $o; };
    switch ($kind) {
        case 'one':         return $bar(8) . $bar(15);                 // exactly one
        case 'zero_or_one': return $bar(8) . $circle(22);
        case 'one_or_many': return $crow() . $bar(24);
        case 'many':        return $crow() . $circle(30);              // zero or many
    }
    return '';
}

/** A relationship: orthogonal route from parent edge to child edge. */
function rel(array $pts, string $parentKind, string $childKind): string
{
    $n = count($pts);
    return poly($pts, ['sw' => 2.2])
        . endmark($pts[0], $pts[1], $parentKind)
        . endmark($pts[$n - 1], $pts[$n - 2], $childKind);
}

// ---- draw --------------------------------------------------------------------
$out = svg_open($W, $H);
$out .= txt($W / 2, 46, 'VulcaTrack: Sales and Inventory with On-the-Go Services', 30, ['anchor' => 'middle', 'weight' => '700']);
$out .= txt($W / 2, 78, 'Entity-Relationship Diagram — the 8 database tables (from docs/ERD/schema.dbml)', 19, ['anchor' => 'middle', 'fill' => '#444']);

// relationships first, so tables sit on top of line ends
$lane = 102;
$out .= rel([[$midX('customers'), $bottom('customers')], [$midX('customers'), $top('vehicles')]], 'one', 'many');
$out .= rel([[$right('customers'), $rowY('customers', 'customer_id')], [400, $rowY('customers', 'customer_id')], [400, $rowY('service_requests', 'customer_id')], [$left('service_requests'), $rowY('service_requests', 'customer_id')]], 'one', 'many');
$out .= rel([[$right('vehicles'), $rowY('vehicles', 'vehicle_id')], [445, $rowY('vehicles', 'vehicle_id')], [445, $rowY('service_requests', 'vehicle_id')], [$left('service_requests'), $rowY('service_requests', 'vehicle_id')]], 'one', 'many');
$out .= rel([[$right('tiremen'), $rowY('tiremen', 'tireman_id')], [490, $rowY('tiremen', 'tireman_id')], [490, $rowY('service_requests', 'tireman_id')], [$left('service_requests'), $rowY('service_requests', 'tireman_id')]], 'zero_or_one', 'many');
$out .= rel([[$midX('customers'), $top('customers')], [$midX('customers'), $lane], [$midX('sales'), $lane], [$midX('sales'), $top('sales')]], 'zero_or_one', 'many');
$out .= rel([[$midX('admins'), $top('admins')], [$midX('admins'), $bottom('service_requests')]], 'zero_or_one', 'many');
$out .= rel([[$right('service_requests'), $rowY('service_requests', 'request_id')], [910, $rowY('service_requests', 'request_id')], [910, $rowY('sales', 'service_request_id')], [$left('sales'), $rowY('sales', 'service_request_id')]], 'zero_or_one', 'zero_or_one');
$out .= rel([[$right('admins'), $rowY('admins', 'admin_id')], [955, $rowY('admins', 'admin_id')], [955, $rowY('sales', 'admin_id')], [$left('sales'), $rowY('sales', 'admin_id')]], 'one', 'many');
$out .= rel([[$midX('sales'), $bottom('sales')], [$midX('sales'), $top('sale_items')]], 'one', 'one_or_many');
$out .= rel([[$midX('items'), $top('items')], [$midX('items'), $bottom('sale_items')]], 'one', 'many');

foreach ($pos as $t => [$x, $y]) {
    $h = HEAD + count($tables[$t]) * ROW;
    $out .= rect($x, $y, TW, $h, ['sw' => 2.2]);
    $out .= rect($x, $y, TW, HEAD, ['fill' => '#1f2933', 'stroke' => '#1f2933', 'sw' => 2.2]);
    $out .= txt($x + TW / 2, $y + HEAD / 2, $t, 22, ['anchor' => 'middle', 'weight' => '700', 'fill' => '#fff', 'middle' => true]);
    $out .= poly([[$x + 70, $y + HEAD], [$x + 70, $y + $h]], ['sw' => 1.2, 'stroke' => '#9aa1a8']);
    foreach ($tables[$t] as $i => $c) {
        $cy = $y + HEAD + ROW * $i + ROW / 2;
        if ($i > 0) {
            $out .= poly([[$x, $y + HEAD + ROW * $i], [$x + TW, $y + HEAD + ROW * $i]], ['sw' => 0.8, 'stroke' => '#d5d9dd']);
        }
        $keys = array_filter([$c['pk'] ? 'PK' : '', $c['fk'] ? 'FK' : '', $c['uq'] ? 'UQ' : '']);
        if ($keys) {
            $out .= txt($x + 35, $cy, implode(',', $keys), count($keys) > 1 ? 14 : 16, ['anchor' => 'middle', 'weight' => '700', 'middle' => true]);
        }
        $out .= txt($x + 80, $cy, $c['name'], 20, ['weight' => $c['pk'] ? '700' : '400', 'middle' => true]);
    }
}

// legend (free area under admins)
$lx = 520; $ly = $bottom('admins') + 42;
$out .= txt($lx, $ly, 'Legend', 19, ['weight' => '700']);
$sym = function (float $x, float $y, string $kind, string $label) {
    return poly([[$x, $y], [$x + 60, $y]], ['sw' => 2.2]) . endmark([$x + 60, $y], [$x, $y], $kind)
        . txt($x + 72, $y, $label, 17, ['middle' => true]);
};
$out .= $sym($lx, $ly + 28, 'one', 'exactly one') . $sym($lx + 230, $ly + 28, 'zero_or_one', 'zero or one');
$out .= $sym($lx, $ly + 58, 'one_or_many', 'one or many') . $sym($lx + 230, $ly + 58, 'many', 'zero or many');
$out .= txt($lx, $ly + 94, [
    'PK primary key · FK foreign key · UQ unique',
    'sales.customer_id NULL = walk-in sale',
    'sales.service_request_id NULL = in-shop sale;',
    'UNIQUE: at most one sale per Rescue request',
], 17, ['lh' => 22]);

echo $out . "</svg>\n";
