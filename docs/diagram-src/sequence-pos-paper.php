<?php
/** Conceptual Process Sale (POS) sequence for the paper. */
require __DIR__ . '/lib.php';

const W = 1500, H = 1310;
$x = ['Admin' => 160, 'POS' => 750, 'Database' => 1340];
$out = svg_open(W, H, arrow_marker('call', '#20252a', 13) . arrow_marker('return', '#555', 13));
$out .= txt(750, 43, 'VulcaTrack: Sales and Inventory with On-the-Go Services', 26, ['anchor' => 'middle', 'weight' => '700']);
$out .= txt(750, 78, 'Sequence Diagram — Process Sale (POS)', 22, ['anchor' => 'middle']);
foreach ($x as $name => $cx) {
    $out .= rect($cx - 135, 115, 270, 60, ['rx' => 6, 'fill' => '#f8db74', 'stroke' => '#aa8421', 'sw' => 2]);
    $out .= txt($cx, 152, $name === 'POS' ? 'VulcaTrack POS System' : $name, 21, ['anchor' => 'middle', 'weight' => '700']);
    $out .= poly([[$cx, 175], [$cx, 1230]], ['stroke' => '#777', 'sw' => 1.6, 'dash' => '8 6']);
}
$message = function (string $from, string $to, int $y, string $label, bool $return = false) use ($x): string {
    $a = $x[$from]; $b = $x[$to]; $dir = $a < $b ? 1 : -1;
    return poly([[$a + $dir * 8, $y], [$b - $dir * 8, $y]], $return
        ? ['stroke' => '#555', 'sw' => 2, 'dash' => '9 6', 'end' => 'return']
        : ['sw' => 2.2, 'end' => 'call'])
        . txt(($a + $b) / 2, $y - 12, $label, 20, ['anchor' => 'middle', 'halo' => true]);
};
$out .= $message('Admin', 'POS', 235, 'Add products/services and quantities');
$out .= $message('POS', 'Database', 320, 'Get item details, current price and stock');
$out .= $message('Database', 'POS', 405, 'Return price and available stock', true);
$out .= $message('POS', 'Admin', 490, 'Show cart and total', true);
$out .= $message('Admin', 'POS', 575, 'Optional registered Customer; otherwise Walk-in');
$out .= $message('Admin', 'POS', 660, 'Enter cash tender and complete Sale');
$out .= rect(574, 700, 352, 51, ['rx' => 8, 'fill' => '#dcebf6', 'stroke' => '#315f7c', 'sw' => 2]);
$out .= txt(750, 733, 'Validate checkout input', 20, ['anchor' => 'middle', 'weight' => '600']);
$out .= poly([[750, 660], [750, 700]], ['sw' => 2, 'end' => 'call']);
$out .= rect(55, 780, 1390, 440, ['fill' => 'none', 'stroke' => '#315f7c', 'sw' => 2]);
$out .= rect(55, 780, 105, 37, ['fill' => '#dcebf6', 'stroke' => '#315f7c', 'sw' => 2]);
$out .= txt(76, 805, 'alt', 20, ['weight' => '700']);
$out .= txt(172, 806, '[Valid]', 18, ['italic' => true]);
$out .= $message('POS', 'Database', 851, 'Record Sale transaction');
$out .= $message('Database', 'POS', 921, 'Return Sale success', true);
$out .= $message('POS', 'Admin', 991, 'Show Sale number, total and change', true);
$out .= $message('Admin', 'POS', 1051, 'Open Transaction Summary');
$out .= $message('POS', 'Admin', 1101, 'Show printable Transaction Summary', true);
$out .= poly([[55, 1130], [1445, 1130]], ['sw' => 1.6, 'dash' => '8 6', 'stroke' => '#315f7c']);
$out .= txt(172, 1155, '[Invalid]', 18, ['italic' => true]);
$out .= $message('POS', 'Admin', 1190, 'Show checkout errors; no Sale recorded', true);
$out .= txt(750, 1270, 'Cash tender and change are displayed for checkout; they are not stored as Sale history.', 17, ['anchor' => 'middle', 'fill' => '#555']);
echo $out . "</svg>\n";
