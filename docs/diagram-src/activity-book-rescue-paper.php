<?php
/** Book a Rescue activity for an already authenticated Customer. */
require __DIR__ . '/lib.php';

const W = 1100, H = 1580;
$blue = '#dcebf6'; $edge = '#315f7c'; $ink = '#20252a';
$out = svg_open(W, H, arrow_marker('flow', $ink, 13));
$out .= txt(550, 43, 'VulcaTrack: Sales and Inventory with On-the-Go Services', 26, ['anchor' => 'middle', 'weight' => '700']);
$out .= txt(550, 78, 'Activity Diagram — Book a Rescue', 22, ['anchor' => 'middle']);
$out .= txt(550, 107, 'Precondition: Customer is already logged in', 17, ['anchor' => 'middle', 'fill' => '#555']);
foreach ([['Customer', 0, 520], ['VulcaTrack System', 520, 580]] as [$name, $x, $w]) {
    $out .= rect($x + 2, 126, $w - 4, 1445, ['stroke' => '#777', 'sw' => 1.5]);
    $out .= rect($x + 2, 126, $w - 4, 52, ['fill' => '#eef1f4', 'stroke' => '#777', 'sw' => 1.5]);
    $out .= txt($x + $w / 2, 159, $name, 23, ['anchor' => 'middle', 'weight' => '700']);
}
$box = function (float $x, float $y, float $w, array $lines) use ($blue, $edge): string {
    $h = count($lines) > 3 ? 112 : (count($lines) > 2 ? 89 : 72);
    return rect($x - $w / 2, $y - $h / 2, $w, $h, ['rx' => 15, 'fill' => $blue, 'stroke' => $edge, 'sw' => 2])
        . txt($x, $y, $lines, 20, ['anchor' => 'middle', 'middle' => true, 'lh' => 23]);
};
$arrow = fn (array $p): string => poly($p, ['sw' => 2.2, 'end' => 'flow']);
$out .= $arrow([[260, 202], [260, 225]]);
foreach ([[297, 316], [388, 441], [513, 557], [647, 691]] as [$a, $b]) {
    $out .= $arrow([[260, $a], [260, $b]]);
}
$out .= $arrow([[445, 727], [805, 727], [805, 770]]);
$out .= $arrow([[805, 800], [805, 844]]);
$out .= $arrow([[805, 916], [805, 938]]);
$out .= $arrow([[700, 982], [650, 982], [650, 1089]]);
$out .= txt(660, 967, '[Invalid]', 17, ['italic' => true, 'anchor' => 'middle']);
$out .= $arrow([[540, 1125], [445, 1125]]);
$out .= $arrow([[75, 1125], [35, 1125], [35, 785], [790, 785]]);
$out .= $arrow([[805, 1027], [920, 1027], [920, 1089]]);
$out .= txt(856, 1052, '[Valid]', 17, ['italic' => true, 'anchor' => 'middle']);
$out .= $arrow([[920, 1161], [920, 1224]]);
$out .= $arrow([[795, 1260], [490, 1260], [490, 1400], [445, 1400]]);
$out .= $arrow([[260, 1436], [260, 1497]]);

$out .= '<circle cx="260" cy="194" r="12" fill="' . $ink . '"/>' . "\n";
$out .= $box(260, 261, 370, ['Open Book a Rescue']);
$out .= $box(260, 352, 370, ['Choose an active saved Vehicle']);
$out .= $box(260, 477, 370, ['Describe the problem']);
$out .= $box(260, 602, 370, ['Provide location:', 'browser geolocation or', 'landmark/address search', 'map-marker adjustment is optional']);
$out .= $box(260, 727, 370, ['Confirm contact number on file', 'and submit request']);
$out .= '<circle cx="805" cy="785" r="15" fill="' . $blue . '" stroke="' . $edge . '" stroke-width="2"/>' . "\n";
$out .= $box(805, 880, 390, ['Validate Customer, Vehicle', 'and request details']);
$out .= '<path d="M805,938 L910,982 L805,1027 L700,982 z" fill="' . $blue . '" stroke="' . $edge . '" stroke-width="2"/>' . "\n";
$out .= txt(805, 988, 'Valid request?', 18, ['anchor' => 'middle', 'weight' => '600']);
$out .= $box(650, 1125, 220, ['Show validation', 'errors']);
$out .= $box(260, 1125, 370, ['Correct errors and resubmit']);
$out .= $box(920, 1125, 250, ['Compute ETA once']);
$out .= $box(920, 1260, 250, ['Save Rescue request', 'as Pending']);
$out .= $box(260, 1400, 370, ['View confirmation, ETA', 'and Pending status']);
$out .= '<circle cx="260" cy="1514" r="17" fill="#fff" stroke="' . $ink . '" stroke-width="2.5"/>'
    . '<circle cx="260" cy="1514" r="10" fill="' . $ink . '"/>' . "\n";
echo $out . "</svg>\n";
