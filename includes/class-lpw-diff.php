<?php
/**
 * Image comparison for Lookit Page Watch.
 *
 * Two numbers come out of a comparison, and the second one matters more than
 * the first. Whole-image difference answers "how much of the page moved",
 * which is the right question for a redesign and the wrong one for a single
 * edited paragraph: hiding one block of text on a long page might be one
 * percent of the pixels and still be exactly what someone needs to see.
 *
 * So the image is also divided into a grid and every cell scored on its own.
 * The worst cell is reported alongside the overall figure, and either can
 * flag a page as changed.
 *
 * @package LookitPageWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downscaled pixel comparison with regional scoring.
 */
class LPW_Diff {

	const SAMPLE_W  = 200;
	const SAMPLE_H  = 800;
	const CELL      = 20;
	const TOLERANCE = 14;

	/**
	 * Is GD available.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'imagecreatefromstring' ) && function_exists( 'imagescale' );
	}

	/**
	 * Load any supported image format into a GD resource.
	 *
	 * imagecreatefromstring sniffs the format itself, so PNG, JPEG and WebP
	 * all work. Capture providers do not agree on a format, so this must not
	 * assume PNG.
	 *
	 * @param string $path Absolute file path.
	 * @return resource|GdImage|false
	 */
	private static function load( $path ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return false;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file we wrote.

		if ( false === $bytes || '' === $bytes ) {
			return false;
		}

		return @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Compare two image files.
	 *
	 * @param string $baseline_path Absolute path to the baseline image.
	 * @param string $current_path  Absolute path to the new capture.
	 * @return array{overall:float,region:float,height:float}|null Null if the comparison could not run.
	 */
	public static function compare( $baseline_path, $current_path ) {
		if ( ! self::available() ) {
			return null;
		}

		$a = self::load( $baseline_path );
		$b = self::load( $current_path );

		if ( ! $a || ! $b ) {
			return null;
		}

		$height_a     = imagesy( $a );
		$height_b     = imagesy( $b );
		$tallest      = max( $height_a, $height_b, 1 );
		$height_delta = ( abs( $height_a - $height_b ) / $tallest ) * 100;

		$sa = imagescale( $a, self::SAMPLE_W, self::SAMPLE_H );
		$sb = imagescale( $b, self::SAMPLE_W, self::SAMPLE_H );

		unset( $a, $b );

		if ( ! $sa || ! $sb ) {
			return null;
		}

		$cols  = (int) ceil( self::SAMPLE_W / self::CELL );
		$rows  = (int) ceil( self::SAMPLE_H / self::CELL );
		$cells = array_fill( 0, $cols * $rows, 0 );

		$different = 0;
		$total     = self::SAMPLE_W * self::SAMPLE_H;

		for ( $y = 0; $y < self::SAMPLE_H; $y++ ) {
			$cell_row = (int) ( $y / self::CELL );

			for ( $x = 0; $x < self::SAMPLE_W; $x++ ) {
				$pa = imagecolorat( $sa, $x, $y );
				$pb = imagecolorat( $sb, $x, $y );

				if ( $pa === $pb ) {
					continue;
				}

				$dr = abs( ( ( $pa >> 16 ) & 0xFF ) - ( ( $pb >> 16 ) & 0xFF ) );
				$dg = abs( ( ( $pa >> 8 ) & 0xFF ) - ( ( $pb >> 8 ) & 0xFF ) );
				$db = abs( ( $pa & 0xFF ) - ( $pb & 0xFF ) );

				if ( ( ( $dr + $dg + $db ) / 3 ) <= self::TOLERANCE ) {
					continue;
				}

				++$different;
				++$cells[ ( $cell_row * $cols ) + (int) ( $x / self::CELL ) ];
			}
		}

		unset( $sa, $sb );

		$cell_pixels = self::CELL * self::CELL;
		$worst_cell  = $cells ? max( $cells ) : 0;

		return array(
			'overall' => round( min( 100, max( ( $different / $total ) * 100, $height_delta ) ), 2 ),
			'region'  => round( min( 100, ( $worst_cell / $cell_pixels ) * 100 ), 2 ),
			'height'  => round( min( 100, $height_delta ), 2 ),
		);
	}
}
