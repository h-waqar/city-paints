<?php

namespace CityPaintsERP\Api;

use CityPaintsERP\Helpers\Logger;
use WP_Error;

class ApiClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private string $apiKey;
    private AuthManager $auth;

    public function __construct(string $baseUrl, string $username, string $password, string $apiKey)
    {
        $this->baseUrl  = rtrim($baseUrl, '/') . '/';
        $this->username = $username;
        $this->password = $password;
        $this->apiKey   = $apiKey;
        $this->auth     = new AuthManager();
    }

    public function get(string $endpoint, array $args = []): array|WP_Error
    {
        return $this->request('GET', $endpoint, $args);
    }

    public function post(string $endpoint, array $body = []): array|WP_Error
    {
        return $this->request('POST', $endpoint, [], $body);
    }

    private function request(string $method, string $endpoint, array $params = [], array $body = []): array|WP_Error
    {
        $token = $this->auth->getToken();

        if (!$token) {
            $token = $this->refreshToken();
            if (is_wp_error($token)) {
                return $token;
            }
        }

        $url = $this->baseUrl . ltrim($endpoint, '/');
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        $response = wp_remote_request($url, [
            'method'  => $method,
            'headers' => [
                'Authorization' => "Bearer $token",
                'X-API-Key'     => $this->apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => !empty($body) ? wp_json_encode($body) : null,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            $this->auth->clearToken();
            return new WP_Error('unauthorized', 'Invalid or expired token');
        }

        return is_array($data) ? $data : [];
    }

    private function refreshToken(): string|WP_Error
    {
        $url = $this->baseUrl . 'auth/token'; // adjust if endpoint differs

        $response = wp_remote_post($url, [
            'headers' => [
                'X-API-Key'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'username' => $this->username,
                'password' => $this->password,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['accessToken'])) {
            return new WP_Error('auth_failed', 'Failed to get token', ['response' => $body]);
        }

        $token     = $body['accessToken'];
        $expiresIn = $body['expiresIn'] ?? 3600;

        $this->auth->saveToken($token, (int) $expiresIn);
        return $token;
    }
}
