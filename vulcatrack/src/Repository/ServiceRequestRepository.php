<?php

namespace VulcaTrack\Repository;

use PDO;

/**
 * Data access for the `service_requests` table (On-the-Go). Prepared statements
 * only, every read scoped to the owning customer.
 *
 * Phase 4 is customer-side only: a customer CREATES a request (always
 * `status = 'pending'`) and VIEWS their own requests. Accept / reject / assign /
 * complete are admin actions (Phase 6) and are not implemented here.
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
}
