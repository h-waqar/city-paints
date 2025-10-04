<?php
//
//namespace CityPaintsERP\Includes;
//
//class Enqueue {
//	private array $adminAssets = [];
//	private array $frontendAssets = [];
//
//	public function __construct() {
//		if ( is_admin() ) {
//			add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAdmin' ] );
//		} else {
//			add_action( 'wp_enqueue_scripts', [ $this, 'enqueueFrontend' ] );
//		}
//
//		// Register admin assets
//		$this->adminAssets = [
//			'citypaints-admin-sync' => [
//				'src'      => 'assets/admin/js/product-sync.js',
//				'deps'     => [ 'jquery' ],
//				'localize' => [
//					'CityPaintsSync' => [
//						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
//						'nonce'   => wp_create_nonce( 'citypaints_sync' )
//					]
//				],
//				'screens'  => [ 'edit-product' ],
//			],
//		];
//
//		// Register frontend assets
//		$this->frontendAssets = [
//			'citypaints-main-css' => [
//				'src'  => 'assets/public/css/main.css',
//				'deps' => [],
//				'ver'  => null,
//			],
//			'citypaints-main-js'  => [
//				'src'  => 'assets/public/js/main.js',
//				'deps' => [ 'jquery' ],
//				'ver'  => null,
//			],
//		];
//
//	}
//
//	public function enqueueAdmin(): void {
//		$screen = get_current_screen();
//
//		foreach ( $this->adminAssets as $handle => $asset ) {
//			if ( ! empty( $asset['screens'] ) && ( ! $screen || ! in_array( $screen->id, $asset['screens'], true ) ) ) {
//				continue;
//			}
//
//			$src = CITYPAINTS_PLUGIN_URL . $asset['src'];
//			$ver = file_exists( CITYPAINTS_PLUGIN_DIR . $asset['src'] ) ? filemtime( CITYPAINTS_PLUGIN_DIR . $asset['src'] ) : '1.0.0';
//
//			wp_enqueue_script( $handle, $src, $asset['deps'] ?? [], $ver, true );
//
//			if ( ! empty( $asset['localize'] ) ) {
//				foreach ( $asset['localize'] as $objectName => $data ) {
//					wp_localize_script( $handle, $objectName, $data );
//				}
//			}
//		}
//	}
//
//	public function enqueueFrontend(): void {
//		foreach ( $this->frontendAssets as $handle => $asset ) {
//			$src = CITYPAINTS_PLUGIN_URL . $asset['src'];
//			$ver = file_exists( CITYPAINTS_PLUGIN_DIR . $asset['src'] ) ? filemtime( CITYPAINTS_PLUGIN_DIR . $asset['src'] ) : '1.0.0';
//
//			wp_enqueue_script( $handle, $src, $asset['deps'] ?? [], $ver, true );
//		}
//	}
//}


namespace CityPaintsERP\Includes;

class Enqueue {
	private array $adminAssets = [];
	private array $frontendAssets = [];

	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAdmin' ] );
		} else {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueueFrontend' ] );
		}

		// Register admin assets
		$this->adminAssets = [
			'citypaints-admin-sync' => [
				'type'     => 'script',
				'src'      => 'assets/admin/js/product-sync.js',
				'deps'     => [ 'jquery' ],
				'localize' => [
					'CityPaintsSync' => [
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( 'citypaints_sync' ),
					],
				],
				'screens'  => [ 'edit-product' ],
			],
		];

		// Register frontend assets
		$this->frontendAssets = [
//			'citypaints-main-css' => [
//				'type' => 'style',
//				'src'  => 'assets/public/css/main.css',
//				'deps' => [],
//			],
//			'bootstrap-css'       => [
//				'type' => 'style',
//				'src'  => 'assets/public/css/bootstrap.min.css',
//				'deps' => [],
//			],
//			'citypaints-main-js'  => [
//				'type' => 'script',
//				'src'  => 'assets/public/js/main.js',
//				'deps' => [ 'jquery' ],
//			],
//			'bootstrap-js'        => [
//				'type' => 'script',
//				'src'  => 'assets/public/js/bootstrap.min.js',
//				'deps' => [ 'jquery' ],
//			],
		];
	}

	public function enqueueAdmin(): void {
		$screen = get_current_screen();

		foreach ( $this->adminAssets as $handle => $asset ) {
			if ( ! empty( $asset['screens'] ) && ( ! $screen || ! in_array( $screen->id, $asset['screens'], true ) ) ) {
				continue;
			}
			$this->enqueueAsset( $handle, $asset );
		}
	}

	private function enqueueAsset( string $handle, array $asset ): void {
		$src = CITYPAINTS_PLUGIN_URL . $asset['src'];
		$ver = file_exists( CITYPAINTS_PLUGIN_DIR . $asset['src'] )
			? filemtime( CITYPAINTS_PLUGIN_DIR . $asset['src'] )
			: '1.0.0';

		if ( $asset['type'] === 'style' ) {
			wp_enqueue_style( $handle, $src, $asset['deps'] ?? [], $ver );
		} else {
			wp_enqueue_script( $handle, $src, $asset['deps'] ?? [], $ver, true );

			// Handle localization if provided
			if ( ! empty( $asset['localize'] ) ) {
				foreach ( $asset['localize'] as $objectName => $data ) {
					wp_localize_script( $handle, $objectName, $data );
				}
			}
		}
	}

	public function enqueueFrontend(): void {
		foreach ( $this->frontendAssets as $handle => $asset ) {
			$this->enqueueAsset( $handle, $asset );
		}
	}
}
