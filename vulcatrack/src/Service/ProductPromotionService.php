<?php

namespace VulcaTrack\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\ProductPromotionRepository;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\PromotionLifecycle;

/** Admin promotion creation/cancellation with Product-first locking. */
final class ProductPromotionService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Raw form amounts stay strings; validation and pricing use integer centavos. */
    public function create(int $itemId, $salePrice, string $startsOn, string $endsOn,
        $unitLimit, ?string $note, int $adminId): int
    {
        $startsOn = trim($startsOn);
        $endsOn = trim($endsOn);
        $today = date('Y-m-d');
        $effectiveStart = $startsOn === '' ? $today : $startsOn;
        $effectiveEnd = $endsOn === '' ? null : $endsOn;
        if ($itemId < 1 || $adminId < 1 || !PromotionLifecycle::validDay($today)
            || !PromotionLifecycle::validDay($effectiveStart)
            || ($effectiveEnd !== null && (!PromotionLifecycle::validDay($effectiveEnd)
                || $effectiveStart > $effectiveEnd || $effectiveEnd < $today))) {
            throw new InvalidArgumentException('Choose a valid Product and date range that has not ended.');
        }
        $saleCentavos = Money::tryToCentavos($salePrice);
        if ($saleCentavos === null || $saleCentavos < 1) {
            throw new InvalidArgumentException('Sale price must be greater than zero and below the regular price.');
        }
        $limit = null;
        if ($unitLimit !== null && $unitLimit !== '') {
            if (!is_string($unitLimit) || preg_match('/^[1-9]\d{0,9}$/D', $unitLimit) !== 1
                || (int) $unitLimit > 2147483647) {
                throw new InvalidArgumentException('Promo-unit limit must be a positive whole number or blank.');
            }
            $limit = (int) $unitLimit;
        }
        $note = $note === null ? null : trim($note);
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Note must be at most 500 characters.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Promotion creation owns its transaction.');
        }

        $items = new ItemRepository($this->pdo);
        $promotions = new ProductPromotionRepository($this->pdo);
        $this->pdo->beginTransaction();
        try {
            // Every Product operation that can change eligibility locks this row.
            $item = $items->lockForUpdate($itemId);
            $today = date('Y-m-d');
            if ($startsOn === '') { $effectiveStart = $today; }
            if ($effectiveEnd !== null && ($effectiveEnd < $today || $effectiveEnd < $effectiveStart)) {
                throw new InvalidArgumentException('The promotion date range has already ended.');
            }
            if ($item === null || $item['item_type'] !== 'product' || (int) $item['is_active'] !== 1
                || (int) $item['stock_quantity'] < 1) {
                throw new InvalidArgumentException('Choose an active Product with stock available.');
            }
            if ($saleCentavos >= (int) $item['price_centavos']) {
                throw new InvalidArgumentException('Sale price must be greater than zero and below the regular price.');
            }
            if ($limit !== null && $limit > (int) $item['stock_quantity']) {
                throw new InvalidArgumentException('Promo-unit limit cannot exceed current Product stock.');
            }
            // Reuse the authoritative slow-moving query, after the Product lock.
            $candidates = array_column($items->slowMovingProducts(date('Y-m-d H:i:s')), null, 'item_id');
            if (!isset($candidates[$itemId])) {
                throw new InvalidArgumentException('This Product is no longer slow-moving. Refresh Promotions.');
            }
            if ($promotions->findOpenForItem($itemId, $today, true) !== null) {
                throw new InvalidArgumentException('This Product already has a Scheduled or Active promotion.');
            }
            $id = $promotions->create($itemId, $saleCentavos, (int) $item['price_centavos'],
                $effectiveStart, $effectiveEnd, $limit, $note, $adminId);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    public function cancel(int $promotionId, int $adminId): void
    {
        if ($promotionId < 1 || $adminId < 1) {
            throw new InvalidArgumentException('Choose a valid promotion.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Promotion cancellation owns its transaction.');
        }
        $promotions = new ProductPromotionRepository($this->pdo);
        $known = $promotions->findById($promotionId);
        if ($known === null) {
            throw new InvalidArgumentException('Promotion not found.');
        }
        $this->pdo->beginTransaction();
        try {
            (new ItemRepository($this->pdo))->lockForUpdate((int) $known['item_id']);
            $current = $promotions->lockById($promotionId);
            $at = date('Y-m-d H:i:s');
            if ($current === null || PromotionLifecycle::status($current, substr($at, 0, 10)) === 'ended'
                || $current['cancelled_at'] !== null) {
                throw new InvalidArgumentException('Only a Scheduled or Active promotion can be cancelled.');
            }
            if (!$promotions->cancel($promotionId, $adminId, $at)) {
                throw new RuntimeException('Promotion changed before cancellation.');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }
}
