<?php
/**
 * Contains the OAuth_Helpers class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Admin;

/**
 * Contains all the OAuth redirect handling functions, called by \Sgdg\Admin\AdminPages\action_handler()
 *
 * @see \Sgdg\Admin\AdminPages\action_handler()
 */
class OAuth_Helpers {
	/**
	 * Redirects to the OAuth granting URL
	 *
	 * @return void
	 */
	public static function grant_redirect() {
		if ( ! is_admin() ) {
			return;
		}

		$client   = \Sgdg\API_Client::get_raw_client();
		$auth_url = $client->createAuthUrl();
		header( 'Location: ' . esc_url_raw( $auth_url ) );
	}

	/**
	 * Handles the redirect back from Google app permission granting and redirects back to basic settings
	 *
	 * @return void
	 */
	public static function grant_return() {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['code'] ) ) {
			add_settings_error( 'general', 'oauth_failed', esc_html__( 'Google API hasn\'t returned an authentication code. Please try again.', 'skaut-google-drive-gallery' ), 'error' );
		}
		if ( count( get_settings_errors() ) === 0 && false === get_option( 'sgdg_access_token', false ) ) {
			$client = \Sgdg\API_Client::get_raw_client();
			try {
				$client->fetchAccessTokenWithAuthCode( \Sgdg\GET_Helpers::get_string_variable( 'code' ) );
				$access_token = $client->getAccessToken();

				$drive_client = new \Sgdg\Vendor\Google\Service\Drive( $client );
				$drive_client->drives->listDrives(
					array(
						'pageSize' => 1,
						'fields'   => 'drives(id)',
					)
				);
				update_option( 'sgdg_access_token', $access_token );
			} catch ( \Sgdg\Vendor\Google\Service\Exception $e ) {
				if ( 'accessNotConfigured' === $e->getErrors()[0]['reason'] ) {
					/* translators: %s: Link to the Google developers console */
					add_settings_error( 'general', 'oauth_failed', sprintf( esc_html__( 'Google Drive API is not enabled. Please enable it at %s and try again after a while.', 'skaut-google-drive-gallery' ), '<a href="https://console.developers.google.com/apis/library/drive.googleapis.com" target="_blank">https://console.developers.google.com/apis/library/drive.googleapis.com</a>' ), 'error' );
				} else {
					add_settings_error( 'general', 'oauth_failed', esc_html__( 'An unknown error has been encountered:', 'skaut-google-drive-gallery' ) . ' ' . $e->getErrors()[0]['message'], 'error' );
				}
			} catch ( \Sgdg\Vendor\GuzzleHttp\Exception\TransferException $e ) {
				add_settings_error( 'general', 'oauth_failed', esc_html__( 'An unknown error has been encountered:', 'skaut-google-drive-gallery' ) . ' ' . $e->getMessage(), 'error' );
			}
		}
		if ( count( get_settings_errors() ) === 0 ) {
			add_settings_error( 'general', 'oauth_updated', esc_html__( 'Permission granted.', 'skaut-google-drive-gallery' ), 'updated' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		header( 'Location: ' . esc_url_raw( admin_url( 'admin.php?page=sgdg_basic&settings-updated=true' ) ) );
	}

	/**
	 * Revokes and deletes the OAuth token and redirects back to basic settings
	 *
	 * @return void
	 */
	public static function revoke() {
		if ( ! is_admin() ) {
			return;
		}

		$client = \Sgdg\API_Client::get_raw_client();
		try {
			$client->revokeToken();
			delete_option( 'sgdg_access_token' );
		} catch ( \Sgdg\Vendor\GuzzleHttp\Exception\TransferException $e ) {
			add_settings_error( 'general', 'oauth_failed', esc_html__( 'An unknown error has been encountered:', 'skaut-google-drive-gallery' ) . ' ' . $e->getMessage(), 'error' );
		}
		if ( count( get_settings_errors() ) === 0 ) {
			add_settings_error( 'general', 'oauth_updated', __( 'Permission revoked.', 'skaut-google-drive-gallery' ), 'updated' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		header( 'Location: ' . esc_url_raw( admin_url( 'admin.php?page=sgdg_basic&settings-updated=true' ) ) );
	}
}
