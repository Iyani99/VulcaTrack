<?php

namespace VulcaTrack\Service;

use RuntimeException;

/**
 * A checkout was rejected by SaleService — bad input, an unavailable item, or
 * not enough stock. When SaleService throws this (or anything else), it has
 * already rolled back: no `sales` row, no `sale_items`, no stock change
 * survives. The message is safe to show to the cashier.
 */
final class SaleException extends RuntimeException
{
}
