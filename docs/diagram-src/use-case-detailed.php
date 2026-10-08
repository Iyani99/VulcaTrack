<?php
/** Comprehensive current Customer/Admin use cases for technical documentation. */
require __DIR__ . '/lib.php';

const W = 1700, H = 1570;
$out = svg_open(W, H);
$out .= txt(850, 48, 'VulcaTrack: Sales and Inventory with On-the-Go Services', 30, ['anchor' => 'middle', 'weight' => '700']);
$out .= txt(850, 85, 'Use Case Diagram — Technical System Coverage', 23, ['anchor' => 'middle', 'fill' => '#555']);
$out .= rect(330, 110, 1040, 1400, ['rx' => 14, 'fill' => '#fff', 'stroke' => '#41535c', 'sw' => 2.5]);
$out .= txt(850, 146, 'VulcaTrack System', 24, ['anchor' => 'middle', 'weight' => '700']);

$actor = function (int $x, string $name, string $color): string {
    return '<g stroke="' . $color . '" stroke-width="3" fill="none"><circle cx="' . $x . '" cy="736" r="24"/>'
        . '<path d="M' . $x . ',760 V831 M' . ($x - 42) . ',789 H' . ($x + 42) . ' M' . $x . ',831 L' . ($x - 34) . ',890 M' . $x . ',831 L' . ($x + 34) . ',890"/></g>' . "\n"
        . txt($x, 928, $name, 24, ['anchor' => 'middle', 'weight' => '700', 'fill' => $color]);
};
$oval = function (int $x, int $y, int $w, string $label, string $color): string {
    return '<ellipse cx="' . $x . '" cy="' . $y . '" rx="' . ($w / 2) . '" ry="36" fill="#fff" stroke="' . $color . '" stroke-width="2.3"/>' . "\n"
        . txt($x, $y + 7, $label, 19, ['anchor' => 'middle']);
};
$out .= $actor(115, 'Customer', '#1b62a9') . $actor(1585, 'Admin', '#bf3030');

$customer = [
    [300, 'Register'],
    [440, 'Manage Profile'],
    [580, 'Manage Saved Vehicles'],
    [720, 'Book a Rescue'],
    [860, 'View Rescue Status and ETA'],
    [1000, 'View Rescue History'],
    [1140, 'Submit Rescue Feedback'],
];
$admin = [
    [280, 'View Dashboard'],
    [375, 'Manage Inventory'],
    [470, 'Process Sale (POS)'],
    [565, 'View Transaction Summary'],
    [660, 'View Sales History'],
    [755, 'View Sales Reports'],
    [850, 'Manage Rescue Requests'],
    [945, 'Assign or Reassign Tireman'],
    [1040, 'Manage Tiremen'],
    [1135, 'View Rescue Feedback'],
    [1230, 'Manage Admin Accounts'],
];

// Shared association buses keep the larger technical figure readable.
$out .= poly([[157, 800], [278, 800]], ['stroke' => '#9eafb8', 'sw' => 1.7]);
$out .= poly([[1543, 800], [1422, 800]], ['stroke' => '#9eafb8', 'sw' => 1.7]);
$out .= poly([[278, 205], [278, 1450]], ['stroke' => '#9eafb8', 'sw' => 1.7]);
$out .= poly([[1422, 205], [1422, 1450]], ['stroke' => '#9eafb8', 'sw' => 1.7]);
foreach ($customer as [$y, $label]) {
    $out .= poly([[278, $y], [425, $y]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
    $out .= $oval(620, $y, 390, $label, '#1b62a9');
}
foreach ($admin as [$y, $label]) {
    $out .= poly([[1422, $y], [1275, $y]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
    $out .= $oval(1080, $y, 390, $label, '#bf3030');
}
$out .= poly([[278, 205], [735, 205]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
$out .= poly([[1422, 205], [965, 205]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
$out .= $oval(850, 205, 230, 'Log In', '#41535c');
$out .= poly([[278, 1450], [735, 1450]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
$out .= poly([[1422, 1450], [965, 1450]], ['stroke' => '#9eafb8', 'sw' => 1.5]);
$out .= $oval(850, 1450, 230, 'Log Out', '#41535c');
$out .= txt(850, 1540, 'Tiremen are managed and assigned by Admin; they have no application account, login or dashboard.', 18, ['anchor' => 'middle', 'italic' => true, 'fill' => '#555']);
echo $out . "</svg>\n";
