<?php
/**
 * Contains the Helpers class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg;

/**
 * Contains various helper functions.
 */
class Helpers {
	/**
	 * Checks whether debug info should be displayed
	 *
	 * @return bool True to display debug info.
	 */
	public static function is_debug_display() {
		if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_DISPLAY' ) ) {
			return \WP_DEBUG === true && \WP_DEBUG_DISPLAY === true;
		}
		return false;
	}

	/**
	 * Runs an AJAX handler and handles errors.
	 *
	 * @param callable $handler The actual handler.
	 *
	 * @return void
	 */
	public static function ajax_wrapper( $handler ) {
		try {
			$handler();
		} catch ( \Sgdg\Exceptions\Exception $e ) {
			wp_send_json( array( 'error' => $e->getMessage() ) );
		} catch ( \Exception $e ) {
			if ( self::is_debug_display() ) {
				wp_send_json( array( 'error' => $e->getMessage() ) );
			}
			wp_send_json( array( 'error' => esc_html__( 'Unknown error.', 'skaut-google-drive-gallery' ) ) );
		}
	}
}
