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
    ) {}

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
    ) {}

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
                return Response::error($e->status, $e->codeName, $e->getMessage());
            } catch (\Throwable $e) {
                error_log('[SignalLab] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                return Response::error(500, 'server_error', 'Something went wrong. Please try again.');
            }
        }
        return $allowed
            ? Response::error(405, 'method_not_allowed', 'Method not allowed.')
            : Response::error(404, 'not_found', 'Endpoint not found.');
    }

    public static function send(Response $res): void
    {
        http_response_code($res->status);
        header('Content-Type: ' . $res->contentType);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $res->body;
    }
}
