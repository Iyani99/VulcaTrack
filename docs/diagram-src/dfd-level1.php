<?php
/**
 * VulcaTrack — Level 1 Data Flow Diagram (Yourdon / DeMarco notation), paper version.
 * Landscape: 1600 units ≈ a 9-inch page width → 20-unit flow labels ≈ 8 pt and
 * 21-unit process names ≈ 8.5 pt (about 2.2× the previous diagram's text).
 *
 * Facts checked against the committed system (Git HEAD at regeneration time):
 * - External entities: Customer and Admin only. The Tireman is a data record (D7):
 *   no login, no dashboard, so not an external entity.
 * - Stores = the 8 tables; D5 groups sales + sale_items for readability.
 * - P3 also takes the customer's one-time feedback on a completed request and stores
 *   it on the request itself (D6) — there is no feedback store (Decision 78).
 * - Rescue sale (Decisions 70-76): from an eligible (accepted / completed) request,
 *   P4 hands the POS a Rescue sale context; P5 records the sale for that request's
 *   customer and the sale row stores the link (D5). Same POS, same cart.
 * - Cash tendered is an input to P5 only — no store holds it (Decision 54).
 * - The per-sale output is a printable Transaction Summary (not an official receipt).
 * - P7 = Sales History + Sales Reports (Phase 6): read-only over D5.
 * - Web admin-account creation is NOT shown: it is not in the committed system at
 *   the time of this regeneration (the first admin comes from the CLI seed script).
 * - Every flow passes through a process (no entity <-> store flow).
 *
 *   php docs/diagram-src/dfd-level1.php > docs/flows/VulcaTrack-DFD-1-Level1.svg
 */
require __DIR__ . '/lib.php';

const W = 1600, H = 1165, FL = 20, PR = 21, R = 90;
const SW = 210, SH = 44;
$navy = '#1f3a5a';

$procs = [ // id => [cx, cy, name]
    1 => [800, 195, "Authenticate &\nManage Accounts"],
    2 => [420, 330, "Manage\nVehicles"],
    3 => [420, 830, "Process Rescue\nRequest"],
    4 => [820, 820, "Manage Rescue\nRequests"],
    5 => [1190, 590, "Process Sale\n(POS)"],
    6 => [1190, 245, "Manage\nInventory"],
    7 => [1190, 985, "Sales History\n& Reports"],
];
$stores = [ // id => [x, y, label]
    'D1' => [700, 360, 'customers'],
    'D2' => [930, 118, 'admins'],
    'D3' => [320, 500, 'vehicles'],
    'D4' => [1090, 400, 'items'],
    'D5' => [1100, 735, 'sales + sale_items'],
    'D6' => [560, 1050, 'service_requests'],
    'D7' => [860, 1050, 'tiremen'],
];
$entities = ['Customer' => [20, 330, 125, 530], 'Admin' => [1470, 330, 110, 530]]; // x, y, w, h

// flows: [points, label (\n ok), [label x, first baseline y], anchor, double-headed?]
$flows = [
    // Customer <-> P1 (routed over P2), P1 <-> D1 / D2, Admin login over the top
    [[[70, 330], [70, 150], [722, 150]], 'Registration, Login, Profile & Photo', [420, 138], 'middle'],
    [[[711, 210], [110, 210], [110, 330]], 'Account & Profile Details', [585, 238], 'middle'],
    [[[800, 285], [800, 360]], 'Customer Account Data', [812, 328], 'start', true],
    [[[878, 150], [930, 140]], 'Admin Credentials', [1020, 186], 'middle', true],
    [[[1560, 330], [1560, 90], [800, 90], [800, 105]], 'Admin Login Credentials', [1160, 80], 'middle'],
    // Customer <-> P2, P2 <-> D3
    [[[145, 345], [331, 345]], 'Vehicle Details', [240, 334], 'middle'],
    [[[345, 380], [145, 380]], "Saved\nVehicle List", [240, 405], 'middle'],
    [[[420, 420], [420, 500]], 'Vehicle Records', [432, 468], 'start', true],
    // P3: reads D3, Customer in / out, <-> D6, reads D7
    [[[420, 544], [420, 740]], 'Active Vehicle (read)', [432, 650], 'start'],
    [[[145, 790], [340, 790]], "Rescue Request:\nvehicle, problem,\nlocation; Feedback", [155, 712], 'start'],
    [[[344, 870], [80, 870], [80, 860]], "Confirmation, Frozen ETA,\nStatus, Tireman Contact", [22, 902], 'start'],
    [[[470, 905], [590, 1050]], "Request, Frozen ETA,\nStatus, Feedback", [505, 985], 'end', true],
    [[[960, 1094], [960, 1130], [290, 1130], [290, 905], [362, 905]], 'Assigned Tireman Name & Contact (read)', [640, 1118], 'middle'],
    // P4: reads D1, <-> D6, <-> D7, Admin in / out, hands a Rescue sale context to P5
    [[[800, 404], [812, 730]], 'Customer Details (read)', [792, 600], 'end'],
    [[[762, 889], [700, 1050]], "Status, Tireman,\nAdmin Updates", [752, 985], 'start', true],
    [[[878, 889], [930, 1050]], 'Tireman Records', [932, 972], 'start', true],
    [[[1470, 820], [910, 820]], "Accept + Tireman,\nReject, Complete;\nTireman Roster", [925, 760], 'start'],
    [[[903, 855], [1470, 855]], "Pending Requests,\nDetails, Customer\nFeedback", [925, 880], 'start'],
    [[[884, 757], [1106, 625]], "Rescue Sale\nContext", [960, 665], 'end'],
    // P5
    [[[880, 404], [1105, 560]], "Customer Details
(optional link)", [975, 520], 'end'],
    [[[1190, 500], [1190, 444]], "Item Prices (read),\nStock Deduction", [1202, 470], 'start', true],
    [[[1190, 680], [1190, 735]], "Sale & Line Items\n(+ Rescue link)", [1178, 702], 'end', true],
    [[[1279, 600], [1470, 600]], "Total, Change,\nTransaction\nSummary (print)", [1288, 530], 'start'],
    [[[1470, 645], [1261, 645]], "Items, Qty, Cash\nTendered, Customer\nor Rescue Link", [1288, 670], 'start'],
    // P6
    [[[1500, 330], [1500, 215], [1276, 215]], "Product / Service Details,\nStock Updates, Activation", [1296, 168], 'start'],
    [[[1273, 275], [1478, 275], [1478, 330]], "Inventory List,\nLow-Stock Alerts", [1296, 300], 'start'],
    [[[1190, 335], [1190, 400]], 'Item Records', [1202, 372], 'start', true],
    // P7
    [[[1190, 779], [1190, 895]], 'Recorded Sales (read)', [1202, 882], 'start'],
    [[[1280, 985], [1500, 985], [1500, 860]], "Sales History\n& Reports", [1296, 1015], 'start'],
];

$out = svg_open(W, H, arrow_marker('ah', '#2b2f33', 16));
$out .= txt(W / 2, 38, 'VulcaTrack: Sales and Inventory with On-the-Go Services', 28, ['anchor' => 'middle', 'weight' => '700']);
$out .= txt(W / 2, 66, 'Data Flow Diagram — Level 1', 20, ['anchor' => 'middle', 'fill' => '#444']);

foreach ($flows as $f) {
    $out .= poly($f[0], ['sw' => 2.2, 'stroke' => '#2b2f33', 'end' => 'ah'] + (!empty($f[4]) ? ['start' => 'ah'] : []));
}
// entities
foreach ($entities as $n => [$x, $y, $w, $h]) {
    $out .= rect($x, $y, $w, $h, ['fill' => '#eef0f3', 'stroke' => $navy, 'sw' => 3]);
    $out .= txt($x + $w / 2, $y + $h / 2, $n, 23, ['anchor' => 'middle', 'weight' => '700', 'middle' => true]);
}
// processes
foreach ($procs as $id => [$x, $y, $name]) {
    $out .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . R . '" fill="#fff" stroke="' . $navy . '" stroke-width="3"/>' . "\n";
    $out .= '<circle cx="' . $x . '" cy="' . ($y - R + 24) . '" r="15" fill="' . $navy . '"/>' . "\n";
    $out .= txt($x, $y - R + 24, (string) $id, 18, ['anchor' => 'middle', 'weight' => '700', 'fill' => '#fff', 'middle' => true]);
    $out .= txt($x, $y + 12, $name, PR, ['anchor' => 'middle', 'weight' => '600', 'middle' => true, 'lh' => PR * 1.15]);
}
// stores (open-ended rectangles)
foreach ($stores as $id => [$x, $y, $label]) {
    $out .= '<rect x="' . $x . '" y="' . $y . '" width="' . SW . '" height="' . SH . '" fill="#fff" stroke="none"/>' . "\n";
    $out .= '<path d="M' . ($x + SW) . ',' . $y . ' H' . $x . ' V' . ($y + SH) . ' H' . ($x + SW) . '" fill="none" stroke="' . $navy . '" stroke-width="2.6"/>' . "\n";
    $out .= poly([[$x + 50, $y], [$x + 50, $y + SH]], ['sw' => 2.6, 'stroke' => $navy]);
    $out .= txt($x + 25, $y + SH / 2, $id, 19, ['anchor' => 'middle', 'weight' => '700', 'middle' => true]);
    $out .= txt($x + 58, $y + SH / 2, $label, 20, ['middle' => true]);
}
// flow labels last, with a white halo, so no line runs through their text
foreach ($flows as [$pts, $label, [$lx, $ly], $anchor]) {
    $out .= txt($lx, $ly, $label, FL, ['anchor' => $anchor, 'halo' => true, 'lh' => FL * 1.15, 'fill' => '#1a1a1a']);
}

echo $out . "</svg>\n";
