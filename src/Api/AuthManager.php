<?php


namespace CityPaintsERP\Api;

class AuthManager {
	private string $option_token = 'citypaints_erp_token';
	private string $option_expiry = 'citypaints_erp_token_expiry';

	public function getToken(): ?string {
		$token  = get_option( $this->option_token );
		$expiry = (int) get_option( $this->option_expiry );

		if ( ! $token || time() >= $expiry ) {
			return null;
		}

		return $token;
	}

	public function saveToken( string $token, int $expiresIn ): void {
		update_option( $this->option_token, $token, false );
		update_option( $this->option_expiry, time() + $expiresIn - 60, false ); // 1 min buffer
	}

	public function getRefreshToken(): ?string {
		return get_option( 'citypaints_erp_refresh_token' ) ?: null;
	}

	public function saveRefreshToken( string $refreshToken ): void {
		update_option( 'citypaints_erp_refresh_token', $refreshToken, false );
	}

	public function clearAll(): void {
		$this->clearToken();
		$this->clearRefreshToken();
	}

	public function clearToken(): void {
		delete_option( $this->option_token );
		delete_option( $this->option_expiry );
	}

	public function clearRefreshToken(): void {
		delete_option( 'citypaints_erp_refresh_token' );
	}

}
