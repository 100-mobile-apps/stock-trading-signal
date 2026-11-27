<?php
declare(strict_types=1);

namespace SignalLab;

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $codeName, string $message)
    {
        parent::__construct($message);
    }
}

/** Response returned by a route: JSON by default, or raw HTML. */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType = 'application/json; charset=utf-8',
        public array $headers = [],
    ) {}

    public static function redirect(string $url, int $status = 303): self
    {
        return new self($status, '', 'text/html; charset=utf-8', ['Location' => $url]);
    }

    public static function text(string $body, string $type = 'text/plain; charset=utf-8'): self
    {
        return new self(200, $body, $type);
    }

    /** Adds a header (Set-Cookie may repeat, so values are lists). */
    public function with(string $name, string $value): self
    {
        $this->headers[$name] = isset($this->headers[$name]) ? array_merge((array) $this->headers[$name], [$value]) : $value;
        return $this;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, 'text/html; charset=utf-8');
    }

    public static function error(int $status, string $code, string $message): self
    {
        return new self($status, json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]]));
    }
}

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly string $ip,
        public array $cookies = [],
    ) {
        if (!$this->cookies && isset($this->headers['cookie'])) {
            foreach (explode(';', (string) $this->headers['cookie']) as $pair) {
                $kv = explode('=', trim($pair), 2);
                if (count($kv) === 2) $this->cookies[$kv[0]] = urldecode($kv[1]);
            }
        }
    }

    public function withPath(string $path): self
    {
        return new self($this->method, $path, $this->query, $this->body, $this->headers, $this->ip, $this->cookies);
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public static function fromGlobals(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Strip the folder the API lives in (e.g. /api) so routes are always "/auth/login".
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
        $uri = '/' . trim(preg_replace('#/index\.php#', '', $uri), '/');

        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
        if (!$body && !empty($_POST)) $body = $_POST;

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
        // Some Apache/cPanel setups hide the Authorization header.
        if (!isset($headers['authorization'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? (function_exists('apache_request_headers')
                ? (apache_request_headers()['Authorization'] ?? null) : null);
            if ($auth) $headers['authorization'] = $auth;
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $uri,
            $_GET,
            $body,
            $headers,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        );
    }

    public function str(string $key, int $max = 500): string
    {
        $v = $this->body[$key] ?? $this->query[$key] ?? '';
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->body[$key] ?? $this->query[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bearer(): ?string
    {
        $h = $this->headers['authorization'] ?? '';
        return preg_match('/^Bearer\s+(.+)$/i', $h, $m) ? trim($m[1]) : null;
    }
}

final class Router
{
    /** @var array<int, array{string, string, callable}> */
    private array $routes = [];
    /** Optional fn(int $status, string $code, string $message, Request $req): Response, used by the website for HTML errors. */
    public $onError = null;

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [$method, $pattern, $handler];
    }

    public function dispatch(Request $req): Response
    {
        if ($req->method === 'OPTIONS') return new Response(204, '');
        $allowed = false;
        foreach ($this->routes as [$method, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $req->path, $m)) continue;
            $allowed = true;
            if ($method !== $req->method) continue;
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            try {
                return $handler($req, $params);
            } catch (ApiError $e) {
                return $this->fail($e->status, $e->codeName, $e->getMessage(), $req);
            } catch (\Throwable $e) {
                error_log('[SignalLab] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                return $this->fail(500, 'server_error', 'Something went wrong. Please try again.', $req);
            }
        }
        return $allowed
            ? $this->fail(405, 'method_not_allowed', 'Method not allowed.', $req)
            : $this->fail(404, 'not_found', 'Page not found.', $req);
    }

    private function fail(int $status, string $code, string $message, Request $req): Response
    {
        return $this->onError ? ($this->onError)($status, $code, $message, $req) : Response::error($status, $code, $message);
    }

    public static function send(Response $res): void
    {
        self::sendWith($res, true);
    }

    public static function sendWith(Response $res, bool $api): void
    {
        http_response_code($res->status);
        header('Content-Type: ' . $res->contentType);
        if ($api) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Cache-Control: no-store');
        } else {
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            if (!isset($res->headers['Cache-Control'])) header('Cache-Control: private, no-cache');
        }
        header('X-Content-Type-Options: nosniff');
        foreach ($res->headers as $name => $values) {
            foreach ((array) $values as $v) header("$name: $v", false);
        }
        echo $res->body;
    }
}
