<?php

namespace VulcaTrack\Service;

use RuntimeException;

/**
 * A POS cart change was refused (bad quantity, cart limit reached …).
 *
 * The message is written for the cashier and is safe to display (escaped).
 * Nothing in the cart was changed when this is thrown.
 */
final class PosCartException extends RuntimeException
{
}
