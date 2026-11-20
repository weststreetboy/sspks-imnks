<?php

namespace SSpkS\Handler;

use SSpkS\Output\HtmlOutput;
use think\facade\Cache;

/**
 * Package upload endpoint: ?action=upload
 *
 * GET  renders the upload form, POST accepts a single .spk file into the
 * configured package directory. The management password (X-SSpkS-Token header,
 * or the token form field) is required for every request, exactly like the
 * index update endpoint.
 */
final class UploadHandler extends AbstractHandler
{
    private const ACTION = 'upload';
    private const AUTH_FAILURE_LIMIT = 5;
    private const AUTH_FAILURE_WINDOW = 60;
    private const FIELD = 'spk';

    public function canHandle(): bool
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true)
            && $requestPath === $this->config->baseUrlRelative
            && count($_GET) === 1
            && isset($_GET['action'])
            && is_string($_GET['action'])
            && hash_equals(self::ACTION, $_GET['action']);
    }

    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $this->outputPage();
            return;
        }

        $this->runUpload();
    }

    private function outputPage(): void
    {
        header('Cache-Control: no-store');
        $output = new HtmlOutput($this->config);
        $output->setVariable('uploadEndpoint', $this->config->baseUrlRelative . '?action=' . self::ACTION);
        $limitLabel = $this->formatLimit($this->limit($this->iniBytes('upload_max_filesize')));
        $output->setVariable('uploadHint', $this->language->get('upload_hint', ['limit' => $limitLabel]));
        $output->setVariable('hideSetupInfo', true);
        $output->setVariable('noIndex', true);
        $output->setVariable('pageTitle', $this->language->get('upload_title') . ' - ' . $this->config->site['name']);
        $output->setTemplate('html_upload');
        $output->output();
    }

    private function runUpload(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        if ($this->isRateLimited()) {
            http_response_code(429);
            header('Retry-After: 60');
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_auth_rate_limited')]);
            return;
        }

        $token = $this->providedToken();
        $configured = (string) ($this->config->update_token ?? '');
        if ($token === '' || $configured === '' || !hash_equals($configured, $token)) {
            $this->recordAuthFailure();
            http_response_code(401);
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_auth_invalid')]);
            return;
        }
        $this->clearAuthFailures();

        $file = $_FILES[self::FIELD] ?? null;
        if (!is_array($file) || !isset($file['error'])) {
            http_response_code(400);
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_no_file')]);
            return;
        }

        $error = (int) $file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            http_response_code($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE ? 413 : 400);
            $message = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? $this->language->get('upload_too_big')
                : $this->language->get('upload_failed');
            $this->emit(['ok' => false, 'message' => $message]);
            return;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $originalName = (string) ($file['name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            http_response_code(400);
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_no_file')]);
            return;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $this->limit($this->iniBytes('upload_max_filesize')) + 1) {
            http_response_code(413);
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_too_big')]);
            return;
        }

        $name = $this->safeFileName($originalName);
        if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'spk') {
            http_response_code(400);
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_bad_type')]);
            return;
        }

        try {
            $directory = $this->packageDirectory();
        } catch (\Throwable $e) {
            error_log('[SSpkS] Upload directory unavailable: ' . $e->getMessage());
            http_response_code(503);
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_failed')]);
            return;
        }

        $target = $directory . DIRECTORY_SEPARATOR . $name;
        if (file_exists($target)) {
            http_response_code(409);
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_dup')]);
            return;
        }

        // Move into a hidden temporary name first so an interrupted copy never
        // leaves a half written .spk that the indexer would try to parse.
        $partial = $directory . DIRECTORY_SEPARATOR . '.upload-' . bin2hex(random_bytes(8)) . '.part';
        if (!move_uploaded_file($tmpName, $partial)) {
            http_response_code(500);
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_failed')]);
            return;
        }
        @chmod($partial, 0644);
        if (!@rename($partial, $target)) {
            @unlink($partial);
            http_response_code(500);
            $this->emit(['ok' => false, 'message' => $this->language->get('upload_failed')]);
            return;
        }

        // Clear derived caches so the next index run picks the new file up.
        try {
            Cache::clear();
        } catch (\Throwable $e) {
            error_log('[SSpkS] Cache clear after upload failed: ' . $e->getMessage());
        }

        $this->emit([
            'ok' => true,
            'name' => $name,
            'size' => $size,
            'message' => $this->language->get('upload_ok', ['name' => $name]),
            'next' => $this->language->get('upload_need_index'),
            'indexUrl' => $this->config->baseUrlRelative . '?action=' . rawurlencode((string) $this->config->update['action']),
        ]);
    }

    private function packageDirectory(): string
    {
        $path = (string) ($this->config->paths['packages'] ?? 'packages/');
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) !== 1) {
            $path = $this->config->basePath . DIRECTORY_SEPARATOR . $path;
        }
        $path = rtrim($path, '/\\');
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create the package directory');
        }
        if (!is_writable($path)) {
            throw new \RuntimeException('The package directory is not writable');
        }
        return $path;
    }

    private function safeFileName(string $name): string
    {
        $name = str_replace(['\\', '/'], '', trim($name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        // Keep an ASCII skeleton plus CJK characters; everything else becomes '_'.
        $name = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $name) ?? '';
        $name = ltrim($name, '.-');
        if ($name === '' || strlen($name) > 200) {
            return '';
        }
        return $name;
    }

    private function limit(int $serverLimit): int
    {
        $hardCap = 8 * 1024 * 1024 * 1024;
        if ($serverLimit <= 0) {
            return $hardCap;
        }
        return min($hardCap, $serverLimit);
    }

    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        switch ($unit) {
            case 'g': $number *= 1024 * 1024 * 1024; break;
            case 'm': $number *= 1024 * 1024; break;
            case 'k': $number *= 1024; break;
        }
        return (int) $number;
    }

    private function formatLimit(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1, '.', ''), '0'), '.') . 'G';
        }
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.') . 'M';
        }
        return max(1, (int) round($bytes / 1024)) . 'K';
    }

    private function providedToken(): string
    {
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (stripos($authorization, 'Bearer ') === 0) {
            return trim(substr($authorization, 7));
        }
        $header = trim((string) ($_SERVER['HTTP_X_SSPKS_TOKEN'] ?? ''));
        if ($header !== '') {
            return $header;
        }
        // Browsers send the token as multipart text field when JS is unavailable.
        return isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '';
    }

    private function rateFile(): string
    {
        $directory = $this->config->basePath . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the upload rate-limit directory');
        }
        return $directory . DIRECTORY_SEPARATOR . 'upload-auth-rate.json';
    }

    private function clientKey(): string
    {
        return hash('sha256', trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')));
    }

    /** @return int attempts inside the window */
    private function isRateLimited(): bool
    {
        try {
            $handle = @fopen($this->rateFile(), 'c+');
            if ($handle === false || !flock($handle, LOCK_EX)) {
                if (is_resource($handle)) { fclose($handle); }
                return false;
            }
            try {
                rewind($handle);
                $raw = stream_get_contents($handle, 512 * 1024 + 1);
                $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
                if (!is_array($data)) { $data = []; }
                $key = $this->clientKey();
                $oldest = time() - self::AUTH_FAILURE_WINDOW;
                $attempts = array_values(array_filter($data[$key] ?? [], static function ($ts) use ($oldest): bool {
                    return is_int($ts) && $ts > $oldest;
                }));
                return count($attempts) >= self::AUTH_FAILURE_LIMIT;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Upload rate limiter unavailable: ' . $e->getMessage());
            return false;
        }
    }

    private function recordAuthFailure(): void
    {
        $this->writeRate(static function (array $attempts): array {
            $attempts[] = time();
            return $attempts;
        });
    }

    private function clearAuthFailures(): void
    {
        $this->writeRate(static fn (): array => []);
    }

    private function writeRate(callable $mutate): void
    {
        try {
            $handle = @fopen($this->rateFile(), 'c+');
            if ($handle === false || !flock($handle, LOCK_EX)) {
                if (is_resource($handle)) { fclose($handle); }
                return;
            }
            try {
                rewind($handle);
                $raw = stream_get_contents($handle, 512 * 1024 + 1);
                $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
                if (!is_array($data)) { $data = []; }
                $key = $this->clientKey();
                $oldest = time() - self::AUTH_FAILURE_WINDOW;
                $attempts = array_values(array_filter($data[$key] ?? [], static function ($ts) use ($oldest): bool {
                    return is_int($ts) && $ts > $oldest;
                }));
                $attempts = $mutate($attempts);
                if ($attempts === []) {
                    unset($data[$key]);
                } else {
                    $data[$key] = $attempts;
                }
                if (count($data) > 1024) {
                    $data = array_slice($data, -1024, null, true);
                }
                $encoded = json_encode($data);
                if (is_string($encoded)) {
                    rewind($handle);
                    ftruncate($handle, 0);
                    fwrite($handle, $encoded);
                    fflush($handle);
                    @chmod($this->rateFile(), 0600);
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Upload rate limiter write failed: ' . $e->getMessage());
        }
    }

    private function emit(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        echo $json === false ? '{"ok":false}' : $json;
    }
}
