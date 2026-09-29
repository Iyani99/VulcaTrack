<?php
/**
 * VulcaTrack — Rescue (On-the-Go) service request, UML activity diagram (paper version).
 * Portrait: 1100 units ≈ a 6.5-inch page width → 21-unit text ≈ 9 pt.
 *
 * Facts (Decisions 10, 25, 32, 39, 65-68, 70-76, 78): an authenticated customer books
 * with an ACTIVE saved vehicle; ETA computed once and frozen; status starts pending;
 * accepting ASSIGNS an active Tireman in the same guarded action; pending -> accepted |
 * rejected, accepted -> completed | rejected; completion is an explicit admin action and
 * recording the POS sale is separate; the customer may give one-time feedback on a
 * completed request (status unchanged). No live GPS, no chat, no cancelled status.
 *
 *   php docs/diagram-src/activity-otg.php > docs/flows/VulcaTrack-Activity-Diagram-OTG.svg
 */
require __DIR__ . '/lib.php';

const W = 1100, H = 1575, F = 21, NOTE = 17, PAD = 18;
$lanes = ['Customer' => [0, 350], 'VulcaTrack System' => [350, 730], 'Admin' => [730, 1100]];
$cx = ['C' => 175, 'S' => 540, 'A' => 915];
$bw = ['C' => 300, 'S' => 320, 'A' => 300];
$cy = fn (float $n): float => 190 + ($n - 1) * 94 + 31;          // row centre

$fill = '#dcebf6'; $stroke = '#2f5f82';
$box = function (string $lane, float $n, $lines, array $o = []) use ($cx, $bw, $cy, $fill, $stroke) {
    $lines = is_array($lines) ? $lines : explode("\n", $lines);
    $w = $o['w'] ?? $bw[$lane];
    $x = ($o['x'] ?? $cx[$lane] - $w / 2);
    $h = PAD + count($lines) * F * 1.18;
    $y = $cy($n) - $h / 2;
    return rect($x, $y, $w, $h, ['rx' => 14, 'fill' => $fill, 'stroke' => $stroke, 'sw' => 2])
        . txt($x + $w / 2, $cy($n), $lines, F, ['anchor' => 'middle', 'middle' => true, 'lh' => F * 1.18]);
};
$top = fn (float $n, int $lines = 2): float => $cy($n) - (PAD + $lines * F * 1.18) / 2;
$bot = fn (float $n, int $lines = 2): float => $cy($n) + (PAD + $lines * F * 1.18) / 2;
$diamond = function (string $label, float $n) use ($cx, $cy, $fill, $stroke) {
    $x = $cx['A']; $y = $cy($n);
    return sprintf('<path d="M%s,%s L%s,%s L%s,%s L%s,%s z" fill="%s" stroke="%s" stroke-width="2"/>' . "\n",
            r($x), r($y - 38), r($x + 80), r($y), r($x), r($y + 38), r($x - 80), r($y), $fill, $stroke)
        . txt($x, $y, $label, F, ['anchor' => 'middle', 'middle' => true, 'weight' => '600']);
};
$arrow = fn (array $pts) => poly($pts, ['sw' => 2.2, 'end' => 'a']);
$line  = fn (array $pts) => poly($pts, ['sw' => 2.2]);
$note = function (float $x, float $y, float $w, array $lines, bool $boldFirst = false) {
    $h = 22 + count($lines) * NOTE * 1.25;
    $o = rect($x, $y, $w, $h, ['fill' => '#fff8d6', 'stroke' => '#a08a2a', 'sw' => 1.6, 'dash' => '6 4']);
    foreach ($lines as $i => $l) {
        $o .= txt($x + 14, $y + 11 + NOTE + $i * NOTE * 1.25, $l, NOTE, ['weight' => ($boldFirst && $i === 0) ? '700' : '400', 'fill' => '#3d3510']);
    }
    return $o;
};
$label = fn (float $x, float $y, string $t, string $anchor = 'start') => txt($x, $y, $t, 18, ['italic' => true, 'fill' => '#333', 'anchor' => $anchor]);

$out = svg_open(W, H, arrow_marker('a', '#1a1a1a', 15));
$out .= txt(W / 2, 40, 'VulcaTrack: Sales and Inventory with On-the-Go Services', 27, ['anchor' => 'middle', 'weight' => '700']);
$out .= txt(W / 2, 72, 'Activity Diagram — Rescue (On-the-Go) Service Request', 20, ['anchor' => 'middle', 'fill' => '#444']);

// swimlanes
foreach ($lanes as $name => [$x0, $x1]) {
    $out .= rect($x0 + 1, 94, $x1 - $x0 - 2, H - 100, ['fill' => 'none', 'stroke' => '#555', 'sw' => 1.6]);
    $out .= rect($x0 + 1, 94, $x1 - $x0 - 2, 46, ['fill' => '#eef1f4', 'stroke' => '#555', 'sw' => 1.6]);
    $out .= txt(($x0 + $x1) / 2, 117, $name, 23, ['anchor' => 'middle', 'weight' => '700', 'middle' => true]);
}

// ---- edges (drawn first) -------------------------------------------------------
$C = $cx['C']; $S = $cx['S']; $A = $cx['A'];
$out .= $arrow([[$C, 170], [$C, $top(1)]]);
foreach ([[1, 2], [2, 3], [3, 4]] as [$a, $b]) {
    $out .= $arrow([[$C, $bot($a)], [$C, $top($b)]]);
}
$out .= $arrow([[325, $cy(4)], [380, $cy(4)]]);                       // submit -> validate
$out .= $arrow([[$S, $bot(4)], [$S, $top(5)]]);
$out .= $arrow([[$S, $bot(5)], [$S, $top(6)]]);
$out .= $arrow([[380, $cy(6)], [325, $cy(6)]]);                       // -> confirmation
$out .= $arrow([[700, $cy(6)], [765, $cy(6)]]);                       // -> admin review
$out .= $arrow([[$A, $bot(6)], [$A, $cy(7) - 38]]);
$out .= $arrow([[$A, $cy(7) + 38], [$A, $top(8)]]);                   // [Yes]
$out .= $label($A + 12, $cy(7) + 55, '[Yes]');
$out .= $arrow([[765, $cy(8)], [700, $cy(8)]]);                       // accept+assign -> save
$out .= $arrow([[380, $cy(8)], [$C, $cy(8)], [$C, $top(9, 3)]]);      // -> Tireman on the way
$out .= $arrow([[$A, $bot(8)], [$A, $cy(10) - 38]]);                  // -> job done?
$out .= $arrow([[325, $cy(9)], [362, $cy(9)], [362, $cy(10)], [$A - 80, $cy(10)]]); // after the job
$out .= $label(560, $cy(10) - 10, 'after the roadside job', 'middle');
$out .= $arrow([[$A, $cy(10) + 38], [$A, $top(11, 1)]]);              // [Yes] -> mark completed
$out .= $label($A + 12, $cy(10) + 57, '[Yes]');
$out .= $arrow([[765, $cy(11)], [700, $cy(11)]]);                     // -> status completed
$out .= $arrow([[380, $cy(11)], [$C, $cy(11)], [$C, $top(12)]]);      // -> view completed
$out .= $arrow([[$C, $bot(12)], [$C, $top(13)]]);                     // -> optional feedback
$out .= $arrow([[325, $cy(13)], [380, $cy(13)]]);                     // -> save feedback
$out .= $arrow([[660, $cy(13)], [690, $cy(13)]]);                     // -> end (completed)
// the two reject branches share the right gutter
$out .= $line([[$A + 80, $cy(7)], [1085, $cy(7)]]);
$out .= $label($A + 88, $cy(7) - 10, '[No]');
$out .= $line([[$A + 80, $cy(10)], [1085, $cy(10)]]);
$out .= $label($A + 88, $cy(10) - 10, '[No]');
$out .= $arrow([[1085, $cy(7)], [1085, $cy(14)], [1065, $cy(14)]]);
$out .= $arrow([[765, $cy(14)], [700, $cy(14)]]);                     // reject -> status rejected
$out .= $arrow([[380, $cy(14)], [325, $cy(14)]]);                     // -> view rejected
$out .= $arrow([[$C, $bot(14, 1)], [$C, $bot(14, 1) + 50]]);             // -> end (rejected)

// ---- nodes -----------------------------------------------------------------------
$out .= '<circle cx="' . $C . '" cy="160" r="12" fill="#1a1a1a"/>' . "\n";
$out .= $box('C', 1, "Log in & open\nBook a Rescue");
$out .= $box('C', 2, "Choose an active saved\nvehicle; describe problem");
$out .= $box('C', 3, "Share location: browser\ngeolocation or map pin");
$out .= $box('C', 4, "Confirm contact number\non file; submit request");
$out .= $box('S', 4, "Validate customer, vehicle\nownership & request data");
$out .= $box('S', 5, "Compute the ETA once\n(customer point + shop)");
$out .= $box('S', 6, "Save request: pending;\nETA frozen (never updated)");
$out .= $box('C', 6, "See confirmation, frozen\nETA and status Pending");
$out .= $box('A', 6, "Review pending request\n(details, location, ETA)");
$out .= $diamond('Accept?', 7);
$out .= $box('A', 8, "Accept & assign an active\nTireman (one action)");
$out .= $box('S', 8, "Save Tireman + status\naccepted (guarded update)");
$out .= $box('C', 9, "“Tireman is on the way”:\nname, contact number,\nfrozen ETA");
$out .= $note(378, $top(9, 3), 324, ['Off-system: the Tireman does', 'the roadside job; customer and', 'Tireman talk by phone. No live', 'GPS tracking, no in-app chat.']);
$out .= $diamond('Job done?', 10);
$out .= $box('A', 11, 'Mark request completed');
$out .= $box('S', 11, 'Set status = completed');
$out .= $box('C', 12, "View completed request\n(“Serviced by …”)");
$out .= $box('C', 13, "Optional: rate this service\n(1–5 stars + comment)");
$out .= $box('S', 13, "Save one-time feedback\n(status stays completed)", ['x' => 380, 'w' => 280]);
$out .= $note(765, $bot(11, 1) + 16, 300, [
    'Status rules', 'pending → accepted or rejected', 'accepted → completed or rejected',
    'While accepted, the admin may', 'reassign another active Tireman.',
    'The POS sale is a separate action', 'and never changes the status.',
], true);
$out .= $box('A', 14, 'Reject request');
$out .= $box('S', 14, 'Set status = rejected');
$out .= $box('C', 14, 'View rejected request');
// activity final nodes
foreach ([[707, $cy(13)], [$C, $bot(14, 1) + 65]] as [$x, $y]) {
    $out .= '<circle cx="' . r($x) . '" cy="' . r($y) . '" r="15" fill="#fff" stroke="#1a1a1a" stroke-width="2.4"/>'
        . '<circle cx="' . r($x) . '" cy="' . r($y) . '" r="8.5" fill="#1a1a1a"/>' . "\n";
}

echo $out . "</svg>\n";
