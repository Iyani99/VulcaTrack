<?php
/**
 * Renders the admin Rescue list's empty-state partial
 * (src/Views/partials/rescue_empty.php) for every filter value — no database,
 * so the result never depends on which requests exist.
 *
 * The HTTP side (a non-empty view never shows these links) is covered in
 * http/AdminRescueTest with seeded requests.
 */

namespace VulcaTrack\Tests;

function render_rescue_empty(string $status): string
{
    ob_start();
    require app_path('src/Views/partials/rescue_empty.php');
    return (string) ob_get_clean();
}

test('Rescue empty state: an empty Pending view links to Accepted and All', function () {
    $html = render_rescue_empty('pending');
    assert_contains('No pending requests.', $html);
    assert_contains('href="' . e(vulcatrack_url('/admin/rescue.php?status=accepted')) . '">View accepted</a>', $html);
    assert_contains('href="' . e(vulcatrack_url('/admin/rescue.php?status=all')) . '">View all</a>', $html);
    assert_same(2, substr_count($html, '<a '), 'exactly two quick links');
});

test('Rescue empty state: empty Accepted / Rejected / Completed views link to Pending and All', function () {
    foreach (['accepted', 'rejected', 'completed'] as $status) {
        $html = render_rescue_empty($status);
        assert_contains("No {$status} requests.", $html);
        assert_contains('href="' . e(vulcatrack_url('/admin/rescue.php?status=pending')) . '">View pending</a>', $html, "{$status} -> pending");
        assert_contains('href="' . e(vulcatrack_url('/admin/rescue.php?status=all')) . '">View all</a>', $html, "{$status} -> all");
        assert_not_contains('?status=' . $status . '"', $html, "{$status} never links to itself");
        assert_same(2, substr_count($html, '<a '), "{$status}: exactly two quick links");
    }
});

test('Rescue empty state: an empty All view shows only the message', function () {
    $html = render_rescue_empty('all');
    assert_contains('No rescue requests yet.', $html);
    assert_same(0, substr_count($html, '<a '), 'All has nowhere else to link');
});
