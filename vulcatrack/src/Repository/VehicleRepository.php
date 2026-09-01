<?php

namespace VulcaTrack\Repository;

use PDO;

/**
 * Data access for the `vehicles` table. Prepared statements only.
 *
 * Every read and write is scoped to the owning customer (`customer_id`) so a
 * customer can only ever see or change their own vehicles.
 *
 * Soft delete: "removing" a vehicle sets `is_active = 0` (Decisions 27-29).
 * Rows are never hard-deleted -- an inactive vehicle stays visible on the
 * historical `service_requests` that reference it.
 */
final class VehicleRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function listForCustomer(int $customerId, bool $includeInactive = false): array
    {
        $sql = 'SELECT vehicle_id, plate_number, vehicle_type, make, model, is_active, created_at
                FROM vehicles WHERE customer_id = :cid';
        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY is_active DESC, plate_number ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':cid' => $customerId]);

        return $stmt->fetchAll();
    }

    public function countActiveForCustomer(int $customerId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM vehicles WHERE customer_id = ? AND is_active = 1'
        );
        $stmt->execute([$customerId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function findForCustomer(int $vehicleId, int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT vehicle_id, plate_number, vehicle_type, make, model, is_active, created_at
             FROM vehicles WHERE vehicle_id = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$vehicleId, $customerId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(
        int $customerId,
        string $plateNumber,
        ?string $vehicleType,
        ?string $make,
        ?string $model
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO vehicles (customer_id, plate_number, vehicle_type, make, model)
             VALUES (:cid, :plate, :type, :make, :model)'
        );
        $stmt->execute([
            ':cid'   => $customerId,
            ':plate' => $plateNumber,
            ':type'  => $vehicleType,
            ':make'  => $make,
            ':model' => $model,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update a vehicle. The `customer_id` clause is defence in depth -- callers
     * must still confirm ownership with findForCustomer() first.
     */
    public function update(
        int $vehicleId,
        int $customerId,
        string $plateNumber,
        ?string $vehicleType,
        ?string $make,
        ?string $model
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE vehicles
             SET plate_number = :plate, vehicle_type = :type, make = :make, model = :model,
                 updated_at = CURRENT_TIMESTAMP
             WHERE vehicle_id = :vid AND customer_id = :cid'
        );
        $stmt->execute([
            ':plate' => $plateNumber,
            ':type'  => $vehicleType,
            ':make'  => $make,
            ':model' => $model,
            ':vid'   => $vehicleId,
            ':cid'   => $customerId,
        ]);
    }

    public function setActive(int $vehicleId, int $customerId, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE vehicles SET is_active = :active, updated_at = CURRENT_TIMESTAMP
             WHERE vehicle_id = :vid AND customer_id = :cid'
        );
        $stmt->execute([
            ':active' => $active ? 1 : 0,
            ':vid'    => $vehicleId,
            ':cid'    => $customerId,
        ]);
    }
}
