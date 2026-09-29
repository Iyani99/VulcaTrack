<?php
/**
 * Unit tests for VulcaTrack\Support\AvatarStore -- the private profile picture
 * store (Phase 7.4b-e2, Decision 77). Runs against a throwaway temp folder with
 * uploadsOnly = false so fixture files stand in for PHP uploads (the real
 * is_uploaded_file / move_uploaded_file path is covered by the HTTP test).
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Support\AvatarException;
use VulcaTrack\Support\AvatarStore;

/** A fresh AvatarStore on its own temp folder (folder not created yet). */
function avatar_store(): array
{
    $dir = sys_get_temp_dir() . '/vt_avatars_' . bin2hex(random_bytes(6));
    return [new AvatarStore($dir, false), $dir];
}

function avatar_cleanup(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    // the fixture "uploads" (ImageFixtures::file) that store() copied from
    foreach (glob(sys_get_temp_dir() . '/vtimg*') ?: [] as $f) {
        @unlink($f);
    }
}

/** A $_FILES-style entry for fixture bytes. */
function avatar_upload(string $bytes, int $error = UPLOAD_ERR_OK): array
{
    $tmp = ImageFixtures::file($bytes);
    return ['name' => 'whatever.php.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($bytes)];
}

test('AvatarStore accepts JPEG, PNG and WebP by their real contents', function () {
    [$store, $dir] = avatar_store();
    foreach (['jpg' => ImageFixtures::jpeg(), 'png' => ImageFixtures::png(), 'webp' => ImageFixtures::webp()] as $ext => $bytes) {
        $up = avatar_upload($bytes);
        assert_same($ext, $store->validate($up), "{$ext} is accepted, extension from the verified type");
        @unlink($up['tmp_name']);
    }
    avatar_cleanup($dir);
});

test('AvatarStore refuses GIF, PDF, PHP, a fake JPEG header and empty / failed uploads', function () {
    [$store] = avatar_store();
    $cases = [
        'GIF'          => [ImageFixtures::gif(), 'JPEG, PNG or WebP'],
        'PDF'          => [ImageFixtures::pdf(), 'JPEG, PNG or WebP'],
        'PHP'          => [ImageFixtures::php(), 'JPEG, PNG or WebP'],
        'fake JPEG'    => [ImageFixtures::fakeJpeg(), 'JPEG, PNG or WebP'],
        'plain text'   => ['just some text', 'JPEG, PNG or WebP'],
        'empty file'   => ['', 'Choose an image'],
    ];
    foreach ($cases as $label => [$bytes, $message]) {
        $up = avatar_upload($bytes);
        assert_throws(fn () => $store->validate($up), AvatarException::class, $message, "{$label} must be refused");
        @unlink($up['tmp_name']);
    }
    assert_throws(fn () => $store->validate(null), AvatarException::class, 'Choose an image');
    assert_throws(fn () => $store->validate(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']), AvatarException::class, 'Choose an image');
    assert_throws(fn () => $store->validate(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '']), AvatarException::class, 'too large');
    assert_throws(fn () => $store->validate(['error' => UPLOAD_ERR_FORM_SIZE, 'tmp_name' => '']), AvatarException::class, 'too large');
    assert_throws(fn () => $store->validate(['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '']), AvatarException::class, 'Please try again');
    // avatar[] posted as an array: every key is an array
    assert_throws(fn () => $store->validate(['error' => [0], 'tmp_name' => ['x']]), AvatarException::class, 'Please try again');
});

test('AvatarStore refuses a file over 5 MB even when it starts like an image', function () {
    [$store] = avatar_store();
    $up = avatar_upload(ImageFixtures::png() . str_repeat("\0", AvatarStore::MAX_BYTES));
    assert_throws(fn () => $store->validate($up), AvatarException::class, 'too large');
    @unlink($up['tmp_name']);
});

test('AvatarStore stores under a generated name, records it, and cleans up when recording fails', function () {
    [$store, $dir] = avatar_store();

    $up = avatar_upload(ImageFixtures::webp());
    $recorded = null;
    $name = $store->store($up, 42, function (string $n) use (&$recorded): void { $recorded = $n; });
    assert_same(1, preg_match('/^42_[a-f0-9]{32}\.webp$/', $name), 'name = <customer_id>_<32 hex>.<verified ext>');
    assert_same($name, $recorded, 'the generated name is what gets recorded');
    assert_true(is_file($dir . '/' . $name), 'the file is stored');
    assert_same($dir . '/' . $name, $store->pathFor($name, 42));
    assert_same('image/webp', AvatarStore::mimeFor($name));

    $second = $store->store(avatar_upload(ImageFixtures::webp()), 42, function (): void {});
    assert_true($second !== $name, 'every upload gets a new name (never overwrites)');

    // the database update fails -> the new file is removed, the error propagates
    $before = glob($dir . '/*');
    assert_throws(function () use ($store): void {
        $store->store(avatar_upload(ImageFixtures::png()), 42, function (): void { throw new \RuntimeException('db down'); });
    }, \RuntimeException::class, 'db down');
    assert_same($before, glob($dir . '/*'), 'no file is left behind by a failed record');

    // deleting: only valid names of this customer
    assert_false($store->delete($name, 7), 'another customer id cannot delete it');
    assert_true($store->delete($name, 42));
    assert_null($store->pathFor($name, 42), 'gone after delete');
    assert_false($store->delete($name, 42), 'deleting a missing file is a harmless false');

    avatar_cleanup($dir);
});

test('AvatarStore only resolves strictly well-formed names of the given customer', function () {
    $hex = str_repeat('ab', 16);
    assert_true(AvatarStore::isValidName("12_{$hex}.jpg"));
    assert_true(AvatarStore::isValidName("12_{$hex}.png", 12));
    foreach ([
        null, '', "12_{$hex}.gif", "12_{$hex}.php", "12_{$hex}.jpg.php", "12_" . strtoupper($hex) . '.jpg',
        "12_{$hex}", "../12_{$hex}.jpg", "12_{$hex}.jpg/../../config/config.php", "x_{$hex}.jpg", "12_abc.jpg",
        "12_{$hex}.jpg\n",
    ] as $bad) {
        assert_false(AvatarStore::isValidName($bad), 'must be refused: ' . var_export($bad, true));
    }
    assert_false(AvatarStore::isValidName("12_{$hex}.png", 13), 'a name for another customer id is refused');

    [$store, $dir] = avatar_store();
    assert_null($store->pathFor("12_{$hex}.png", 12), 'a valid name with no file resolves to null');
    assert_null($store->displayUrl(null, 12), 'no picture -> no URL (initials)');
    assert_null($store->displayUrl("12_{$hex}.png", 12), 'missing file -> no URL (never a broken image)');

    mkdir($dir);
    file_put_contents($dir . "/12_{$hex}.png", ImageFixtures::png());
    assert_same(vulcatrack_url('/customer/avatar.php?v=' . $hex), $store->displayUrl("12_{$hex}.png", 12), 'URL = the endpoint + a cache-busting version');
    assert_null($store->displayUrl("12_{$hex}.png", 99), 'never another customer\'s file');
    avatar_cleanup($dir);
});
