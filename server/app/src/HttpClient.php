<?php
declare(strict_types=1);

namespace SignalLab;

final class HttpClient
{
    /** @return array{status:int, body:string} */
    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /** @return array{status:int, body:string} */
    public static function post(string $url, string $body, array $headers = [], int $timeout = 20): array
    {
        return self::request('POST', $url, $body, $headers, $timeout);
    }

    private static function request(string $method, string $url, ?string $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Stock Trading Signals/1.0)',
            CURLOPT_ENCODING => '',
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) return ['status' => 0, 'body' => $err];
        return ['status' => $status, 'body' => (string) $out];
    }
}
