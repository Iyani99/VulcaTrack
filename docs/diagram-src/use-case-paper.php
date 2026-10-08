<?php
/** Current use cases. Tiremen are managed records, not application actors. */
require __DIR__ . '/lib.php';

const W = 1500, H = 1410;
$out = svg_open(W, H);
$out .= txt(750, 48, 'VulcaTrack: Sales and Inventory with On-the-Go Services', 31, ['anchor' => 'middle', 'weight' => '700']);
$out .= txt(750, 85, 'Use Case Diagram — Customer and Admin', 23, ['anchor' => 'middle', 'fill' => '#555']);
$out .= rect(260, 110, 980, 1220, ['rx' => 15, 'fill' => '#fff', 'stroke' => '#41535c', 'sw' => 2.5]);
$out .= txt(750, 144, 'VulcaTrack System', 24, ['anchor' => 'middle', 'weight' => '700']);
$actor = function (int $x, string $name, string $color): string {
    return '<g stroke="' . $color . '" stroke-width="3" fill="none"><circle cx="' . $x . '" cy="629" r="24"/>'
        . '<path d="M' . $x . ',653 V726 M' . ($x - 42) . ',680 H' . ($x + 42) . ' M' . $x . ',726 L' . ($x - 34) . ',785 M' . $x . ',726 L' . ($x + 34) . ',785"/></g>' . "\n"
        . txt($x, 823, $name, 24, ['anchor' => 'middle', 'weight' => '700', 'fill' => $color]);
};
$out .= $actor(95, 'Customer', '#1b62a9') . $actor(1405, 'Admin', '#bf3030');
$oval = function (int $x, int $y, int $w, string $label, string $color): string {
    return '<ellipse cx="' . $x . '" cy="' . $y . '" rx="' . ($w / 2) . '" ry="42" fill="#fff" stroke="' . $color . '" stroke-width="2.4"/>' . "\n"
        . txt($x, $y + 7, $label, 20, ['anchor' => 'middle']);
};
$customer = [
    [300, 'Register'],
    [440, 'Manage Profile and Vehicles'],
    [580, 'Book a Rescue'],
    [720, 'View Rescue Status / Bookings'],
    [860, 'Submit Rescue Feedback'],
];
$admin = [
    [270, 'Manage Inventory'],
    [390, 'Process Sale (POS)'],
    [510, 'Manage Rescue Requests'],
    [630, 'Assign Tireman to Request'],
    [750, 'Manage Tiremen'],
    [870, 'View Sales History'],
    [990, 'View Sales Reports'],
    [1110, 'Manage Admin Accounts'],
];
foreach ($customer as [$y, $label]) {
    $out .= poly([[112, 700], [352, $y]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
    $out .= $oval(515, $y, 325, $label, '#1b62a9');
}
foreach ($admin as [$y, $label]) {
    $out .= poly([[1388, 700], [1148, $y]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
    $out .= $oval(985, $y, 325, $label, '#bf3030');
}
$out .= poly([[112, 700], [245, 240], [663, 182]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
$out .= poly([[1388, 700], [1255, 240], [837, 182]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
$out .= $oval(750, 209, 225, 'Log In', '#41535c');
$out .= poly([[112, 700], [180, 790], [245, 1190], [663, 1292]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
$out .= poly([[1388, 700], [1320, 790], [1255, 1190], [837, 1292]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
$out .= $oval(750, 1265, 225, 'Log Out', '#41535c');
$out .= txt(750, 1370, 'Tireman is a service-provider record managed by Admin; there is no Tireman login or dashboard.', 18, ['anchor' => 'middle', 'italic' => true, 'fill' => '#555']);
echo $out . "</svg>\n";
