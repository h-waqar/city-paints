<?php


namespace CityPaintsERP\Api;

use Exception;
use WP_Error;

class ApiClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private string $apiKey;
    private AuthManager $auth;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $options = get_option('citypaints_erp_settings', []);

        if (empty($options['username'] ?? '') || empty($options['password'] ?? '') || empty($options['base_url'] ?? '') || empty($options['api_key'] ?? '')) {
            throw new Exception('API credentials not set in settings.');
        }

        $this->baseUrl = rtrim($options['base_url'], '/') . '/';
        $this->username = $options['username'];
        $this->password = $options['password'];
        $this->apiKey = $options['api_key'];
        $this->auth = new AuthManager();
    }

    /**
     * GET request
     */
    public function get(string $endpoint, array $params = []): array|WP_Error
    {
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * Main request
     */

    private function request(string $method, string $endpoint, array $params = [], array $body = []): array|WP_Error
    {
//		global $CLOGGER;

        // Ensure endpoint URL
        $url = $this->baseUrl . ltrim($endpoint, '/');
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        // Ensure we have a token (will call refreshToken() if missing)
        $token = $this->auth->getToken();
//        $CLOGGER->log("accessToken = $token");
//        if (!$token) {
//            $token = $this->refreshToken();
//            if (is_wp_error($token)) {
//                return $token;
//            }
//        }


        $attempt = 0;
        $maxAttempts = 2; // initial + one retry after refresh
        $lastResponse = null;

        while ($attempt < $maxAttempts) {
            $args = [
                'method' => $method,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'x-api-key' => "$this->apiKey",
                    'Authorization' => "Bearer {$token}",
                ],
                'timeout' => 20,
                'sslverify' => false,
            ];

            if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && !empty($body)) {
                $args['body'] = wp_json_encode($body);
            }

            if (isset($CLOGGER)) {
                $CLOGGER->log("ApiClient Request + ARGS: {$method} {$url}", $args);
            }

            $response = wp_remote_request($url, $args);
            $lastResponse = $response;

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);
            $data = null;
            if ($raw !== '') {
                $data = json_decode($raw, true);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    // Invalid JSON
                    return new WP_Error('invalid_json', 'Invalid JSON from API', [
                        'http_code' => $code,
                        'raw_body' => $raw,
                    ]);
                }
            }

            // If unauthorized -> clear token and try refresh once
            if ($code === 401 && $attempt === 0) {
                if (isset($CLOGGER)) {
                    $CLOGGER->warn("ApiClient 401, attempting token refresh for {$endpoint}", [
                        'response_headers' => $response['headers'] ?? null,
                    ]);
                }
                // Clear cached token and try refresh
                $this->auth->clearToken();
                $token = $this->refreshToken();
                if (is_wp_error($token)) {
                    return $token;
                }
                $attempt++;
                // loop to retry with new token
                continue;
            }

            // Success (2xx)
            if ($code >= 200 && $code < 300) {
                return is_array($data) ? $data : [];
            }

            // Non-2xx: return a WP_Error with body + code
            return new WP_Error('api_http_error', "API returned HTTP {$code}", [
                'http_code' => $code,
                'body' => $data ?? $raw,
                'raw' => $raw,
            ]);
        }

        // fallback
        return new WP_Error('api_error', 'API request failed', ['response' => $lastResponse]);
    }


    /**
     * Refresh token
     */
    private function refreshToken(): string|WP_Error
    {
        $refreshToken = $this->auth->getRefreshToken();

        // Try refresh if we have a refresh token
        if ($refreshToken) {
            $existingToken = $this->auth->getToken() ?? '';
            $resp = wp_remote_post($this->baseUrl . 'authentication/refresh', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer $existingToken",
                ],
                'body' => wp_json_encode([
                    'AccessToken' => $existingToken,
                    'RefreshToken' => $refreshToken,
                ]),
                'timeout' => 15,
                'sslverify' => false,
            ]);

            if (!is_wp_error($resp)) {
                $code = wp_remote_retrieve_response_code($resp);
                $body = json_decode(wp_remote_retrieve_body($resp), true);
                if ($code === 200 && !empty($body['AccessToken'])) {
                    $this->auth->saveToken($body['AccessToken'], (int)($body['ExpiresIn'] ?? 3600));
                    if (!empty($body['RefreshToken'])) {
                        $this->auth->saveRefreshToken($body['RefreshToken']);
                    }

                    return $body['AccessToken'];
                }
            }

            // Failed refresh → clear both tokens
            $this->auth->clearAll();
        }

        // Fallback: login
        $resp = wp_remote_post($this->baseUrl . 'authentication/login', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode([
                'UserName' => $this->username,
                'Password' => $this->password,
            ]),
            'timeout' => 15,
            'sslverify' => false,
        ]);

        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200 || empty($body['AccessToken'])) {
            return new WP_Error('auth_failed', 'Failed to get token', ['response' => $body]);
        }

        $this->auth->saveToken($body['AccessToken'], (int)($body['ExpiresIn'] ?? 3600));
        if (!empty($body['RefreshToken'])) {
            $this->auth->saveRefreshToken($body['RefreshToken']);
        }

        return $body['AccessToken'];
    }


    /**
     * POST request
     */
    public function post(string $endpoint, array $body = []): array|WP_Error
    {
        return $this->request('POST', $endpoint, [], $body);
    }
}
