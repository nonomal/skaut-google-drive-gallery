<?php
/**
 * Contains the Directories class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Frontend\Page;

/**
 * Contains all the functions used to display directories in a gallery.
 */
final class Directories {

	/**
	 * Returns a list of subdirectories in a directory.
	 *
	 * @param string                           $parent_id A directory to list items of.
	 * @param \Sgdg\Frontend\Pagination_Helper $pagination_helper An initialized pagination helper.
	 * @param \Sgdg\Frontend\Options_Proxy     $options The configuration of the gallery.
	 *
	 * @return \Sgdg\Vendor\GuzzleHttp\Promise\PromiseInterface A promise resolving to a list of directories in the format `['id' =>, 'id', 'name' => 'name', 'thumbnail' => 'thumbnail', 'dircount' => 1, 'imagecount' => 1, 'videocount' => 1]`.
	 */
	public static function directories( $parent_id, $pagination_helper, $options ) {
		return ( \Sgdg\API_Facade::list_directories( $parent_id, new \Sgdg\Frontend\API_Fields( array( 'id', 'name' ) ), $pagination_helper, $options->get( 'dir_ordering' ) )->then(
			static function( $files ) use ( &$options ) {
				$files = array_map(
					static function( $file ) use ( &$options ) {
						if ( '' !== $options->get( 'dir_prefix' ) ) {
							$pos          = mb_strpos( $file['name'], $options->get( 'dir_prefix' ) );
							$file['name'] = mb_substr( $file['name'], false !== $pos ? $pos + 1 : 0 );
						}

						return $file;
					},
					$files
				);
				$ids   = array_column( $files, 'id' );

				return \Sgdg\Vendor\GuzzleHttp\Promise\Utils::all( array( $files, self::thumbnail_images( $ids, $options ), self::directory_item_counts( $ids ) ) );
			}
		)->then(
			static function( $list ) use ( &$options ) {
				list( $files, $images, $counts ) = $list;
				$count                           = count( $files );

				for ( $i = 0; $i < $count; $i++ ) {
					$files[ $i ]['thumbnail'] = $images[ $i ];

					if ( 'true' === $options->get( 'dir_counts' ) ) {
						$files[ $i ] = array_merge( $files[ $i ], $counts[ $i ] );
					}

					if ( 0 === $counts[ $i ]['dircount'] + $counts[ $i ]['imagecount'] + $counts[ $i ]['videocount'] ) {
						unset( $files[ $i ] );
					}
				}

				// Needed because of the unset not re-indexing.
				return array_values( $files );
			}
		) );
	}

	/**
	 * Creates API requests for directory thumbnails
	 *
	 * Takes a batch and adds to it a request for the first image in each directory.
	 *
	 * @param array<string>                $dirs A list of directory IDs.
	 * @param \Sgdg\Frontend\Options_Proxy $options The configuration of the gallery.
	 *
	 * @return \Sgdg\Vendor\GuzzleHttp\Promise\PromiseInterface A promise resolving to a list of directory images.
	 */
	private static function thumbnail_images( $dirs, $options ) {
		return \Sgdg\Vendor\GuzzleHttp\Promise\Utils::all(
			array_map(
				static function( $directory ) use ( &$options ) {
					return \Sgdg\API_Facade::list_images(
						$directory,
						new \Sgdg\Frontend\API_Fields(
							array(
								'imageMediaMetadata' => array( 'width', 'height' ),
								'thumbnailLink',
							)
						),
						( new \Sgdg\Frontend\Paging_Pagination_Helper() )->withValues( 0, 1 ),
						$options->get( 'image_ordering' )
					)->then(
						static function( $images ) use ( &$options ) {
							if ( count( $images ) === 0 ) {
								return false;
							}

							return substr( $images[0]['thumbnailLink'], 0, -4 ) . ( $images[0]['imageMediaMetadata']['width'] > $images[0]['imageMediaMetadata']['height'] ? 'h' : 'w' ) . floor( 1.25 * $options->get( 'grid_height' ) );
						}
					);
				},
				$dirs
			)
		);
	}

	/**
	 * Creates API requests for directory item counts
	 *
	 * Takes a batch and adds to it requests for the counts of subdirectories and images for each directory.
	 *
	 * @param array<string> $dirs A list of directory IDs.
	 *
	 * @return \Sgdg\Vendor\GuzzleHttp\Promise\PromiseInterface A promise resolving to a list of subdirectory, image and video counts of format `['dircount' => 1, 'imagecount' => 1, 'videocount' => 1]` for each directory.
	 */
	private static function directory_item_counts( $dirs ) {
		return \Sgdg\Vendor\GuzzleHttp\Promise\Utils::all(
			array_map(
				static function( $dir ) {
					return \Sgdg\Vendor\GuzzleHttp\Promise\Utils::all(
						array(
							\Sgdg\API_Facade::list_directories(
								$dir,
								new \Sgdg\Frontend\API_Fields( array( 'createdTime' ) ),
								new \Sgdg\Frontend\Single_Page_Pagination_Helper(),
								'name'
							),
							\Sgdg\API_Facade::list_images(
								$dir,
								new \Sgdg\Frontend\API_Fields( array( 'createdTime' ) ),
								new \Sgdg\Frontend\Single_Page_Pagination_Helper(),
								'name'
							),
							\Sgdg\API_Facade::list_videos(
								$dir,
								new \Sgdg\Frontend\API_Fields( array( 'createdTime' ) ),
								new \Sgdg\Frontend\Single_Page_Pagination_Helper(),
								'name'
							),
						)
					)->then(
						static function( $items ) {
							return array(
								'dircount'   => count( $items[0] ),
								'imagecount' => count( $items[1] ),
								'videocount' => count( $items[2] ),
							);
						}
					);
				},
				$dirs
			)
		);
	}

}
