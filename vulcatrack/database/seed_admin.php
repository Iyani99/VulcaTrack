<?php
/**
 * VulcaTrack -- create an admin account (COMMAND LINE ONLY).
 *
 * Admins are provisioned internally (Decision 18/40). There is no public admin
 * registration. Run this from a terminal:
 *
 *     php vulcatrack/database/seed_admin.php
 *
 * It prompts for full name, email and password, enforces the 8-character
 * minimum, hashes the password with password_hash(), and inserts one row into
 * `admins`. The password is never printed or logged. Do not commit real
 * credentials.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: seed_admin.php can only be run from the command line.\n");
}

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Support\Validator;

$config = require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/db.php';

/** Read a visible line from STDIN. */
function seed_prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

/** Read a line from STDIN without echoing it (falls back gracefully). */
function seed_prompt_secret(string $label): string
{
    fwrite(STDOUT, $label);

    // Piped / non-interactive input: just read the line.
    if (function_exists('stream_isatty') && defined('STDIN') && !@stream_isatty(STDIN)) {
        $line = fgets(STDIN);
        return $line === false ? '' : trim($line);
    }

    if (function_exists('shell_exec')) {
        if (stripos(PHP_OS, 'WIN') === 0) {
            $value = shell_exec(
                'powershell -NoProfile -Command "$s = Read-Host -AsSecureString; ' .
                '[Runtime.InteropServices.Marshal]::PtrToStringAuto(' .
                '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))"'
            );
            fwrite(STDOUT, "\n");
            return $value === null ? '' : trim($value, "\r\n");
        }

        shell_exec('stty -echo 2>/dev/null');
        $line = fgets(STDIN);
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
        return $line === false ? '' : trim($line);
    }

    fwrite(STDOUT, "(note: input will be visible)\n");
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

fwrite(STDOUT, "VulcaTrack -- create an admin account\n\n");

$minLength = (int) ($config['security']['password_min_length'] ?? 8);

$fullName = seed_prompt('Full name: ');
$email    = seed_prompt('Email: ');
$password = seed_prompt_secret("Password (min {$minLength} chars): ");
$confirm  = seed_prompt_secret('Confirm password: ');

$v = new Validator();
$fullName = $v->text('full_name', $fullName, 'Full name', 150);
$email    = $v->email('email', $email, 190);
$password = $v->password('password', $password, $minLength);
if ($password !== null && $confirm !== $password) {
    $v->add('password_confirmation', 'Passwords do not match.');
}

if ($v->fails()) {
    fwrite(STDERR, "\nCould not create the admin account:\n");
    foreach ($v->errors() as $message) {
        fwrite(STDERR, "  - {$message}\n");
    }
    exit(1);
}

$repo = new AdminRepository(vulcatrack_db());

try {
    $adminId = $repo->create($fullName, $email, Password::hash($password));
} catch (PDOException $ex) {
    if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
        fwrite(STDERR, "An admin with the email '{$email}' already exists.\n");
        exit(1);
    }
    throw $ex;
}

$password = null;
$confirm = null;
unset($password, $confirm);

fwrite(STDOUT, "\nAdmin account created (admin_id = {$adminId}).\n");
exit(0);
