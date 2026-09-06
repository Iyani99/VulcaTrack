<?php
/**
 * VulcaTrack test runner -- dependency-free.
 *
 *   php vulcatrack/tests/run.php              # all suites
 *   php vulcatrack/tests/run.php unit         # one suite (unit|integration|http)
 *   php vulcatrack/tests/run.php --filter=Geo # only tests whose name matches
 *
 * Exit code 0 = all passed, 1 = one or more failures (usable in CI later).
 *
 * Each *Test.php file registers cases with test('name', fn). Cases run in
 * registration order; a case fails on the first failed assertion or any
 * uncaught Throwable. During a case, PHP warnings / notices / deprecations are
 * promoted to exceptions, so "no warnings" is enforced, not hoped for.
 */

namespace VulcaTrack\Tests;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("run.php is CLI-only.\n");
}

require __DIR__ . '/bootstrap.php';

$suites = ['unit', 'integration', 'http'];
$only = null;
$filter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--filter=') === 0) {
        $filter = substr($arg, 9);
    } elseif (in_array($arg, $suites, true)) {
        $only = $arg;
    }
}
$runSuites = $only !== null ? [$only] : $suites;

$files = [];
foreach ($runSuites as $suite) {
    foreach (glob(__DIR__ . '/' . $suite . '/*Test.php') ?: [] as $file) {
        $files[] = $file;
    }
}
sort($files);

$totalPass = 0;
$totalFail = 0;
$failures = [];

// Promote warnings/notices to exceptions for the duration of a test case.
$strictHandler = static function (int $severity, string $msg, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new \ErrorException("PHP: {$msg}", 0, $severity, $file, $line);
};

foreach ($files as $file) {
    Assert::$tests = [];
    require $file;
    $suiteLabel = basename(dirname($file)) . '/' . basename($file, '.php');

    foreach (Assert::$tests as [$name, $fn]) {
        if ($filter !== null && stripos($name, $filter) === false && stripos($suiteLabel, $filter) === false) {
            continue;
        }
        set_error_handler($strictHandler);
        ob_start();
        try {
            $fn();
            $stray = ob_get_clean();
            restore_error_handler();
            if (trim((string) $stray) !== '') {
                throw new AssertionFailed('unexpected output from test: ' . trim((string) $stray));
            }
            $totalPass++;
            fwrite(STDOUT, "  PASS  {$suiteLabel} :: {$name}\n");
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            restore_error_handler();
            $totalFail++;
            $failures[] = "{$suiteLabel} :: {$name}\n        " . str_replace("\n", "\n        ", $e->getMessage());
            fwrite(STDOUT, "  FAIL  {$suiteLabel} :: {$name}\n");
        }
    }
}

fwrite(STDOUT, "\n");
fwrite(STDOUT, str_repeat('-', 60) . "\n");
fwrite(STDOUT, sprintf(
    "%d passed, %d failed, %d assertions across %d files\n",
    $totalPass,
    $totalFail,
    Assert::$count,
    count($files)
));

if ($failures) {
    fwrite(STDOUT, "\nFAILURES:\n");
    foreach ($failures as $f) {
        fwrite(STDOUT, "  - {$f}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK\n");
exit(0);
