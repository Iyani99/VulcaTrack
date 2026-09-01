<?php

namespace VulcaTrack\Repository;

use PDO;

/**
 * Data access for the `customers` table. Prepared statements only.
 */
final class CustomerRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{customer_id:int,full_name:string,email:string,password_hash:string}|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT customer_id, full_name, email, password_hash
             FROM customers WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array{customer_id:int,full_name:string,email:string,contact_number:string,password_hash:string,created_at:string}|null */
    public function findById(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT customer_id, full_name, email, contact_number, password_hash, created_at
             FROM customers WHERE customer_id = ? LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function updateProfile(int $customerId, string $fullName, string $contactNumber): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers
             SET full_name = :full_name, contact_number = :contact_number, updated_at = CURRENT_TIMESTAMP
             WHERE customer_id = :id'
        );
        $stmt->execute([
            ':full_name'      => $fullName,
            ':contact_number' => $contactNumber,
            ':id'             => $customerId,
        ]);
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM customers WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);

        return $stmt->fetchColumn() !== false;
    }

    /** Insert a customer. Lets a duplicate-email PDOException (SQLSTATE 23000 / 1062) propagate. */
    public function create(string $fullName, string $email, string $contactNumber, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO customers (full_name, email, contact_number, password_hash)
             VALUES (:full_name, :email, :contact_number, :password_hash)'
        );
        $stmt->execute([
            ':full_name'      => $fullName,
            ':email'          => $email,
            ':contact_number' => $contactNumber,
            ':password_hash'  => $passwordHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePasswordHash(int $customerId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ?'
        );
        $stmt->execute([$passwordHash, $customerId]);
    }
}
