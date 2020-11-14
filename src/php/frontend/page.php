<?php
/**
 * Contains all the functions used to handle the "page" AJAX endpoint.
 *
 * The "page" AJAX enpoint gets called each time the user needs to fetch items for a gallery.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Frontend\Page;

/**
 * Registers the "page" AJAX endpoint
 */
function register() {
	add_action( 'wp_ajax_page', '\\Sgdg\\Frontend\\Page\\handle_ajax' );
	add_action( 'wp_ajax_nopriv_page', '\\Sgdg\\Frontend\\Page\\handle_ajax' );
}

/**
 * Handles errors for the "page" AJAX endpoint.
 *
 * This function is a wrapper around `handle_ajax_body` that handles all the possible errors that can occur and sends them back as error messages.
 */
function handle_ajax() {
	try {
		ajax_handler_body();
	} catch ( \Sgdg\Vendor\Google_Service_Exception $e ) {
		if ( 'userRateLimitExceeded' === $e->getErrors()[0]['reason'] || 'rateLimitExceeded' === $e->getErrors()[0]['reason'] ) {
			wp_send_json( array( 'error' => esc_html__( 'The maximum number of requests has been exceeded. Please try again in a minute.', 'skaut-google-drive-gallery' ) ) );
		} else {
			wp_send_json( array( 'error' => $e->getErrors()[0]['message'] ) );
		}
	} catch ( \Exception $e ) {
		wp_send_json( array( 'error' => $e->getMessage() ) );
	}
}

/**
 * Actually handles the "gallery" AJAX endpoint.
 *
 * Returns a list of directories and a list of images.
 *
 * @see get_page()
 */
function ajax_handler_body() {
	get_context()->then(
		static function( $context ) {
			list( $client, $dir, $options ) = $context;
			$pagination_helper              = new \Sgdg\Frontend\Pagination_Helper( $options, false );

			wp_send_json( get_page( $client, $dir, $pagination_helper, $options ) );
		}
	);
	\Sgdg\API_Client::execute();
}

/**
 * Returns common variables used by different parts of the codebase
 *
 * @throws \Exception The gallery has expired.
 *
 * @return \Sgdg\Vendor\GuzzleHttp\Promise\PromiseInterface A promise resolving to an array of the form {
 *     @type \Sgdg\Vendor\Google_Drive_service A Google Drive API client.
 *     @type string The root directory of the gallery.
 *     @type \Sgdg\Frontend\Options_Proxy The configuration of the gallery.
 * }
 */
function get_context() {
	$client = \Sgdg\API_Client::get_drive_client();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['hash'] ) ) {
		throw new \Exception( esc_html__( 'The gallery has expired.', 'skaut-google-drive-gallery' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$transient = get_transient( 'sgdg_hash_' . sanitize_text_field( wp_unslash( $_GET['hash'] ) ) );

	if ( false === $transient ) {
		throw new \Exception( esc_html__( 'The gallery has expired.', 'skaut-google-drive-gallery' ) );
	}

	$path    = array( $transient['root'] );
	$options = new \Sgdg\Frontend\Options_Proxy( $transient['overriden'] );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['path'] ) && '' !== $_GET['path'] ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$path = array_merge( $path, explode( '/', sanitize_text_field( wp_unslash( $_GET['path'] ) ) ) );
	}

	return verify_path( $path )->then(
		static function() use ( $client, $path, $options ) {
			return array( $client, end( $path ), $options );
		}
	);
}

/**
 * Checks that a path is a valid path on Google Drive.
 *
 * @param array $path A list of directory IDs.
 *
 * @return \Sgdg\Vendor\GuzzleHttp\Promise\PromiseInterface A promise that resolves if the path is valid
 */
function verify_path( array $path ) {
	if ( count( $path ) === 1 ) {
		return new \Sgdg\Vendor\GuzzleHttp\Promise\FulfilledPromise( null );
	}
	return \Sgdg\API_Client::check_directory_in_directory( $path[1], $path[0] )->then(
		static function() use ( $path ) {
			array_shift( $path );
			return verify_path( $path );
		},
		static function( $exception ) {
			if ( $exception instanceof \Sgdg\Exceptions\File_Not_Found_Exception ) {
				return new \Sgdg\Vendor\GuzzleHttp\Promise\RejectedPromise( new \Sgdg\Exceptions\Path_Not_Found_Exception() );
			}
			return new \Sgdg\Vendor\GuzzleHttp\Promise\RejectedPromise( $exception );
		}
	);
}

/**
 * Return one page worth of items
 *
 * Lists one page of items - first directories and then images, up until the number of items per page is reached.
 *
 * @param \Sgdg\Vendor\Google_Service_Drive $client A Google Drive API client.
 * @param string                            $dir A directory to list items of.
 * @param \Sgdg\Frontend\Pagination_Helper  $pagination_helper An initialized pagination helper.
 * @param \Sgdg\Frontend\Options_Proxy      $options The configuration of the gallery.
 */
function get_page( $client, $dir, $pagination_helper, $options ) {
	$ret = array();
	if ( $pagination_helper->should_continue() ) {
		$ret['directories'] = directories( $client, $dir, $pagination_helper, $options );
	}
	if ( $pagination_helper->should_continue() ) {
		$ret['images'] = images( $client, $dir, $pagination_helper, $options );
	}
	if ( $pagination_helper->should_continue() ) {
		$ret['videos'] = videos( $client, $dir, $pagination_helper, $options );
	}
	$ret['more'] = $pagination_helper->has_more();
	return $ret;
}

/**
 * Returns a list of subdirectories in a directory.
 *
 * @param \Sgdg\Vendor\Google_Service_Drive $client A Google Drive API client.
 * @param string                            $dir A directory to list items of.
 * @param \Sgdg\Frontend\Pagination_Helper  $pagination_helper An initialized pagination helper.
 * @param \Sgdg\Frontend\Options_Proxy      $options The configuration of the gallery.
 *
 * @throws \Sgdg\Vendor\Google_Service_Exception A Google Drive API exception.
 *
 * @return array A list of directories in the format `['id' =>, 'id', 'name' => 'name', 'thumbnail' => 'thumbnail', 'dircount' => 1, 'imagecount' => 1]`.
 */
function directories( $client, $dir, $pagination_helper, $options ) {
	$page_token = null;
	do {
		$params   = array(
			'q'                         => '"' . $dir . '" in parents and (mimeType = "application/vnd.google-apps.folder" or (mimeType = "application/vnd.google-apps.shortcut" and shortcutDetails.targetMimeType = "application/vnd.google-apps.folder")) and trashed = false',
			'supportsAllDrives'         => true,
			'includeItemsFromAllDrives' => true,
			'orderBy'                   => $options->get( 'dir_ordering' ),
			'pageToken'                 => $page_token,
			'pageSize'                  => $pagination_helper->next_list_size( 1000 ),
			'fields'                    => 'nextPageToken, files(id, name, mimeType, shortcutDetails(targetId))',
		);
		$response = $client->files->listFiles( $params );
		if ( $response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $response;
		}
		list( $ids, $names ) = dir_ids_names( $response->getFiles(), $pagination_helper, $options );
		$page_token          = $response->getNextPageToken();
	} while ( null !== $page_token && $pagination_helper->should_continue() );

	$client->getClient()->setUseBatch( true );
	$batch = $client->createBatch();
	dir_images_requests( $client, $batch, $ids, $options );
	dir_counts_requests( $client, $batch, $ids );
	$responses = $batch->execute();
	$client->getClient()->setUseBatch( false );

	$dir_images = dir_images_responses( $responses, $ids, $options );
	$dir_counts = dir_counts_responses( $responses, $ids );

	$ret   = array();
	$count = count( $ids );
	for ( $i = 0; $i < $count; $i++ ) {
		$val = array(
			'id'        => $ids[ $i ],
			'name'      => $names[ $i ],
			'thumbnail' => $dir_images[ $i ],
		);
		if ( 'true' === $options->get( 'dir_counts' ) ) {
			$val = array_merge( $val, $dir_counts[ $i ] );
		}
		if ( 0 < $dir_counts[ $i ]['dircount'] + $dir_counts[ $i ]['imagecount'] + $dir_counts[ $i ]['videocount'] ) {
			$ret[] = $val;
		}
	}
	return $ret;
}

/**
 * Converts a list of Google Drive files into a list of IDs and a list of names.
 *
 * @param \Sgdg\Vendor\Google_Collection   $files A list of \Sgdg\Vendor\Google_Service_Drive_DriveFile.
 * @param \Sgdg\Frontend\Pagination_Helper $pagination_helper An initialized pagination helper.
 * @param \Sgdg\Frontend\Options_Proxy     $options The configuration of the gallery.
 *
 * @return array {
 *     @type array A list of Google Drive directory IDs.
 *     @type array A list of Google Drive directory names.
 * }
 */
function dir_ids_names( $files, $pagination_helper, $options ) {
	$ids   = array();
	$names = array();
	$pagination_helper->iterate(
		$files,
		static function( $file ) use ( &$ids, &$names, &$options ) {
			$ids[] = $file->getMimeType() === 'application/vnd.google-apps.shortcut' ? $file->getShortcutDetails()->getTargetId() : $file->getId();
			$name  = $file->getName();
			if ( '' !== $options->get( 'dir_prefix' ) ) {
				$pos     = mb_strpos( $name, $options->get( 'dir_prefix' ) );
				$names[] = mb_substr( $name, false !== $pos ? $pos + 1 : 0 );
			} else {
				$names[] = $name;
			}
		}
	);
	return array( $ids, $names );
}

/**
 * Creates API requests for directory thumbnails
 *
 * Takes a batch and adds to it a request for the first image in each directory.
 *
 * @param \Sgdg\Vendor\Google_Service_Drive $client A Google Drive API client.
 * @param \Sgdg\Vendor\Google_Http_Batch    $batch A Google Drive request batch.
 * @param array                             $dirs A list of directory IDs.
 * @param \Sgdg\Frontend\Options_Proxy      $options The configuration of the gallery.
 */
function dir_images_requests( $client, $batch, $dirs, $options ) {
	$params = array(
		'supportsAllDrives'         => true,
		'includeItemsFromAllDrives' => true,
		'orderBy'                   => $options->get( 'image_ordering' ),
		'pageSize'                  => 1,
		'fields'                    => 'files(imageMediaMetadata(width, height), thumbnailLink)',
	);

	foreach ( $dirs as $dir ) {
		$params['q'] = '"' . $dir . '" in parents and mimeType contains "image/" and trashed = false';
		$request     = $client->files->listFiles( $params );
		// @phan-suppress-next-line PhanTypeMismatchArgument
		$batch->add( $request, 'img-' . $dir );
	}
}

/**
 * Creates API requests for directory item counts
 *
 * Takes a batch and adds to it requests for the counts of subdirectories and images for each directory.
 *
 * @param \Sgdg\Vendor\Google_Service_Drive $client A Google Drive API client.
 * @param \Sgdg\Vendor\Google_Http_Batch    $batch A Google Drive request batch.
 * @param array                             $dirs A list of directory IDs.
 */
function dir_counts_requests( $client, $batch, $dirs ) {
	$params = array(
		'supportsAllDrives'         => true,
		'includeItemsFromAllDrives' => true,
		'pageSize'                  => 1000,
		'fields'                    => 'files(id)',
	);

	foreach ( $dirs as $dir ) {
		$params['q'] = '"' . $dir . '" in parents and (mimeType = "application/vnd.google-apps.folder" or (mimeType = "application/vnd.google-apps.shortcut" and shortcutDetails.targetMimeType = "application/vnd.google-apps.folder")) and trashed = false';
		$request     = $client->files->listFiles( $params );
		// @phan-suppress-next-line PhanTypeMismatchArgument
		$batch->add( $request, 'dircount-' . $dir );
		$params['q'] = '"' . $dir . '" in parents and mimeType contains "image/" and trashed = false';
		$request     = $client->files->listFiles( $params );
		// @phan-suppress-next-line PhanTypeMismatchArgument
		$batch->add( $request, 'imgcount-' . $dir );
		$params['q'] = '"' . $dir . '" in parents and mimeType contains "video/" and trashed = false';
		$request     = $client->files->listFiles( $params );
		// @phan-suppress-next-line PhanTypeMismatchArgument
		$batch->add( $request, 'vidcount-' . $dir );
	}
}

/**
 * Processes responses for directory thumbnails
 *
 * @param array                        $responses A list of \Sgdg\Vendor\GuzzleHttp\Psr7\Response.
 * @param array                        $dirs A list of directory IDs.
 * @param \Sgdg\Frontend\Options_Proxy $options The configuration of the gallery.
 *
 * @throws \Sgdg\Vendor\Google_Service_Exception A Google Drive API exception.
 *
 * @return array An array of string|bool containing either `false` if there is no thumbnail available or a link if ther is.
 */
function dir_images_responses( $responses, $dirs, $options ) {
	$ret = array();
	foreach ( $dirs as $dir ) {
		$response = $responses[ 'response-img-' . $dir ];
		if ( $response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $response;
		}
		$images = $response->getFiles();
		if ( count( $images ) === 0 ) {
			$ret[] = false;
		} else {
			$ret[] = substr( $images[0]->getThumbnailLink(), 0, -4 ) . ( $images[0]->getImageMediaMetadata()->getWidth() > $images[0]->getImageMediaMetadata()->getHeight() ? 'h' : 'w' ) . floor( 1.25 * $options->get( 'grid_height' ) );
		}
	}
	return $ret;
}

/**
 * Processes responses for directory item counts
 *
 * @param array $responses A list of \Sgdg\Vendor\GuzzleHttp\Psr7\Response.
 * @param array $dirs A list of directory IDs.
 *
 * @throws \Sgdg\Vendor\Google_Service_Exception A Google Drive API exception.
 *
 * @return array A list of subdirectory and image counts of format `['dircount' => 1, 'imagecount' => 1]` for each directory.
 */
function dir_counts_responses( $responses, $dirs ) {
	$ret = array();
	foreach ( $dirs as $dir ) {
		$dir_response = $responses[ 'response-dircount-' . $dir ];
		$img_response = $responses[ 'response-imgcount-' . $dir ];
		$vid_response = $responses[ 'response-vidcount-' . $dir ];
		if ( $dir_response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $dir_response;
		}
		if ( $img_response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $img_response;
		}
		if ( $vid_response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $vid_response;
		}
		$ret[] = array(
			'dircount'   => count( $dir_response->getFiles() ),
			'imagecount' => count( $img_response->getFiles() ),
			'videocount' => count( $vid_response->getFiles() ),
		);
	}
	return $ret;
}

/**
 * Returns a list of images in a directory
 *
 * @param \Sgdg\Vendor\Google_Service_Drive $client A Google Drive API client.
 * @param string                            $dir A directory to list items of.
 * @param \Sgdg\Frontend\Pagination_Helper  $pagination_helper An initialized pagination helper.
 * @param \Sgdg\Frontend\Options_Proxy      $options The configuration of the gallery.
 *
 * @throws \Sgdg\Vendor\Google_Service_Exception A Google Drive API exception.
 *
 * @return array A list of images in the format `['id' =>, 'id', 'description' => 'description', 'image' => 'image', 'thumbnail' => 'thumbnail']`.
 */
function images( $client, $dir, $pagination_helper, $options ) {
	$ret        = array();
	$page_token = null;
	do {
		$params = array(
			'q'                         => '"' . $dir . '" in parents and mimeType contains "image/" and trashed = false',
			'supportsAllDrives'         => true,
			'includeItemsFromAllDrives' => true,
			'pageToken'                 => $page_token,
			'pageSize'                  => $pagination_helper->next_list_size( 1000 ),
		);
		if ( $options->get_by( 'image_ordering' ) === 'time' ) {
			$params['fields'] = 'nextPageToken, files(id, thumbnailLink, createdTime, imageMediaMetadata(time), description)';
		} else {
			$params['orderBy'] = $options->get( 'image_ordering' );
			$params['fields']  = 'nextPageToken, files(id, thumbnailLink, description)';
		}
		$response = $client->files->listFiles( $params );
		if ( $response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $response;
		}
		$pagination_helper->iterate(
			$response->getFiles(),
			static function( $file ) use ( &$ret, &$options ) {
				$ret[] = image_preprocess( $file, $options );
			}
		);
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token && $pagination_helper->should_continue() );
	return images_order( $ret, $options );
}

/**
 * Processes an image response.
 *
 * @param \Sgdg\Vendor\Google_Service_Drive_DriveFile $file A Google Drive file response.
 * @param \Sgdg\Frontend\Options_Proxy                $options The configuration of the gallery.
 *
 * @return array {
 *     @type string    $id The ID of the image.
 *     @type string    $description The description (caption) of the image.
 *     @type string    $image A URL of the image to be displayed in the lightbox
 *     @type string    $thumbnail A URL of a thumbnail to be displayed in the image grid.
 *     @type \DateTime $timestamp A timestamp to order the images by. Optional.
 * }
 */
function image_preprocess( $file, $options ) {
	$description = $file->getDescription();
	$ret         = array(
		'id'          => $file->getId(),
		'description' => ( isset( $description ) ? esc_attr( $description ) : '' ),
		'image'       => substr( $file->getThumbnailLink(), 0, -3 ) . $options->get( 'preview_size' ),
		'thumbnail'   => substr( $file->getThumbnailLink(), 0, -4 ) . 'h' . floor( 1.25 * $options->get( 'grid_height' ) ),
	);
	if ( $options->get_by( 'image_ordering' ) === 'time' ) {
		if ( null !== $file->getImageMediaMetadata() && null !== $file->getImageMediaMetadata()->getTime() ) {
			$timestamp = \DateTime::createFromFormat( 'Y:m:d H:i:s', $file->getImageMediaMetadata()->getTime() );
		} else {
			$timestamp = \DateTime::createFromFormat( 'Y-m-d\TH:i:s.uP', $file->getCreatedTime() );
		}
		if ( false !== $timestamp ) {
			$ret['timestamp'] = $timestamp->format( 'U' );
		}
	}
	return $ret;
}

/**
 * Orders images.
 *
 * @param array                        $images A list of images in the format `['id' =>, 'id', 'description' => 'description', 'image' => 'image', 'thumbnail' => 'thumbnail', 'timestamp' => new \DateTime()]`.
 * @param \Sgdg\Frontend\Options_Proxy $options The configuration of the gallery.
 *
 * @return array An ordered list of images in the format `['id' =>, 'id', 'description' => 'description', 'image' => 'image', 'thumbnail' => 'thumbnail']`.
 */
function images_order( $images, $options ) {
	if ( $options->get_by( 'image_ordering' ) === 'time' ) {
		usort(
			$images,
			static function( $first, $second ) use ( $options ) {
				$asc = $first['timestamp'] - $second['timestamp'];
				return $options->get_order( 'image_ordering' ) === 'ascending' ? $asc : -$asc;
			}
		);
		array_walk(
			$images,
			static function( &$item ) {
				unset( $item['timestamp'] );
			}
		);
	}
	return $images;
}

/**
 * Returns a list of images in a directory
 *
 * @param \Sgdg\Vendor\Google_Service_Drive $client A Google Drive API client.
 * @param string                            $dir A directory to list items of.
 * @param \Sgdg\Frontend\Pagination_Helper  $pagination_helper An initialized pagination helper.
 * @param \Sgdg\Frontend\Options_Proxy      $options The configuration of the gallery.
 *
 * @throws \Sgdg\Vendor\Google_Service_Exception A Google Drive API exception.
 *
 * @return array A list of videos in the format `['id' =>, 'id', 'thumbnail' => 'thumbnail', 'mimeType' => 'mimeType', 'src' => 'src']`.
 */
function videos( $client, $dir, $pagination_helper, $options ) {
	$ret        = array();
	$page_token = null;
	do {
		$params   = array(
			'q'                         => '"' . $dir . '" in parents and mimeType contains "video/" and trashed = false',
			'supportsAllDrives'         => true,
			'includeItemsFromAllDrives' => true,
			'orderBy'                   => $options->get( 'image_ordering' ),
			'pageToken'                 => $page_token,
			'pageSize'                  => $pagination_helper->next_list_size( 1000 ),
			'fields'                    => 'nextPageToken, files(id, mimeType, webContentLink, thumbnailLink, videoMediaMetadata(width, height))',
		);
		$response = $client->files->listFiles( $params );
		if ( $response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $response;
		}
		$pagination_helper->iterate(
			$response->getFiles(),
			static function( $file ) use ( &$ret, &$options ) {
				$ret[] = video_preprocess( $file, $options );
			}
		);
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token && $pagination_helper->should_continue() );
	return $ret;
}

/**
 * Processes an image response.
 *
 * @param \Sgdg\Vendor\Google_Service_Drive_DriveFile $file A Google Drive file response.
 * @param \Sgdg\Frontend\Options_Proxy                $options The configuration of the gallery.
 *
 * @return array {
 *     @type string $id The ID of the image.
 *     @type string $thumbnail A URL of a thumbnail to be displayed in the image grid.
 *     @type string $mimeType The MIME type of the video file.
 *     @type int    $width The width of the video.
 *     @type int    $height The height of the video.
 *     @type src    $src The URL of the video file.
 * }
 */
function video_preprocess( $file, $options ) {
	$video_metadata = $file->getVideoMediaMetadata();
	$width          = is_null( $video_metadata ) ? '0' : $video_metadata->getWidth();
	$height         = is_null( $video_metadata ) ? '0' : $video_metadata->getHeight();
	return array(
		'id'        => $file->getId(),
		'thumbnail' => substr( $file->getThumbnailLink(), 0, -4 ) . 'h' . floor( 1.25 * $options->get( 'grid_height' ) ),
		'mimeType'  => $file->getMimeType(),
		'width'     => $width,
		'height'    => $height,
		'src'       => resolve_video_url( $file->getWebContentLink() ),
	);
}

/**
 * Resolves the correct URL for a video.
 *
 * Finds the correct URL so that a video would load in the browser.
 *
 * @param string $web_content_url The webContentLink returned by Google Drive API.
 *
 * @return string The resolved video URL.
 */
function resolve_video_url( $web_content_url ) {
	$http_client = new \Sgdg\Vendor\GuzzleHttp\Client();
	$url         = $web_content_url;
	$response    = $http_client->get( $url, array( 'allow_redirects' => false ) );

	if ( $response->hasHeader( 'Set-Cookie' ) && 0 === mb_strpos( $response->getHeader( 'Set-Cookie' )[0], 'download_warning' ) ) {
		// Handle virus scan warning.
		mb_ereg( '(download_warning[^=]*)=([^;]*).*Domain=([^;]*)', $response->getHeader( 'Set-Cookie' )[0], $regs );
		$name       = $regs[1];
		$confirm    = $regs[2];
		$domain     = $regs[3];
		$cookie_jar = \Sgdg\Vendor\GuzzleHttp\Cookie\CookieJar::fromArray( array( $name => $confirm ), $domain );

		$response = $http_client->head(
			$url . '&confirm=' . $confirm,
			array(
				'allow_redirects' => false,
				'cookies'         => $cookie_jar,
			)
		);
		$url      = $response->getHeader( 'Location' )[0];
	}
	return $url;
}
