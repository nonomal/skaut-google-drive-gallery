<?php
/**
 * Contains the Lightbox class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Admin\Settings_Pages\Advanced;

/**
 * Registers and renders the lightbox settings section.
 *
 * @phan-constructor-used-for-side-effects
 */
class Lightbox {
	/**
	 * Register all the hooks for this section.
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', array( self::class, 'add_section' ) );
	}

	/**
	 * Adds the settings section and all the fields in it.
	 *
	 * @return void
	 */
	public static function add_section() {
		add_settings_section( 'sgdg_lightbox', esc_html__( 'Image popup', 'skaut-google-drive-gallery' ), array( self::class, 'html' ), 'sgdg_advanced' );
		\Sgdg\Options::$preview_size->add_field();
		\Sgdg\Options::$preview_speed->add_field();
		\Sgdg\Options::$preview_arrows->add_field();
		\Sgdg\Options::$preview_close_button->add_field();
		\Sgdg\Options::$preview_loop->add_field();
		\Sgdg\Options::$preview_activity_indicator->add_field();
		\Sgdg\Options::$preview_captions->add_field();
	}

	/**
	 * Renders the header for the section.
	 *
	 * Currently no-op.
	 *
	 * @return void
	 */
	public static function html() {}
}
