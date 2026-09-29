<?php

namespace VulcaTrack\Support;

/**
 * Customer profile pictures on disk (Phase 7.4b-e2, Decision 77).
 *
 * Files live in PRIVATE storage (storage/avatars/, denied to the web by
 * storage/.htaccess) and reach the browser only through customer/avatar.php,
 * which serves the signed-in customer's own picture. The database keeps just
 * the generated file name (customers.avatar_filename).
 *
 *  - accepted: JPEG, PNG, WebP up to 5 MB — the real type is read from the file
 *    contents (finfo + getimagesize); the client's file name and Content-Type
 *    are never trusted.
 *  - stored as <customer_id>_<32 random hex>.<jpg|png|webp>: a new name for
 *    every upload, so a replacement never overwrites the working picture.
 *  - no resizing / re-encoding (no GD / Imagick dependency); the page crops the
 *    display with CSS.
 *
 * No SQL, sessions or HTML here: store() takes the database update as a
 * callback so the caller keeps the order "file first, then the row, then drop
 * the old file" — and a failed row update removes the new file again.
 */
final class AvatarStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_DIMENSION = 10000;

    /** verified MIME type => [stored extension, getimagesize() type] */
    private const TYPES = [
        'image/jpeg' => ['jpg', IMAGETYPE_JPEG],
        'image/png'  => ['png', IMAGETYPE_PNG],
        'image/webp' => ['webp', IMAGETYPE_WEBP],
    ];

    // D: "$" is the true end (no trailing newline), so the whole value must match
    private const NAME_PATTERN = '/^(\d+)_([a-f0-9]{32})\.(jpg|png|webp)$/D';

    public const TOO_LARGE = 'The selected image is too large. The limit is 5 MB.';
    private const NO_FILE = 'Choose an image to upload.';
    private const BAD_TYPE = 'Choose a JPEG, PNG or WebP image.';
    private const FAILED = 'The image could not be uploaded. Please try again.';
    private const UNAVAILABLE = 'Profile pictures cannot be saved right now. Please try again later.';

    /**
     * @param bool $uploadsOnly  true in the app: accept only files PHP received as
     *                           uploads (is_uploaded_file / move_uploaded_file).
     *                           Tests pass false to feed fixture files.
     */
    public function __construct(private string $dir, private bool $uploadsOnly = true)
    {
    }

    /** The application's avatar folder. */
    public static function forApp(): self
    {
        return new self(VULCATRACK_ROOT . '/storage/avatars');
    }

    /** True for a well-formed generated name, optionally for this customer only. */
    public static function isValidName(?string $filename, ?int $customerId = null): bool
    {
        if ($filename === null || preg_match(self::NAME_PATTERN, $filename, $m) !== 1) {
            return false;
        }
        return $customerId === null || $m[1] === (string) $customerId;
    }

    /**
     * Check an entry of $_FILES and return the verified extension.
     *
     * @param mixed $file
     * @throws AvatarException
     */
    public function validate($file): string
    {
        if (!is_array($file) || !array_key_exists('error', $file) || !array_key_exists('tmp_name', $file)) {
            throw new AvatarException(self::NO_FILE);
        }
        $error = $file['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new AvatarException(self::NO_FILE);
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new AvatarException(self::TOO_LARGE);
        }
        if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
            throw new AvatarException(self::FAILED); // incl. array-shaped (forged) entries
        }
        $tmp = $file['tmp_name'];
        if ($this->uploadsOnly ? !is_uploaded_file($tmp) : !is_file($tmp)) {
            throw new AvatarException(self::FAILED);
        }

        $size = filesize($tmp);
        if ($size === false || $size === 0) {
            throw new AvatarException(self::NO_FILE);
        }
        if ($size > self::MAX_BYTES) {
            throw new AvatarException(self::TOO_LARGE);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new AvatarException(self::BAD_TYPE);
        }
        [$ext, $imageType] = self::TYPES[$mime];

        $info = @getimagesize($tmp);
        if ($info === false || $info[2] !== $imageType
            || $info[0] < 1 || $info[1] < 1
            || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw new AvatarException(self::BAD_TYPE);
        }

        return $ext;
    }

    /**
     * Validate and store an upload under a new generated name, then call
     * $record($name) to save that name. If $record throws, the new file is
     * deleted and the exception is rethrown (the old picture stays in use).
     * The caller deletes the previous file only after this returns.
     *
     * @param mixed $file  an entry of $_FILES
     * @param callable(string):void $record
     * @throws AvatarException on a refused or unstorable upload
     */
    public function store($file, int $customerId, callable $record): string
    {
        $ext = $this->validate($file);

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new AvatarException(self::UNAVAILABLE);
        }
        $name = $customerId . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $target = $this->dir . '/' . $name;
        $moved = $this->uploadsOnly
            ? @move_uploaded_file($file['tmp_name'], $target)
            : @copy($file['tmp_name'], $target);
        if (!$moved) {
            throw new AvatarException(self::UNAVAILABLE);
        }

        try {
            $record($name);
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }

        return $name;
    }

    /**
     * Readable private path of a stored picture, or null when the name is
     * malformed, belongs to another customer, or the file is missing/unreadable.
     */
    public function pathFor(?string $filename, ?int $customerId = null): ?string
    {
        if (!self::isValidName($filename, $customerId)) {
            return null;
        }
        $path = $this->dir . '/' . $filename;
        return is_file($path) && is_readable($path) ? $path : null;
    }

    /** Best-effort removal of a stored picture; false if not removed (harmless orphan). */
    public function delete(?string $filename, ?int $customerId = null): bool
    {
        $path = $this->pathFor($filename, $customerId);
        return $path !== null && @unlink($path);
    }

    /** Content-Type for a valid stored name (by its generated extension). */
    public static function mimeFor(string $filename): string
    {
        foreach (self::TYPES as $mime => [$ext]) {
            if (substr($filename, -strlen($ext) - 1) === '.' . $ext) {
                return $mime;
            }
        }
        return 'application/octet-stream';
    }

    /**
     * Host-relative URL to show the customer's own picture (customer/avatar.php
     * plus a cache-busting ?v=), or null when there is no usable file — the
     * page then shows initials, never a broken image.
     */
    public function displayUrl(?string $filename, int $customerId): ?string
    {
        if ($this->pathFor($filename, $customerId) === null) {
            return null;
        }
        return vulcatrack_url('/customer/avatar.php?v=' . self::version((string) $filename));
    }

    /** Cache-busting token for the display URL (the name's random part), or null. */
    public static function version(string $filename): ?string
    {
        return preg_match(self::NAME_PATTERN, $filename, $m) === 1 ? $m[2] : null;
    }
}
