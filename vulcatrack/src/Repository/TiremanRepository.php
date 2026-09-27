<?php

namespace VulcaTrack\Repository;

use PDO;

/**
 * Data access for the `tiremen` table (Decisions 22-26). Prepared statements
 * only.
 *
 * A Tireman is a service-provider record — identity / contact / assignment
 * only. No login, no dashboard, no GPS, no schedule, no ratings.
 *
 * Removal is soft: setActive($id, false). An inactive Tireman cannot be newly
 * assigned (listActive() leaves them out) but is never deleted, so requests
 * already assigned to them keep showing their name (Decision 28).
 *
 * `tiremen.updated_at` has no ON UPDATE clause, so every UPDATE sets it here.
 */
final class TiremanRepository
{
    private const COLUMNS = 'tireman_id, name, contact_number, is_active, created_at, updated_at';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findById(int $tiremanId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tiremen WHERE tireman_id = ? LIMIT 1'
        );
        $stmt->execute([$tiremanId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * All Tiremen, or only active (true) / only inactive (false) ones.
     * Active first, then by name.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(?bool $active = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM tiremen';
        $params = [];
        if ($active !== null) {
            $sql .= ' WHERE is_active = ?';
            $params[] = $active ? 1 : 0;
        }
        $sql .= ' ORDER BY is_active DESC, name ASC, tireman_id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    /**
     * Tiremen who may be picked for a NEW assignment (Decision 28).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listActive(): array
    {
        return $this->list(true);
    }

    /** Insert a Tireman (active by default). Callers pass validated values. */
    public function create(string $name, string $contactNumber): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tiremen (name, contact_number) VALUES (:name, :contact)'
        );
        $stmt->execute([
            ':name'    => $name,
            ':contact' => $contactNumber,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Update name / contact number. Does not touch `is_active` — use setActive(). */
    public function update(int $tiremanId, string $name, string $contactNumber): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tiremen
                SET name = :name, contact_number = :contact, updated_at = CURRENT_TIMESTAMP
              WHERE tireman_id = :id'
        );
        $stmt->execute([
            ':name'    => $name,
            ':contact' => $contactNumber,
            ':id'      => $tiremanId,
        ]);
    }

    /** Activate (true) or deactivate (false) a Tireman. */
    public function setActive(int $tiremanId, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tiremen SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE tireman_id = :id'
        );
        $stmt->execute([
            ':active' => $active ? 1 : 0,
            ':id'     => $tiremanId,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $row['tireman_id'] = (int) $row['tireman_id'];
        $row['is_active']  = (int) $row['is_active'];

        return $row;
    }
}
