<?php

namespace VulcaTrack\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use VulcaTrack\Repository\AdjustmentRepository;
use VulcaTrack\Repository\ItemRepository;

/** A locked Product stock correction and its audit row commit together. */
class AdjustmentService
{
    public const REASONS = [
        'lost' => 'Lost',
        'damaged' => 'Damaged / Unsellable',
        'count_correction' => 'Count Correction',
        'other' => 'Other',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function record(int $itemId, string $direction, int $quantity, string $reason,
        ?string $note, int $adminId): int
    {
        if ($itemId < 1 || $adminId < 1 || $quantity < 1 || $quantity > 2147483647
            || !in_array($direction, ['increase', 'decrease'], true)
            || !isset(self::REASONS[$reason])) {
            throw new InvalidArgumentException('Choose a valid Product, direction, positive quantity, reason, and Admin.');
        }
        $note = $note === null ? null : trim($note);
        if ($note === '') { $note = null; }
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Note must be at most 500 characters.');
        }
        if ($reason === 'other' && $note === null) {
            throw new InvalidArgumentException('Add a useful note when the reason is Other.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Adjustment cannot run inside another transaction.');
        }

        $items = new ItemRepository($this->pdo);
        $history = new AdjustmentRepository($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $item = $items->lockForUpdate($itemId);
            if ($item === null || $item['item_type'] !== 'product') {
                throw new InvalidArgumentException('Product not found.');
            }
            // An archived Product can still need physical reconciliation. This does
            // not reactivate it and never creates a Sale or Restock receipt.
            $before = (int) $item['stock_quantity'];
            $after = $direction === 'increase' ? $before + $quantity : $before - $quantity;
            if ($after < 0) {
                throw new InvalidArgumentException('The adjustment cannot make stock negative.');
            }
            if ($after > 2147483647) {
                throw new InvalidArgumentException('Quantity exceeds available stock capacity.');
            }
            if (!$items->setAdjustedStock($itemId, $before, $after)) {
                throw new RuntimeException('Product stock changed before the adjustment could be saved.');
            }
            $id = $history->record($itemId, $direction, $quantity, $reason, $note, $before, $after, $adminId);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }
}
