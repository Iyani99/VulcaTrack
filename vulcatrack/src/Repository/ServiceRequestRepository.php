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
 * Admin side (Phase 6.2): listForAdmin() / findForAdmin() are READ-ONLY and
 * deliberately separate from the customer methods — only admin pages call
 * them. Accept / reject / assign / complete are not implemented yet.
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
}
