<?php
/**
 * Contains the TinyMCE_Plugin class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Admin;

/**
 * Adds a gallery button to the TinyMCE editor.
 *
 * @phan-constructor-used-for-side-effects
 */
class TinyMCE_Plugin {
	/**
	 * Registers all the hooks for the TinyMCE plugin and the "list_gallery_dir" AJAX endpoint
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'media_buttons', array( self::class, 'add' ) );
		add_action( 'wp_enqueue_media', array( self::class, 'register_scripts_styles' ) );
		add_action( 'wp_ajax_list_gallery_dir', array( self::class, 'handle_ajax' ) );
	}

	/**
	 * Adds the Google Drive gallery button to TinyMCE and enables the use of ThickBox
	 *
	 * @return void
	 */
	public static function add() {
		if ( ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) || 'true' !== get_user_option( 'rich_editing' ) ) {
			return;
		}
		echo( '<a href="#" id="sgdg-tinymce-button" class="button"><img class="sgdg-tinymce-button-icon" src="' . esc_attr( plugins_url( '/skaut-google-drive-gallery/admin/icon.png' ) ) . '">' . esc_html__( 'Google Drive gallery', 'skaut-google-drive-gallery' ) . '</a>' );
		add_thickbox();
	}

	/**
	 * Enqueues the scripts and styles used by the Tiny MCE plugin.
	 *
	 * @return void
	 */
	public static function register_scripts_styles() {
		if ( ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) || 'true' !== get_user_option( 'rich_editing' ) ) {
			return;
		}
		\Sgdg\Script_And_Style_Helpers::register_and_enqueue_style( 'sgdg_tinymce', 'admin/css/tinymce.min.css' );
		\Sgdg\Script_And_Style_Helpers::register_and_enqueue_script( 'sgdg_tinymce', 'admin/js/tinymce.min.js' );
		wp_localize_script(
			'sgdg_tinymce',
			'sgdgTinymceLocalize',
			array(
				'dialog_title'  => esc_html__( 'Google Drive gallery', 'skaut-google-drive-gallery' ),
				'root_name'     => esc_html__( 'Google Drive gallery', 'skaut-google-drive-gallery' ),
				'insert_button' => esc_html__( 'Insert', 'skaut-google-drive-gallery' ),
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'sgdg_editor_plugin' ),
			)
		);
	}

	/**
	 * Handles errors for the "list_gallery_dir" AJAX endpoint.
	 *
	 * This function is a wrapper around `handle_ajax_body` that handles all the possible errors that can occur and sends them back as error messages.
	 *
	 * @return void
	 */
	public static function handle_ajax() {
		\Sgdg\Helpers::ajax_wrapper( array( self::class, 'ajax_handler_body' ) );
	}

	/**
	 * Actually handles the "list_gallery_dir" AJAX endpoint.
	 *
	 * Returns a list of all directories inside the last directory of a path.
	 *
	 * @return void
	 *
	 * @throws \Sgdg\Exceptions\Cant_Edit_Exception Insufficient role.
	 * @throws \Sgdg\Exceptions\Plugin_Not_Authorized_Exception Plugin not authorized.
	 */
	public static function ajax_handler_body() {
		check_ajax_referer( 'sgdg_editor_plugin' );
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
			throw new \Sgdg\Exceptions\Cant_Edit_Exception();
		}
		if ( false === get_option( 'sgdg_access_token', false ) ) {
			throw new \Sgdg\Exceptions\Plugin_Not_Authorized_Exception();
		}

		$path      = \Sgdg\GET_Helpers::get_array_variable( 'path' );
		$root_path = \Sgdg\Options::$root_path->get();
		$root      = end( $root_path );

		$directory_promise = self::list_directories_in_path( $path, $root );
		wp_send_json( \Sgdg\API_Client::execute( array( 'directories' => $directory_promise ) ) );
	}

	/**
	 * Returns a list of all directories inside the last directory of a path
	 *
	 * @param array<string> $path A path represented as an array of directory names.
	 * @param string        $root The root directory relative to which the path is taken.
	 *
	 * @return \Sgdg\Vendor\GuzzleHttp\Promise\PromiseInterface A list of directory names.
	 */
	private static function list_directories_in_path( array $path, $root ) {
		if ( 0 === count( $path ) ) {
			return \Sgdg\API_Facade::list_directories( $root, new \Sgdg\Frontend\API_Fields( array( 'name' ) ) )->then(
				static function( $directories ) {
					return array_column( $directories, 'name' );
				}
			);
		}
		return \Sgdg\API_Facade::get_directory_id( $root, $path[0] )->then(
			static function( $next_dir_id ) use ( $path ) {
				array_shift( $path );
				return self::list_directories_in_path( $path, $next_dir_id );
			}
		);
	}
}
