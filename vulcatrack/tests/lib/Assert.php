<?php
/**
 * VulcaTrack test harness -- assertions and the test registry.
 *
 * Dependency-free. No Composer, no PHPUnit. A "test" is a name + closure
 * registered with test(); the runner (tests/run.php) executes each one in
 * isolation and tallies assertions.
 *
 * Every assert_*() call bumps Assert::$count. A failed assertion throws
 * AssertionFailed, which the runner records as a failure for that test.
 */

namespace VulcaTrack\Tests;

final class AssertionFailed extends \RuntimeException
{
}

final class Assert
{
    /** @var int total assertions executed across the run */
    public static int $count = 0;

    /** @var list<array{0:string,1:\Closure}> registered tests */
    public static array $tests = [];

    public static function reset(): void
    {
        self::$count = 0;
        self::$tests = [];
    }
}

/** Register a test case. */
function test(string $name, \Closure $fn): void
{
    Assert::$tests[] = [$name, $fn];
}

function fail(string $message): void
{
    throw new AssertionFailed($message);
}

function assert_true($condition, string $message = 'expected true'): void
{
    Assert::$count++;
    if ($condition !== true) {
        fail($message . ' (got ' . var_export($condition, true) . ')');
    }
}

function assert_false($condition, string $message = 'expected false'): void
{
    Assert::$count++;
    if ($condition !== false) {
        fail($message . ' (got ' . var_export($condition, true) . ')');
    }
}

function assert_same($expected, $actual, string $message = 'values are not identical'): void
{
    Assert::$count++;
    if ($expected !== $actual) {
        fail($message . "\n  expected: " . var_export($expected, true)
            . "\n  actual:   " . var_export($actual, true));
    }
}

function assert_equals($expected, $actual, string $message = 'values are not equal'): void
{
    Assert::$count++;
    if ($expected != $actual) {
        fail($message . "\n  expected: " . var_export($expected, true)
            . "\n  actual:   " . var_export($actual, true));
    }
}

function assert_null($actual, string $message = 'expected null'): void
{
    Assert::$count++;
    if ($actual !== null) {
        fail($message . ' (got ' . var_export($actual, true) . ')');
    }
}

function assert_not_null($actual, string $message = 'expected a non-null value'): void
{
    Assert::$count++;
    if ($actual === null) {
        fail($message);
    }
}

function assert_contains(string $needle, string $haystack, string $message = 'substring not found'): void
{
    Assert::$count++;
    if (strpos($haystack, $needle) === false) {
        fail($message . "\n  looking for: " . var_export($needle, true)
            . "\n  within:      " . var_export(mb_strimwidth($haystack, 0, 400, '...'), true));
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = 'unexpected substring present'): void
{
    Assert::$count++;
    if (strpos($haystack, $needle) !== false) {
        fail($message . "\n  did not want: " . var_export($needle, true));
    }
}

function assert_count(int $expected, $countable, string $message = 'wrong count'): void
{
    Assert::$count++;
    $actual = is_countable($countable) ? count($countable) : -1;
    if ($actual !== $expected) {
        fail($message . " (expected {$expected}, got {$actual})");
    }
}

/**
 * Assert that $fn throws an exception (optionally of a given class, optionally
 * whose message contains $messageContains).
 */
function assert_throws(\Closure $fn, ?string $class = null, ?string $messageContains = null, string $message = 'expected an exception'): void
{
    Assert::$count++;
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($class !== null && !($e instanceof $class)) {
            fail($message . ": expected {$class}, got " . get_class($e) . ' -- ' . $e->getMessage());
        }
        if ($messageContains !== null && strpos($e->getMessage(), $messageContains) === false) {
            fail($message . ": message did not contain " . var_export($messageContains, true)
                . " -- got " . var_export($e->getMessage(), true));
        }
        return;
    }
    fail($message . ': no exception was thrown');
}
