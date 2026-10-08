<?php
/** Current Level 0 / context DFD: one process, two external entities. */
require __DIR__ . '/lib.php';

const W = 1600, H = 850;
$ink = '#193e66';
$out = svg_open(W, H, arrow_marker('flow', '#26323b', 13));
$out .= txt(800, 48, 'VulcaTrack: Sales and Inventory with On-the-Go Services', 31, ['anchor' => 'middle', 'weight' => '700']);
$out .= txt(800, 86, 'Context Diagram — Data Flow Diagram (Level 0)', 23, ['anchor' => 'middle', 'fill' => '#555']);
$out .= rect(70, 350, 260, 150, ['fill' => '#eef1f4', 'stroke' => '#26323b', 'sw' => 2]);
$out .= txt(200, 433, 'Customer', 29, ['anchor' => 'middle', 'weight' => '700']);
$out .= rect(1270, 350, 260, 150, ['fill' => '#eef1f4', 'stroke' => '#26323b', 'sw' => 2]);
$out .= txt(1400, 433, 'Admin', 29, ['anchor' => 'middle', 'weight' => '700']);
$out .= '<circle cx="800" cy="425" r="157" fill="#fff" stroke="' . $ink . '" stroke-width="3"/>' . "\n";
$out .= txt(800, 405, ['VulcaTrack', 'System'], 30, ['anchor' => 'middle', 'middle' => true, 'lh' => 38, 'weight' => '700']);
$out .= poly([[330, 390], [644, 390]], ['sw' => 2.5, 'end' => 'flow']);
$out .= poly([[644, 460], [330, 460]], ['sw' => 2.5, 'end' => 'flow']);
$out .= poly([[1270, 390], [956, 390]], ['sw' => 2.5, 'end' => 'flow']);
$out .= poly([[956, 460], [1270, 460]], ['sw' => 2.5, 'end' => 'flow']);
$out .= txt(490, 210, [
    'Registration and Log In details',
    'Profile and Vehicle information',
    'Rescue request and location',
    'Completed Rescue feedback',
], 20, ['anchor' => 'middle', 'lh' => 29]);
$out .= txt(490, 565, [
    'Account and Vehicle views',
    'Booking confirmation and frozen ETA',
    'Rescue status, bookings and assigned Tireman',
], 20, ['anchor' => 'middle', 'lh' => 29]);
$out .= txt(1110, 210, [
    'Admin Log In and account creation details',
    'Inventory and Tireman information',
    'POS sale and cash tender details',
    'Rescue management decisions',
], 20, ['anchor' => 'middle', 'lh' => 29]);
$out .= txt(1110, 565, [
    'Account, Inventory and Tireman views',
    'Rescue queue and request details',
    'Sale result and printable Transaction Summary',
    'Sales History and Reports',
], 20, ['anchor' => 'middle', 'lh' => 29]);
$out .= txt(800, 786, 'Tiremen are assigned service-provider records; they do not log in or exchange data with the application.', 18, ['anchor' => 'middle', 'italic' => true, 'fill' => '#555']);
echo $out . "</svg>\n";
