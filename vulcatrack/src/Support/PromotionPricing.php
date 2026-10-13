<?php

namespace VulcaTrack\Support;

use RuntimeException;

/** Shared Product price split for display and locked checkout. */
final class PromotionPricing
{
    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $promotion
     * @return array<int,array{quantity:int,unit_centavos:int,subtotal_centavos:int,promotion_id:?int,label:string}>
     */
    public static function lines(array $item, ?array $promotion, int $quantity, string $today, bool $rescue): array
    {
        if ($quantity < 1) {
            throw new RuntimeException('Price quantity must be positive.');
        }
        $regular = (int) $item['price_centavos'];
        $promoQuantity = 0;
        if (!$rescue && $item['item_type'] === 'product' && $promotion !== null
            && (int) $promotion['item_id'] === (int) $item['item_id']
            && PromotionLifecycle::status($promotion, $today) === 'active') {
            $sale = (int) $promotion['sale_price_centavos'];
            if ($sale <= 0 || $sale >= $regular) {
                throw new RuntimeException('Promotion price must remain below the current Product price.');
            }
            $remaining = PromotionLifecycle::remaining($promotion);
            $promoQuantity = min($quantity, $remaining ?? $quantity);
        }

        $lines = [];
        if ($promoQuantity > 0) {
            $unit = (int) $promotion['sale_price_centavos'];
            $lines[] = [
                'quantity' => $promoQuantity,
                'unit_centavos' => $unit,
                'subtotal_centavos' => $unit * $promoQuantity,
                'promotion_id' => (int) $promotion['promotion_id'],
                'label' => 'Sale price',
            ];
        }
        $regularQuantity = $quantity - $promoQuantity;
        if ($regularQuantity > 0) {
            $lines[] = [
                'quantity' => $regularQuantity,
                'unit_centavos' => $regular,
                'subtotal_centavos' => $regular * $regularQuantity,
                'promotion_id' => null,
                'label' => $promoQuantity > 0 ? 'Regular price' : '',
            ];
        }
        return $lines;
    }
}
