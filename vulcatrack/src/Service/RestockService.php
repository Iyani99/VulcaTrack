<?php

namespace VulcaTrack\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\RestockRepository;

/** Commits a Product receipt and its stock increase as one unit. */
class RestockService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(int $itemId, int $quantity, ?string $supplier, int $adminId, ?string $note = null): int
    {
        if ($itemId <= 0 || $quantity <= 0 || $quantity > 2147483647 || $adminId <= 0) {
            throw new InvalidArgumentException('A valid Product, quantity, and Admin are required.');
        }
        $supplier = $supplier === null ? null : trim($supplier);
        if ($supplier === '') {
            $supplier = null;
        }
        if ($supplier !== null && mb_strlen($supplier) > 150) {
            throw new InvalidArgumentException('Supplier name must be at most 150 characters.');
        }
        $note = $note === null ? null : trim($note);
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Note must be at most 500 characters.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Restock cannot run inside another transaction.');
        }

        $items = new ItemRepository($this->pdo);
        $restocks = new RestockRepository($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $item = $items->lockForUpdate($itemId);
            if ($item === null || $item['item_type'] !== 'product') {
                throw new InvalidArgumentException('Product not found.');
            }
            if ((int) $item['is_active'] !== 1) {
                throw new InvalidArgumentException('Reactivate this product before restocking it.');
            }
            $stockBefore = (int) $item['stock_quantity'];
            $stockAfter = $stockBefore + $quantity;
            if (!$items->incrementStock($itemId, $quantity)) {
                throw new InvalidArgumentException('Quantity exceeds available stock capacity.');
            }
            $restockId = $restocks->record($itemId, $quantity, $stockBefore, $stockAfter, $supplier, $note, $adminId);
            $this->pdo->commit();
            return $restockId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
