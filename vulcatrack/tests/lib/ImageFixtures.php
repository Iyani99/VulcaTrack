<?php
/**
 * VulcaTrack test harness -- tiny image / non-image payloads for the profile
 * picture tests (Phase 7.4b-e2). Built without GD: real 1x1 JPEG / WebP / GIF
 * bytes and a generated PNG, plus disguised non-images.
 */

namespace VulcaTrack\Tests;

final class ImageFixtures
{
    public static function jpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }

    /** A valid RGB PNG of the given size (distinct sizes = distinct bytes). */
    public static function png(int $w = 2, int $h = 3): string
    {
        $chunk = static fn (string $t, string $d): string => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        $raw = str_repeat("\x00" . str_repeat("\xcc\x20\x20", $w), $h);
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
    }

    public static function webp(): string
    {
        return base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');
    }

    public static function gif(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    }

    public static function php(): string
    {
        return "<?php echo 'owned'; ?>";
    }

    /** JPEG magic bytes followed by PHP: finfo says image/jpeg, getimagesize() refuses it. */
    public static function fakeJpeg(): string
    {
        return "\xFF\xD8\xFF\xE0<?php echo 'owned'; ?>";
    }

    /** Write bytes to a fresh temp file and return its path (caller deletes). */
    public static function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vtimg');
        file_put_contents($path, $bytes);
        return $path;
    }
}
