<?php

namespace VulcaTrack\Repository;

use PDO;

/**
 * Data access for the `admins` table. Prepared statements only.
 *
 * Admins are provisioned internally (CLI: database/seed_admin.php). There is
 * no public admin registration.
 */
final class AdminRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{admin_id:int,full_name:string,email:string,password_hash:string}|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT admin_id, full_name, email, password_hash
             FROM admins WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Look up an admin by id — used to confirm a still-valid recording admin
     * before a sale is written (mirrors CustomerRepository::findById).
     *
     * @return array{admin_id:int,full_name:string,email:string,password_hash:string,created_at:string}|null
     */
    public function findById(int $adminId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT admin_id, full_name, email, password_hash, created_at
             FROM admins WHERE admin_id = ? LIMIT 1'
        );
        $stmt->execute([$adminId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every admin account for the Admin Accounts page (Decision 79), newest
     * first (admin_id breaks a created_at tie). Display fields only — never
     * the password hash.
     *
     * @return array<int,array{admin_id:int,full_name:string,email:string,created_at:string}>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT admin_id, full_name, email, created_at
             FROM admins ORDER BY created_at DESC, admin_id DESC'
        );

        return array_map(static fn (array $r): array => [
            'admin_id'   => (int) $r['admin_id'],
            'full_name'  => (string) $r['full_name'],
            'email'      => (string) $r['email'],
            'created_at' => (string) $r['created_at'],
        ], $stmt->fetchAll());
    }

    /** Insert an admin. Lets a duplicate-email PDOException (SQLSTATE 23000 / 1062) propagate. */
    public function create(string $fullName, string $email, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admins (full_name, email, password_hash)
             VALUES (:full_name, :email, :password_hash)'
        );
        $stmt->execute([
            ':full_name'     => $fullName,
            ':email'         => $email,
            ':password_hash' => $passwordHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePasswordHash(int $adminId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE admins SET password_hash = ? WHERE admin_id = ?'
        );
        $stmt->execute([$passwordHash, $adminId]);
    }
}
