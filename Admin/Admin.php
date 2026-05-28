<?php
declare( strict_types=1 );

namespace BoltSync\Admin;

use BoltSync\Inc\Plugin;

/**
 * Admin Plugin Class
 */
final class Admin {

	public function __construct( protected Plugin $plugin ) {
		add_action( 'init', [ $this, 'init' ] );
	}

	public function init(): void {
	}
}
