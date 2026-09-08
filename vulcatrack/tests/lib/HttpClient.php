<?php
/**
 * VulcaTrack test harness -- a tiny HTTP client + `php -S` process manager.
 *
 * Used only by tests/http/. Spins up the PHP built-in server with the REPO ROOT
 * as the document root (so URL paths are `/vulcatrack/...`, matching the app's
 * base_url and the `/vulcatrack/` session-cookie path), then makes cookie-aware
 * requests with libcurl. The server is torn down in a shutdown handler.
 */

namespace VulcaTrack\Tests;

final class HttpServer
{
    /** @var resource|null */
    private $proc = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private int $port;
    private string $cookieJar;
    private string $logFile;
    /** @var array<string,string> extra environment for the php -S child */
    private array $extraEnv;

    /** @param array<string,string> $extraEnv extra env vars for the served app */
    public function __construct(int $port = 8677, array $extraEnv = [])
    {
        $this->port = $port;
        $this->extraEnv = $extraEnv;
        $base = sys_get_temp_dir() . '/vulcatrack_test_' . getmypid();
        $this->cookieJar = $base . '_cookies.txt';
        $this->logFile = $base . '_serverlog.txt';
    }

    public function start(): void
    {
        $repoRoot = dirname(VULCATRACK_APP_ROOT);
        $php = PHP_BINARY;
        $cmd = escapeshellarg($php) . ' -d display_errors=1 -d error_reporting=' . E_ALL
             . ' -S 127.0.0.1:' . $this->port
             . ' -t ' . escapeshellarg($repoRoot);

        @unlink($this->logFile);
        // Inherit the parent environment and layer any extras on top (Windows
        // php.exe needs SystemRoot etc., so a partial env is not an option).
        $env = $this->extraEnv === [] ? null : array_merge(getenv(), $this->extraEnv);
        // stdout/stderr go to a FILE, not a pipe: an undrained pipe buffer would
        // deadlock the single-threaded built-in server after a few dozen requests.
        $this->proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['file', $this->logFile, 'a'], 2 => ['file', $this->logFile, 'a']],
            $this->pipes,
            null,
            $env
        );
        if (!is_resource($this->proc)) {
            throw new \RuntimeException('could not start php -S');
        }
        register_shutdown_function([$this, 'stop']);

        // Wait for the port to accept connections.
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.5);
            if (is_resource($conn)) {
                fclose($conn);
                @unlink($this->cookieJar);
                return;
            }
            usleep(150_000);
        }
        throw new \RuntimeException('php -S did not come up on port ' . $this->port);
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if ($status['running'] ?? false) {
                // proc_terminate can miss the real php.exe child on Windows.
                if (stripos(PHP_OS, 'WIN') === 0 && !empty($status['pid'])) {
                    @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
                }
                @proc_terminate($this->proc);
            }
            foreach ($this->pipes as $p) {
                if (is_resource($p)) {
                    @fclose($p);
                }
            }
            @proc_close($this->proc);
            $this->proc = null;
        }
        @unlink($this->cookieJar);
        @unlink($this->logFile);
    }

    /** Everything the built-in server wrote to stdout+stderr so far. */
    public function serverStderr(): string
    {
        clearstatcache();
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /**
     * @param array<string,string>|null $post          null => GET, array => form POST
     * @param array<int,string>          $extraHeaders  extra raw request headers,
     *        e.g. ['Host: vulcatrack.lan'] to simulate a LAN / other-host client
     * @return array{status:int,headers:string,body:string,location:?string}
     */
    public function request(string $path, ?array $post = null, bool $followRedirects = false, array $extraHeaders = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . $this->port . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            // PHP's single-threaded built-in server can wedge on kept-alive
            // connections; force a fresh connection per request and ask the
            // server to close it.
            CURLOPT_FRESH_CONNECT  => true,
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_HTTPHEADER     => array_merge(['Connection: close'], $extraHeaders),
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('curl failed for ' . $path . ': ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'location' => $location];
    }

    /** Pull the CSRF token out of a rendered form. */
    public static function csrfToken(string $html): ?string
    {
        return preg_match('/name="_csrf"\s+value="([a-f0-9]+)"/i', $html, $m) ? $m[1] : null;
    }
}
