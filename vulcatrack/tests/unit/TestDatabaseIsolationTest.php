<?php
/** Test-mode target validation needs no database connection. */

namespace VulcaTrack\Tests;

test('test database name guard accepts only vulcatrack_test', function () {
    \vulcatrack_assert_test_database_name('vulcatrack_test');
    foreach ([null, '', 'vulcatrack', 'other_test', 'VULCATRACK_TEST'] as $wrong) {
        assert_throws(
            static fn () => \vulcatrack_assert_test_database_name($wrong),
            \RuntimeException::class,
            'vulcatrack_test'
        );
    }
});
