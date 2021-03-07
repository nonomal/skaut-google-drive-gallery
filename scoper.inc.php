<?php

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix'                     => 'Sgdg\\Vendor',
	'finders'                    => array(
		Finder::create()->files()
			->notName( '/LICENSE|.*\\.md/' ) // TODO: Check.
			->in( 'dist/bundled/vendor' ),
	),
	'patchers'                   => array(
		function ( $file_path, $prefix, $contents ) {
			if ( __DIR__ . '/dist/bundled/vendor/symfony/polyfill-mbstring/Mbstring.php' === $file_path ) {
				return $contents; // TODO: Remove after nikic/PHP-Parser#763 is solved.
			}
			if ( in_array( $file_path, array( __DIR__ . '/dist/bundled/vendor/symfony/polyfill-intl-idn/bootstrap.php', __DIR__ . '/dist/bundled/vendor/symfony/polyfill-mbstring/bootstrap.php' ), true ) ) {
				$contents = mb_ereg_replace( 'namespace Sgdg\\\\Vendor;', '', $contents );
			}
			if ( __DIR__ . '/dist/bundled/vendor/guzzlehttp/guzzle/src/functions.php' === $file_path ) {
				$contents = mb_ereg_replace( "\\\\Sgdg\\\\Vendor\\\\uri_template\(", "\\uri_template(", $contents );
			}
			if ( __DIR__ . '/dist/bundled/vendor/google/apiclient/src/aliases.php' === $file_path ) {
				$contents = mb_ereg_replace( "'Sgdg\\\\\\\\Vendor\\\\\\\\Google\\\\\\\\(.*?)'\\s+=> 'Google_(.*?)'", "'Sgdg\\\\Vendor\\\\Google\\\\\\1' => 'Sgdg\\\\Vendor\\\\Google_\\2'", $contents );
			}
			$contents = mb_ereg_replace( "defined\('(\\\\\\\\)?GuzzleHttp", "defined('\\\\Sgdg\\\\Vendor\\\\GuzzleHttp", $contents );
			$contents = mb_ereg_replace( "array\('Monolog\\\\\\\\Utils', 'detectAndCleanUtf8'\)", "array('\\\\Sgdg\\\\Vendor\\\\Monolog\\\\Utils', 'detectAndCleanUtf8')", $contents );

			return $contents;
		},
	),
	'whitelist-global-classes'   => false,
	'whitelist-global-constants' => false, // TODO: Only difference is in Verify.php - check which one is correct. Defaults to true.
	'whitelist-global-functions' => false,
);
