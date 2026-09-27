<?php

declare(strict_types=1);

namespace app\components;

/**
 * Tiny cookie-keeping curl client used by `php yii verify/*` to drive the running app
 * the way a browser would (session cookie + CSRF token). Test-only.
 */
class TestHttpClient
{
    private string $cookieJar;
    private string $csrf = '';

    public function __construct(private readonly string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'erpcj');
    }

    public function __destruct()
    {
        @unlink($this->cookieJar);
    }

    /** @return array{code:int, body:string, location:string} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** @return array{code:int, body:string, location:string} */
    public function post(string $path, array $fields, bool $withCsrf = true): array
    {
        if ($withCsrf) {
            $this->ensureCsrf();
            $fields['_csrf'] = $this->csrf;
        }
        return $this->request('POST', $path, http_build_query($fields));
    }

    /** POST a JSON body with the CSRF header, as the chat UI does. */
    public function postJson(string $path, array $data): array
    {
        $this->ensureCsrf();
        return $this->request('POST', $path, json_encode($data), [
            'Content-Type: application/json',
            'X-CSRF-Token: ' . $this->csrf,
            'Accept: application/json',
        ]);
    }

    /** Loads a page (following redirects) to pick up the current CSRF meta token. */
    private function ensureCsrf(): void
    {
        $path = '/index.php?r=site/index';
        for ($hops = 0; $this->csrf === '' && $hops < 3; $hops++) {
            $r = $this->request('GET', $path);
            if ($r['code'] !== 302) {
                break;
            }
            $path = (string) preg_replace('#^https?://[^/]+#', '', $r['location']);
        }
    }

    private function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $location = '';
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code === 302) {
            // Login/switch-user rotate the CSRF token; fetch a fresh one before the next POST.
            $this->csrf = "";
        }

        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $out, $m)) {
            $this->csrf = html_entity_decode($m[1]);
        }
        return ['code' => $code, 'body' => $out, 'location' => $location];
    }
}
