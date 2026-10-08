<?php
/**
 * VulcaTrack — In-shop POS sale, technical UML sequence diagram.
 * Landscape: 1700 units ≈ a 9-inch page width → 22-unit text ≈ 8.4 pt (≈ 2× the old diagram).
 *
 * Facts (Decisions 14, 16, 17, 30, 31, 50, 54, 55, 61-63): SaleService owns ONE
 * database transaction — lock the item rows, use the authoritative database prices,
 * check stock and the expected total, insert the sale + its lines (frozen unit price),
 * deduct stock for PRODUCT lines only, commit — or roll everything back. Walk-in sales
 * store customer_id NULL. Cash tendered / change are checked and shown once, never
 * stored. The printable document is a Transaction Summary, not an official BIR invoice.
 * Rescue-linked sales use the same POS but are out of this diagram's scope.
 *
 *   php docs/diagram-src/sequence-pos-detailed.php > docs/flows/VulcaTrack-Sequence-Diagram-POS-Detailed.svg
 */
require __DIR__ . '/lib.php';

const W = 1700, F = 22, NOTE = 19;
$P = ['admin' => 100, 'pos' => 560, 'items' => 990, 'sales' => 1290, 'sum' => 1560];
$heads = [
    'pos'   => ["VulcaTrack POS", "(SaleService)"],
    'items' => ['Items', '(items table)'],
    'sales' => ['Sales & Line Items', '(sales, sale_items)'],
    'sum'   => ['Transaction', 'Summary'],
];

// messages: [from, to, label (\n = 2 lines), kind: call|return|self]
$msgs = [
    ['admin', 'pos', 'Add items / services + quantities', 'call'],
    ['admin', 'pos', 'Optional: link a customer (else walk-in)', 'call'],
    ['pos', 'items', 'Read active items & current prices', 'call'],
    ['pos', 'pos', 'Compute cart total', 'self'],
    ['pos', 'admin', 'Show total due', 'return'],
    ['admin', 'pos', 'Enter cash tendered; Complete Sale', 'call'],
    ['pos', 'pos', 'Cash ≥ total? Change is shown, not stored', 'self'],
    '[begin]',
    ['pos', 'items', 'Lock item rows; re-read prices & stock', 'call'],
    ['pos', 'pos', 'Check stock and expected total (refuse if changed)', 'self'],
    ['pos', 'sales', 'Insert sale (date, admin, customer or NULL, total)', 'call'],
    ['pos', 'sales', 'Insert line items (frozen unit price, subtotal)', 'call'],
    ['pos', 'items', 'Deduct stock: product lines only', 'call'],
    ['pos', 'pos', 'Commit (any failure → roll back: nothing saved)', 'self'],
    '[end]',
    ['pos', 'admin', 'Sale #N recorded + cash received, change', 'return'],
    ['admin', 'sum', 'Open Transaction Summary', 'call'],
    ['sum', 'sales', 'Read sale & frozen lines', 'call'],
    ['sum', 'admin', 'Display / print Transaction Summary', 'return'],
];

// ---- layout pass --------------------------------------------------------------
$y = 222; $rows = []; $frag = [];
foreach ($msgs as $m) {
    if ($m === '[begin]') { $frag[0] = $y - 12; $y += 62; continue; }
    if ($m === '[end]')   { $frag[1] = $y - 14; $y += 30; continue; }
    $lines = substr_count($m[2], "\n") + 1;
    $y += ($lines - 1) * 24;
    $rows[] = [$m, $y];
    $y += $m[3] === 'self' ? 58 : 44;
}
$footY = $y + 10;
$H = (int) ($footY + 3 * NOTE * 1.3 + 50);

$out = svg_open(W, $H, arrow_marker('call', '#1a1a1a', 15) . arrow_marker('ret', '#555', 15));
$out .= txt(W / 2, 44, 'VulcaTrack: Sales and Inventory with On-the-Go Services', 28, ['anchor' => 'middle', 'weight' => '700']);
$out .= txt(W / 2, 76, 'Technical Sequence Diagram — In-Shop POS Sale', 20, ['anchor' => 'middle', 'fill' => '#444']);

// participants + lifelines
$lifeEnd = $footY - 20;
foreach ($P as $k => $x) {
    $out .= poly([[$x, $k === 'admin' ? 198 : 164], [$x, $lifeEnd]], ['sw' => 1.6, 'stroke' => '#777', 'dash' => '8 6']);
}
$out .= '<g stroke="#1a1a1a" stroke-width="2.4" fill="none"><circle cx="100" cy="104" r="12"/><path d="M100,116 V142 M82,126 H118 M100,142 L86,162 M100,142 L114,162"/></g>' . "\n";
$out .= txt(100, 188, 'Admin', F, ['anchor' => 'middle', 'weight' => '700']);
foreach ($heads as $k => $lines) {
    $out .= rect($P[$k] - 125, 96, 250, 66, ['fill' => '#f5cf4f', 'stroke' => '#9a7a10', 'rx' => 4, 'sw' => 2]);
    $out .= txt($P[$k], 129, $lines, F, ['anchor' => 'middle', 'weight' => '700', 'middle' => true, 'lh' => 24]);
}

// transaction fragment
[$f0, $f1] = $frag;
$out .= rect($P['pos'] - 150, $f0, $P['sales'] + 90 - ($P['pos'] - 150), $f1 - $f0, ['fill' => 'none', 'stroke' => '#2f5f82', 'sw' => 2]);

// activation bar on the POS for the whole interaction
$out .= rect($P['pos'] - 7, 214, 14, $rows[count($rows) - 4][1] - 210, ['fill' => '#e9ecef', 'stroke' => '#555', 'sw' => 1.4]);

// fragment label tab (drawn over the activation bar so it stays readable)
$fx = $P['pos'] - 150;
$out .= '<path d="M' . $fx . ',' . r($f0) . ' H' . ($fx + 560) . ' V' . r($f0 + 22) . ' L' . ($fx + 542) . ',' . r($f0 + 36) . ' H' . $fx . ' z" fill="#e3eef7" stroke="#2f5f82" stroke-width="2"/>' . "\n";
$out .= txt($fx + 12, $f0 + 26, 'atomic: one database transaction (all or nothing)', 19, ['weight' => '700', 'fill' => '#1d3f59']);

// messages
foreach ($rows as [[$from, $to, $label, $kind], $ly]) {
    $lines = explode("\n", $label);
    $x1 = $P[$from]; $x2 = $P[$to];
    if ($kind === 'self') {
        $out .= poly([[$x1 + 7, $ly - 4], [$x1 + 60, $ly - 4], [$x1 + 60, $ly + 22], [$x1 + 9, $ly + 22]], ['sw' => 2, 'end' => 'call']);
        $out .= txt($x1 + 72, $ly + 9 - (count($lines) - 1) * 12, $lines, F, ['lh' => 24, 'halo' => true]);
        continue;
    }
    $dir = $x2 > $x1 ? 1 : -1;
    $a = $x1 + $dir * 8; $b = $x2 - $dir * 8;
    $out .= poly([[$a, $ly], [$b, $ly]], $kind === 'return'
        ? ['sw' => 2, 'stroke' => '#555', 'dash' => '9 6', 'end' => 'ret']
        : ['sw' => 2.2, 'end' => 'call']);
    $out .= txt(($x1 + $x2) / 2, $ly - 10 - (count($lines) - 1) * 24, $lines, F, ['anchor' => 'middle', 'lh' => 24, 'halo' => true, 'fill' => $kind === 'return' ? '#333' : '#111']);
}

// notes
$out .= rect(30, $footY, W - 60, 3 * NOTE * 1.3 + 22, ['fill' => '#fff8d6', 'stroke' => '#a08a2a', 'sw' => 1.6, 'dash' => '6 4']);
$out .= txt(48, $footY + 26, [
    'Walk-in sale: sales.customer_id is NULL. Unit prices come from the database and are frozen on the sale lines.',
    'Product lines reduce items.stock_quantity; service lines do not. Cash tendered and change are checked and shown, not stored.',
    'The Transaction Summary is for transaction reference only — not an official BIR invoice. (Rescue-linked sales use the same POS.)',
], NOTE, ['lh' => NOTE * 1.3, 'fill' => '#3d3510']);

echo $out . "</svg>\n";
