<?php
/**
 * Integration tests for ServiceRequestRepository::submitFeedback() — the
 * customer's one-time feedback on their own COMPLETED request (Phase 7.4c,
 * Decision 78). Every test runs inside a rolled-back transaction.
 *
 * Locks in: only the owner, only completed + serviced (tireman_id set), only
 * once (a second submit never overwrites the first), blank comment -> NULL,
 * and feedback never touches status, tireman_id, admin_id, updated_at or a
 * linked sale.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\ServiceRequestRepository;

/** @return array<string,int> customer + other customer, vehicle, admin, Tireman */
function srf_seed(\PDO $pdo): array
{
    $ids = [];
    foreach (['cust' => 'srf-a', 'other' => 'srf-b'] as $key => $prefix) {
        $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
            ->execute(["SRF {$key}", TestDb::email($prefix), '0917 000 0000', Password::hash('password123')]);
        $ids[$key] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number) VALUES (?,?)')->execute([$ids['cust'], 'SRF-1']);
    $ids['veh'] = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(['SRF admin', TestDb::email('srf-admin'), Password::hash('password123')]);
    $ids['admin'] = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO tiremen (name, contact_number, is_active) VALUES (?,?,1)')->execute(['SRF Tireman', '0918 000 0000']);
    $ids['tireman'] = (int) $pdo->lastInsertId();
    return $ids;
}

/** A request for the seeded customer, with an old updated_at so any change is visible. */
function srf_request(\PDO $pdo, array $ids, string $status, bool $withTireman = true): int
{
    $pdo->prepare(
        "INSERT INTO service_requests
             (customer_id, vehicle_id, admin_id, tireman_id, problem_description, latitude, longitude, eta_minutes, status, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?, '2020-01-01 00:00:00')"
    )->execute([$ids['cust'], $ids['veh'], $status === 'pending' ? null : $ids['admin'], $withTireman && $status !== 'pending' ? $ids['tireman'] : null,
        'SRF problem', 14.95, 120.89, 12, $status]);
    return (int) $pdo->lastInsertId();
}

/** @return array<string,mixed> */
function srf_row(\PDO $pdo, int $id): array
{
    $s = $pdo->prepare('SELECT status, tireman_id, admin_id, updated_at, feedback_rating, feedback_comment, feedback_submitted_at FROM service_requests WHERE request_id = ?');
    $s->execute([$id]);
    return $s->fetch();
}

test('submitFeedback saves 1..5 on the owner\'s completed request and changes nothing else', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srf_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);

        foreach ([1 => 'Slow but fixed.', 5 => str_repeat('é', 500)] as $rating => $comment) {
            $id = srf_request($pdo, $ids, 'completed');
            $before = srf_row($pdo, $id);
            assert_null($before['feedback_rating'], 'no feedback to begin with');
            assert_true($repo->submitFeedback($id, $ids['cust'], $rating, $comment), "rating {$rating} is saved");
            $after = srf_row($pdo, $id);
            assert_same((string) $rating, (string) $after['feedback_rating']);
            assert_same($comment, $after['feedback_comment'], 'the comment is stored as given (500 characters fit)');
            assert_not_null($after['feedback_submitted_at'], 'the server sets the time');
            foreach (['status', 'tireman_id', 'admin_id', 'updated_at'] as $col) {
                assert_same($before[$col], $after[$col], "{$col} is untouched by feedback");
            }
        }

        // blank / whitespace comment -> NULL
        $id = srf_request($pdo, $ids, 'completed');
        assert_true($repo->submitFeedback($id, $ids['cust'], 3, "  \n\t "));
        assert_null(srf_row($pdo, $id)['feedback_comment'], 'whitespace-only comment is stored as NULL');
        $id = srf_request($pdo, $ids, 'completed');
        assert_true($repo->submitFeedback($id, $ids['cust'], 4, null));
        assert_null(srf_row($pdo, $id)['feedback_comment']);

        // out-of-range ratings never reach the database
        assert_throws(fn () => $repo->submitFeedback($id, $ids['cust'], 0, null), \InvalidArgumentException::class);
        assert_throws(fn () => $repo->submitFeedback($id, $ids['cust'], 6, null), \InvalidArgumentException::class);
    });
});

test('submitFeedback is one-time: a second (stale / double) submit never overwrites the first', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srf_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        $id = srf_request($pdo, $ids, 'completed');

        assert_true($repo->submitFeedback($id, $ids['cust'], 5, 'First'));
        $first = srf_row($pdo, $id);
        assert_false($repo->submitFeedback($id, $ids['cust'], 1, 'Second tab'), 'the second submit changes no row');
        assert_same($first, srf_row($pdo, $id), 'rating, comment and time all keep the first submission');
    });
});

test('submitFeedback refuses other customers, non-completed and unserviced requests', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srf_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);

        $completed = srf_request($pdo, $ids, 'completed');
        assert_false($repo->submitFeedback($completed, $ids['other'], 5, 'not mine'), 'another customer cannot rate it');
        assert_null(srf_row($pdo, $completed)['feedback_rating']);

        foreach (['pending', 'accepted', 'rejected'] as $status) {
            $id = srf_request($pdo, $ids, $status);
            assert_false($repo->submitFeedback($id, $ids['cust'], 5, null), "{$status} requests cannot be rated");
            $row = srf_row($pdo, $id);
            assert_null($row['feedback_rating']);
            assert_same($status, $row['status'], 'and the status is unchanged');
        }

        $noTireman = srf_request($pdo, $ids, 'completed', false); // older malformed data
        assert_false($repo->submitFeedback($noTireman, $ids['cust'], 5, null), 'a completed request with no Tireman cannot be rated');
        assert_false($repo->submitFeedback(2147483647, $ids['cust'], 5, null), 'a missing request changes nothing');
    });
});

test('submitFeedback does not depend on or touch a linked sale', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srf_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        $withSale = srf_request($pdo, $ids, 'completed');
        $pdo->prepare("INSERT INTO sales (customer_id, service_request_id, admin_id, sale_date, total_amount) VALUES (?,?,?, '2026-01-02 03:04:05', 150.00)")
            ->execute([$ids['cust'], $withSale, $ids['admin']]);
        $saleId = (int) $pdo->lastInsertId();
        $sale = static function () use ($pdo, $saleId): array {
            $s = $pdo->prepare('SELECT * FROM sales WHERE sale_id = ?');
            $s->execute([$saleId]);
            return $s->fetch();
        };
        $before = $sale();

        assert_true($repo->submitFeedback($withSale, $ids['cust'], 4, 'With a sale'), 'a request with a sale can be rated');
        assert_same($before, $sale(), 'the linked sale is untouched');

        $noSale = srf_request($pdo, $ids, 'completed');
        assert_true($repo->submitFeedback($noSale, $ids['cust'], 2, null), 'a request without a sale can be rated too');
    });
});

test('findForCustomer / findForAdmin expose the saved feedback', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srf_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        $id = srf_request($pdo, $ids, 'completed');
        $repo->submitFeedback($id, $ids['cust'], 5, 'Great');
        foreach ([$repo->findForCustomer($id, $ids['cust']), $repo->findForAdmin($id)] as $row) {
            assert_same('5', (string) $row['feedback_rating']);
            assert_same('Great', $row['feedback_comment']);
            assert_not_null($row['feedback_submitted_at']);
        }
    });
});
