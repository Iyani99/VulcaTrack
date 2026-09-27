<?php

namespace VulcaTrack\Repository;

use InvalidArgumentException;
use PDO;
use VulcaTrack\Support\OtgStatus;

/**
 * Data access for the `service_requests` table (On-the-Go). Prepared statements
 * only.
 *
 * Customer side (Phase 4): a customer CREATES a request (always
 * `status = 'pending'`) and VIEWS their own requests. Every *ForCustomer read
 * is scoped to the owning customer.
 *
 * Admin side: listForAdmin() / findForAdmin() (Phase 6.2) are deliberately
 * separate from the customer methods — only admin pages call them. The admin
 * mutations (Phase 6.3) — accept(), reassign(), reject(), complete() — are
 * each ONE guarded UPDATE: the WHERE clause re-checks the expected current
 * state (and, for assignment, that the Tireman is active), so a request that
 * changed in another tab is never overwritten. Each returns true only when
 * exactly one row changed. Every success records the acting admin in
 * `admin_id` (= last admin who changed status or assignment) and sets
 * `updated_at` (no ON UPDATE clause on this column). `tireman_id` is kept on
 * final requests as history.
 *
 * `eta_minutes` is written once, at creation, as a frozen snapshot and is never
 * updated (Decisions 32/33). No route geometry is stored.
 */
final class ServiceRequestRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Create a pending request. The caller must have already confirmed that
     * $vehicleId is an ACTIVE vehicle owned by $customerId.
     */
    public function createPending(
        int $customerId,
        int $vehicleId,
        string $problemDescription,
        float $latitude,
        float $longitude,
        int $etaMinutes
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO service_requests
                 (customer_id, vehicle_id, problem_description, latitude, longitude, eta_minutes, status)
             VALUES
                 (:cid, :vid, :problem, :lat, :lng, :eta, 'pending')"
        );
        $stmt->execute([
            ':cid'     => $customerId,
            ':vid'     => $vehicleId,
            ':problem' => $problemDescription,
            ':lat'     => $latitude,
            ':lng'     => $longitude,
            ':eta'     => $etaMinutes,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> newest first */
    public function listForCustomer(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sr.request_id, sr.status, sr.eta_minutes, sr.requested_at,
                    sr.tireman_id,
                    v.plate_number, v.vehicle_type, v.make, v.model
             FROM service_requests sr
             JOIN vehicles v ON v.vehicle_id = sr.vehicle_id
             WHERE sr.customer_id = ?
             ORDER BY sr.requested_at DESC, sr.request_id DESC'
        );
        $stmt->execute([$customerId]);

        return $stmt->fetchAll();
    }

    /**
     * One request with vehicle + assigned-tireman details, scoped to the owner.
     *
     * @return array<string,mixed>|null
     */
    public function findForCustomer(int $requestId, int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sr.request_id, sr.customer_id, sr.vehicle_id, sr.tireman_id,
                    sr.problem_description, sr.latitude, sr.longitude, sr.eta_minutes,
                    sr.status, sr.requested_at, sr.updated_at,
                    v.plate_number, v.vehicle_type, v.make, v.model, v.is_active AS vehicle_active,
                    t.name AS tireman_name, t.contact_number AS tireman_contact
             FROM service_requests sr
             JOIN vehicles v ON v.vehicle_id = sr.vehicle_id
             LEFT JOIN tiremen t ON t.tireman_id = sr.tireman_id
             WHERE sr.request_id = ? AND sr.customer_id = ?
             LIMIT 1'
        );
        $stmt->execute([$requestId, $customerId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countOpenForCustomer(int $customerId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM service_requests
             WHERE customer_id = ? AND status IN ('pending', 'accepted')"
        );
        $stmt->execute([$customerId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null the customer's most recent request */
    public function latestForCustomer(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sr.request_id, sr.status, sr.eta_minutes, sr.requested_at, sr.tireman_id,
                    v.plate_number
             FROM service_requests sr
             JOIN vehicles v ON v.vehicle_id = sr.vehicle_id
             WHERE sr.customer_id = ?
             ORDER BY sr.requested_at DESC, sr.request_id DESC
             LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    // --- Phase 6.2: admin reads (all customers, read-only) -------------------

    /**
     * Every request (all customers), or only those with one status. Newest
     * first. $status must be one of the four OtgStatus values or null (= all).
     *
     * @return array<int,array<string,mixed>>
     * @throws InvalidArgumentException on an unknown status
     */
    public function listForAdmin(?string $status = null): array
    {
        $sql = 'SELECT sr.request_id, sr.status, sr.eta_minutes, sr.requested_at, sr.tireman_id,
                       c.full_name AS customer_name, c.contact_number AS customer_contact,
                       v.plate_number,
                       t.name AS tireman_name
                FROM service_requests sr
                JOIN customers c ON c.customer_id = sr.customer_id
                JOIN vehicles v ON v.vehicle_id = sr.vehicle_id
                LEFT JOIN tiremen t ON t.tireman_id = sr.tireman_id';
        $params = [];
        if ($status !== null) {
            if (!OtgStatus::isValid($status)) {
                throw new InvalidArgumentException("Unknown request status: {$status}");
            }
            $sql .= ' WHERE sr.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY sr.requested_at DESC, sr.request_id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * One request with its customer, vehicle, assigned Tireman and handling
     * admin — for the admin detail page. Not scoped to a customer.
     *
     * @return array<string,mixed>|null
     */
    public function findForAdmin(int $requestId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sr.request_id, sr.customer_id, sr.vehicle_id, sr.admin_id, sr.tireman_id,
                    sr.problem_description, sr.latitude, sr.longitude, sr.eta_minutes,
                    sr.status, sr.requested_at, sr.updated_at,
                    c.full_name AS customer_name, c.email AS customer_email,
                    c.contact_number AS customer_contact,
                    v.plate_number, v.vehicle_type, v.make, v.model, v.is_active AS vehicle_active,
                    t.name AS tireman_name, t.contact_number AS tireman_contact,
                    t.is_active AS tireman_active,
                    a.full_name AS admin_name
             FROM service_requests sr
             JOIN customers c ON c.customer_id = sr.customer_id
             JOIN vehicles v ON v.vehicle_id = sr.vehicle_id
             LEFT JOIN tiremen t ON t.tireman_id = sr.tireman_id
             LEFT JOIN admins a ON a.admin_id = sr.admin_id
             WHERE sr.request_id = ?
             LIMIT 1'
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    // --- Phase 6.3: admin mutations (guarded, one statement each) ------------

    /**
     * pending -> accepted, assigning an ACTIVE Tireman in the same statement
     * (acceptance always has a Tireman). False when the request is no longer
     * pending or the Tireman is unknown / inactive.
     */
    public function accept(int $requestId, int $tiremanId, int $adminId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE service_requests
                SET status = 'accepted', tireman_id = :tireman, admin_id = :admin,
                    updated_at = CURRENT_TIMESTAMP
              WHERE request_id = :id
                AND status = 'pending'
                AND EXISTS (SELECT 1 FROM tiremen WHERE tireman_id = :tireman_check AND is_active = 1)"
        );
        $stmt->execute([
            ':tireman' => $tiremanId, ':admin' => $adminId, ':id' => $requestId,
            ':tireman_check' => $tiremanId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Replace the Tireman on an ACCEPTED request (status unchanged) with a
     * different ACTIVE Tireman. $expectedTiremanId is the assignment the admin
     * was looking at (null = none); if someone else reassigned meanwhile, this
     * refuses rather than overwriting their choice.
     */
    public function reassign(int $requestId, ?int $expectedTiremanId, int $tiremanId, int $adminId): bool
    {
        if ($tiremanId === $expectedTiremanId) {
            return false; // already assigned — nothing to change
        }
        // `<=>` is MySQL's NULL-safe equals, so "no Tireman yet" (NULL) matches too.
        $stmt = $this->pdo->prepare(
            "UPDATE service_requests
                SET tireman_id = :tireman, admin_id = :admin, updated_at = CURRENT_TIMESTAMP
              WHERE request_id = :id
                AND status = 'accepted'
                AND tireman_id <=> :expected
                AND EXISTS (SELECT 1 FROM tiremen WHERE tireman_id = :tireman_check AND is_active = 1)"
        );
        $stmt->execute([
            ':tireman' => $tiremanId, ':admin' => $adminId, ':id' => $requestId,
            ':expected' => $expectedTiremanId,
            ':tireman_check' => $tiremanId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * $fromStatus -> rejected, where $fromStatus is the status the admin was
     * looking at (pending or accepted). Any assigned Tireman is kept as
     * history. False when the request is no longer in $fromStatus.
     *
     * @throws InvalidArgumentException when $fromStatus cannot be rejected
     */
    public function reject(int $requestId, string $fromStatus, int $adminId): bool
    {
        if (!OtgStatus::canTransition($fromStatus, 'rejected')) {
            throw new InvalidArgumentException("A {$fromStatus} request cannot be rejected.");
        }
        $stmt = $this->pdo->prepare(
            "UPDATE service_requests
                SET status = 'rejected', admin_id = :admin, updated_at = CURRENT_TIMESTAMP
              WHERE request_id = :id AND status = :from"
        );
        $stmt->execute([':admin' => $adminId, ':id' => $requestId, ':from' => $fromStatus]);

        return $stmt->rowCount() === 1;
    }

    /**
     * accepted -> completed, only when a Tireman is assigned (who did the job
     * is kept on record). The Tireman may since have been deactivated — that
     * does not block completion. False when no longer accepted or unassigned.
     */
    public function complete(int $requestId, int $adminId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE service_requests
                SET status = 'completed', admin_id = :admin, updated_at = CURRENT_TIMESTAMP
              WHERE request_id = :id AND status = 'accepted' AND tireman_id IS NOT NULL"
        );
        $stmt->execute([':admin' => $adminId, ':id' => $requestId]);

        return $stmt->rowCount() === 1;
    }
}
