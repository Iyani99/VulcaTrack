<?php
/**
 * Customer Profile + profile picture over HTTP (Phase 7.4b-e2, Decision 77).
 *
 * Real multipart uploads through `php -S`: JPEG / PNG / WebP accepted by their
 * contents (whatever the file name or browser Content-Type), everything else
 * refused; replace / remove clean up the old file; customer/avatar.php only
 * ever serves the signed-in customer's own picture; broken stored references
 * fall back to initials. Plus the existing Profile behaviour (name / contact /
 * password) and the forged-array hardening on Profile and Vehicle edit.
 *
 * Two throwaway customers are seeded via PDO. The server writes into the real
 * storage/avatars/ folder: every file this test creates is removed afterwards,
 * and the folder too if the test created it.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Support\AvatarStore;

test('customer profile: picture upload / replace / remove, isolation, fallback, and profile regressions', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $password = 'profile-password-123';
    $ins = $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)');
    $ins->execute(['Avatar Owner', $emailA = TestDb::email('pf-a'), '09170000011', Password::hash($password)]);
    $custA = (int) $pdo->lastInsertId();
    $ins->execute(['Other Person', $emailB = TestDb::email('pf-b'), '09170000012', Password::hash($password)]);
    $custB = (int) $pdo->lastInsertId();

    $avatarDir = app_path('storage/avatars');
    $dirExisted = is_dir($avatarDir);
    $tempFiles = [];

    $avatarOf = static function (int $id) use ($pdo): ?string {
        $s = $pdo->prepare('SELECT avatar_filename FROM customers WHERE customer_id = ?');
        $s->execute([$id]);
        $v = $s->fetchColumn();
        return $v === false ? null : $v;
    };
    $filesOf = static fn (int $id): array => array_map('basename', glob($avatarDir . '/' . $id . '_*') ?: []);

    $server = new HttpServer(8698);
    $cleanup = function () use ($pdo, $custA, $custB, $avatarDir, $dirExisted, &$tempFiles): void {
        foreach ([$custA, $custB] as $id) {
            foreach (glob($avatarDir . '/' . $id . '_*') ?: [] as $f) {
                @unlink($f);
            }
        }
        if (!$dirExisted && is_dir($avatarDir) && (glob($avatarDir . '/*') ?: []) === []) {
            @rmdir($avatarDir);
        }
        foreach ($tempFiles as $f) {
            @unlink($f);
        }
        $pdo->prepare('DELETE FROM vehicles WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
        $pdo->prepare('DELETE FROM customers WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
    };

    $login = function (string $email, string $pw) use ($server): void {
        $page = $server->request('/vulcatrack/login.php');
        $r = $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($page['body']), 'email' => $email, 'password' => $pw]);
        assert_same(302, $r['status'], "sign in as {$email}");
    };
    $token = fn (): string => (string) HttpServer::csrfToken($server->request('/vulcatrack/customer/profile.php')['body']);
    $logout = function () use ($server, $token): void {
        $server->request('/vulcatrack/logout.php', ['_csrf' => $token()]);
    };
    // the browser-sent Content-Type is always "image/jpeg": it must not matter
    $upload = function (string $bytes, string $clientName, ?string $csrf = null) use ($server, $token, &$tempFiles): array {
        $tmp = ImageFixtures::file($bytes);
        $tempFiles[] = $tmp;
        return $server->request('/vulcatrack/customer/profile.php', [
            '_csrf'   => $csrf ?? $token(),
            '_action' => 'avatar',
            'avatar'  => new \CURLFile($tmp, 'image/jpeg', $clientName),
        ]);
    };
    $headerOf = static fn (array $r, string $name): ?string => preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $r['headers'], $m) ? trim($m[1]) : null;

    try {
        $server->start();

        // --- signed out: no picture, no upload -----------------------------
        $r = $server->request('/vulcatrack/customer/avatar.php');
        assert_same(302, $r['status'], 'avatar.php requires a signed-in customer');
        assert_contains('/login.php', (string) $r['location']);

        $login($emailA, $password);

        // --- profile page, no picture yet: initials, real sections ----------
        $page = $server->request('/vulcatrack/customer/profile.php');
        assert_same(200, $page['status']);
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error'] as $bad) {
            assert_not_contains($bad, $page['body'], "PHP error text on profile.php: {$bad}");
        }
        assert_contains('<span class="ac-avatar" aria-hidden="true">AO</span>', $page['body'], 'initials placeholder in the account panel');
        assert_not_contains('avatar.php', $page['body'], 'no picture URL without a picture');
        assert_contains('enctype="multipart/form-data"', $page['body'], 'the picture has its own upload form');
        assert_contains('href="/vulcatrack/customer/profile.php" class="is-active" aria-current="page"><svg', $page['body'], 'Personal Info is the active account section');
        foreach (['Personal Information', 'Security &amp; Password', 'Update Password', 'Save Changes'] as $txt) {
            assert_contains($txt, $page['body']);
        }
        foreach (['Notifications', 'Tracking'] as $absent) {
            assert_not_contains($absent, $page['body'], "no {$absent}");
        }
        assert_same(404, $server->request('/vulcatrack/customer/avatar.php')['status'], 'no picture -> 404');

        // --- JPEG ------------------------------------------------------------
        $r = $upload(ImageFixtures::jpeg(), 'me.jpg');
        assert_same(302, $r['status'], 'a JPEG is accepted');
        assert_contains('updated=photo', (string) $r['location']);
        $jpg = $avatarOf($custA);
        assert_same(1, preg_match('/^' . $custA . '_[a-f0-9]{32}\.jpg$/', (string) $jpg), 'stored as <id>_<32 hex>.jpg');
        assert_same([$jpg], $filesOf($custA), 'exactly one file on disk');
        $img = $server->request('/vulcatrack/customer/avatar.php');
        assert_same(200, $img['status']);
        assert_same('image/jpeg', $headerOf($img, 'Content-Type'));
        assert_same('nosniff', $headerOf($img, 'X-Content-Type-Options'));
        assert_contains('private', (string) $headerOf($img, 'Cache-Control'));
        assert_same(ImageFixtures::jpeg(), $img['body'], 'the stored bytes are served unchanged');
        $page = $server->request('/vulcatrack/customer/profile.php?updated=photo'); // the redirect target
        $src = '/vulcatrack/customer/avatar.php?v=' . AvatarStore::version($jpg);
        assert_contains('<img class="ac-avatar ac-avatar--photo" src="' . $src . '" alt=""', $page['body'], 'the account panel shows the picture');
        assert_contains('Profile picture updated.', $page['body']);
        assert_contains('<img class="ac-avatar ac-avatar--photo" src="' . $src . '"', $server->request('/vulcatrack/customer/vehicles.php')['body'], 'My Vehicles shows the same picture');

        // --- PNG (named evil.php.jpg): type from contents, old JPEG removed ---
        $r = $upload(ImageFixtures::png(), 'evil.php.jpg');
        assert_same(302, $r['status'], 'a PNG is accepted whatever its file name');
        $png = $avatarOf($custA);
        assert_same(1, preg_match('/^' . $custA . '_[a-f0-9]{32}\.png$/', (string) $png), 'extension from the verified type, not the name');
        assert_same([$png], $filesOf($custA), 'the replaced JPEG is deleted');
        assert_same('image/png', $headerOf($server->request('/vulcatrack/customer/avatar.php'), 'Content-Type'));

        // --- WebP ------------------------------------------------------------
        $r = $upload(ImageFixtures::webp(), 'photo.webp');
        assert_same(302, $r['status'], 'a WebP is accepted');
        $webp = $avatarOf($custA);
        assert_same(1, preg_match('/^' . $custA . '_[a-f0-9]{32}\.webp$/', (string) $webp));
        assert_same([$webp], $filesOf($custA), 'the replaced PNG is deleted');

        // --- refused uploads leave the current picture alone -----------------
        $refused = [
            'text renamed .jpg'   => ['just text, not an image', 'notes.jpg', 'Choose a JPEG, PNG or WebP image.'],
            'PHP renamed .jpg'    => [ImageFixtures::php(), 'shell.php.jpg', 'Choose a JPEG, PNG or WebP image.'],
            'fake JPEG header'    => [ImageFixtures::fakeJpeg(), 'x.jpg', 'Choose a JPEG, PNG or WebP image.'],
            'PDF'                 => [ImageFixtures::pdf(), 'doc.pdf', 'Choose a JPEG, PNG or WebP image.'],
            'GIF'                 => [ImageFixtures::gif(), 'anim.gif', 'Choose a JPEG, PNG or WebP image.'],
            'over 5 MB'           => [ImageFixtures::png() . str_repeat("\0", AvatarStore::MAX_BYTES), 'big.png', 'The selected image is too large.'],
        ];
        foreach ($refused as $label => [$bytes, $name, $message]) {
            $r = $upload($bytes, $name);
            assert_same(200, $r['status'], "{$label}: the form is shown again");
            assert_contains($message, $r['body'], "{$label}: a clear message");
            assert_contains('id="err-avatar"', $r['body'], "{$label}: the error sits with the picture form");
            assert_same($webp, $avatarOf($custA), "{$label}: the stored picture is unchanged");
            assert_same([$webp], $filesOf($custA), "{$label}: no file written");
        }
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'avatar']);
        assert_contains('Choose an image to upload.', $r['body'], 'no file selected');
        $r = $upload(ImageFixtures::png(), 'ok.png', 'bogus-token');
        assert_contains('Your session expired', $r['body'], 'a bad CSRF token is refused');
        assert_same($webp, $avatarOf($custA), 'bad CSRF changes nothing');

        // --- broken stored references: initials + 404, never a broken page ---
        foreach (['../../config/config.php', $custB . '_' . str_repeat('a', 32) . '.png', $custA . '_' . str_repeat('b', 32) . '.png'] as $broken) {
            $pdo->prepare('UPDATE customers SET avatar_filename = ? WHERE customer_id = ?')->execute([$broken, $custA]);
            $page = $server->request('/vulcatrack/customer/profile.php');
            assert_same(200, $page['status'], "profile still renders with a broken reference ({$broken})");
            assert_contains('<span class="ac-avatar" aria-hidden="true">AO</span>', $page['body'], 'falls back to initials');
            assert_not_contains('<img class="ac-avatar', $page['body']);
            assert_same(404, $server->request('/vulcatrack/customer/avatar.php')['status'], "avatar.php 404s ({$broken})");
        }
        $pdo->prepare('UPDATE customers SET avatar_filename = ? WHERE customer_id = ?')->execute([$webp, $custA]);

        // --- isolation: B never gets A's picture ------------------------------
        $logout();
        $login($emailB, $password);
        foreach (['', '?v=' . AvatarStore::version($webp), '?customer_id=' . $custA . '&filename=' . $webp . '&path=' . urlencode($avatarDir . '/' . $webp)] as $q) {
            $r = $server->request('/vulcatrack/customer/avatar.php' . $q);
            assert_same(404, $r['status'], "B has no picture, whatever the query ({$q})");
            assert_not_contains(ImageFixtures::webp(), $r['body']);
        }
        $r = $upload(ImageFixtures::png(5, 5), 'b.png');
        assert_same(302, $r['status']);
        assert_same(ImageFixtures::png(5, 5), $server->request('/vulcatrack/customer/avatar.php')['body'], 'B gets B\'s own picture');
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'avatar_remove']);
        assert_same(302, $r['status']);
        assert_null($avatarOf($custB), 'B\'s remove clears only B');
        assert_same([], $filesOf($custB));
        assert_same($webp, $avatarOf($custA), 'A is untouched by B');
        assert_same([$webp], $filesOf($custA));
        $logout();
        $login($emailA, $password);

        // --- remove: NULL, file gone, initials back ---------------------------
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => 'bogus', '_action' => 'avatar_remove']);
        assert_same($webp, $avatarOf($custA), 'remove with a bad token changes nothing');
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'avatar_remove']);
        assert_same(302, $r['status']);
        assert_contains('updated=photo_removed', (string) $r['location']);
        assert_null($avatarOf($custA), 'remove sets the column to NULL');
        assert_same([], $filesOf($custA), 'and deletes the file');
        $page = $server->request('/vulcatrack/customer/profile.php?updated=photo_removed');
        assert_contains('<span class="ac-avatar" aria-hidden="true">AO</span>', $page['body'], 'initials are back');
        assert_contains('Profile picture removed.', $page['body']);
        assert_same(404, $server->request('/vulcatrack/customer/avatar.php')['status']);

        // --- existing Profile behaviour ---------------------------------------
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'profile', 'full_name' => 'Renamed Owner', 'contact_number' => '09998887777']);
        assert_same(302, $r['status'], 'profile save redirects');
        $s = $pdo->prepare('SELECT full_name, contact_number FROM customers WHERE customer_id = ?');
        $s->execute([$custA]);
        assert_same(['full_name' => 'Renamed Owner', 'contact_number' => '09998887777'], $s->fetch(), 'name + contact saved');
        $page = $server->request('/vulcatrack/customer/profile.php');
        assert_contains('<span class="appbar__name">Renamed Owner</span>', $page['body'], 'the session name follows the edit');
        assert_contains('value="' . $emailA . '" disabled', $page['body'], 'email stays read-only');

        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'profile', 'full_name' => '', 'contact_number' => '1']);
        assert_contains('Full name is required.', $r['body']);
        assert_contains('Contact number is too short.', $r['body']);

        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'password', 'current_password' => 'wrong-password', 'new_password' => 'brand-new-pass-1', 'new_password_confirmation' => 'brand-new-pass-1']);
        assert_contains('Current password is incorrect.', $r['body'], 'wrong current password refused');
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'password', 'current_password' => $password, 'new_password' => 'brand-new-pass-1', 'new_password_confirmation' => 'different-pass-1']);
        assert_contains('id="err-new_password_confirmation"', $r['body'], 'mismatched confirmation refused');
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'password', 'current_password' => $password, 'new_password' => 'brand-new-pass-1', 'new_password_confirmation' => 'brand-new-pass-1']);
        assert_same(302, $r['status'], 'a valid password change redirects');
        assert_contains('updated=password', (string) $r['location']);
        $logout();
        $login($emailA, 'brand-new-pass-1'); // the new password works

        // --- forged array fields: a normal re-render, never a 500 -------------
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'profile', 'full_name' => ['x'], 'contact_number' => ['y']]);
        assert_same(200, $r['status'], 'full_name[] on Profile re-renders (no TypeError)');
        assert_contains('Full name is required.', $r['body']);
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => ['x'], '_action' => 'profile']);
        assert_same(200, $r['status'], '_csrf[] on Profile is a normal refusal');
        assert_contains('Your session expired', $r['body']);
        $r = $server->request('/vulcatrack/customer/vehicle-edit.php', ['_csrf' => $token(), 'plate_number' => ['x'], 'vehicle_type' => ['SUV'], 'make' => ['m'], 'model' => ['n']]);
        assert_same(200, $r['status'], 'plate_number[] on Vehicle edit re-renders (no TypeError)');
        assert_contains('Plate number is required.', $r['body']);
        $s = $pdo->prepare('SELECT COUNT(*) FROM vehicles WHERE customer_id = ?');
        $s->execute([$custA]);
        assert_same(0, (int) $s->fetchColumn(), 'nothing saved from forged arrays');

        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
        $server->stop();

        // --- a body over post_max_size: PHP drops ALL of it (CSRF token too) --
        // A second server with post_max_size = 1M (via an extra ini dir) keeps
        // this fast; the real limit is only a bigger number.
        $iniDir = sys_get_temp_dir() . '/vt_ini_' . bin2hex(random_bytes(4));
        mkdir($iniDir);
        file_put_contents($iniDir . '/post-limit.ini', "post_max_size=1M\n");
        $tempFiles[] = $iniDir . '/post-limit.ini';
        $small = new HttpServer(8699, ['PHP_INI_SCAN_DIR' => $iniDir]);
        try {
            $small->start();
            $page = $small->request('/vulcatrack/login.php');
            $small->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($page['body']), 'email' => $emailA, 'password' => 'brand-new-pass-1']);
            $pdo->prepare('UPDATE customers SET avatar_filename = ? WHERE customer_id = ?')->execute([$webp, $custA]); // a (missing-file) reference that must survive
            $big = ImageFixtures::file(ImageFixtures::png() . str_repeat("\0", 2 * 1024 * 1024));
            $tempFiles[] = $big;
            $r = $small->request('/vulcatrack/customer/profile.php', [
                '_csrf' => (string) HttpServer::csrfToken($small->request('/vulcatrack/customer/profile.php')['body']),
                '_action' => 'avatar', 'avatar' => new \CURLFile($big, 'image/png', 'huge.png'),
            ]);
            assert_contains('POST Content-Length', $small->serverStderr(), 'the body really exceeded post_max_size');
            assert_same(200, $r['status']);
            assert_contains('The selected image is too large.', $r['body'], 'over post_max_size: "too large"…');
            assert_not_contains('Your session expired', $r['body'], '…not "session expired"');
            assert_same($webp, $avatarOf($custA), 'nothing changed');
        } finally {
            $small->stop();
            @unlink($iniDir . '/post-limit.ini');
            @rmdir($iniDir);
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
