<?php
/**
 * Inline Help.
 *
 * Handles providing a LiveChat icon within WPAdmin until such time
 * as the full live chat experience can be run in a non-Calypso environment.
 *
 * @package automattic/jetpack-masterbar
 */

namespace Automattic\Jetpack\Masterbar;

use Automattic\Jetpack\Assets;

/**
 * Class Inline_Help.
 *
 * @phan-constructor-used-for-side-effects
 */
class Inline_Help {

	/**
	 * Inline_Help constructor.
	 */
	public function __construct() {
		add_action( 'current_screen', array( $this, 'register_actions' ) );
	}

	/**
	 * Registers actions.
	 *
	 * @param object $current_screen Current screen object.
	 * @return void
	 */
	public function register_actions( $current_screen ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// Do not inject the FAB icon on embedded screens since the parent window may already contain a FAB icon.
		$is_framed = ! empty( $_GET['frame-nonce'] );

		// Do not inject the FAB icon on Yoast screens to avoid overlap with the Yoast help icon.
		$is_yoast = ! empty( $current_screen->base ) && str_contains( $current_screen->base, '_page_wpseo_' );

		if ( $is_framed || $is_yoast ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_action( 'admin_footer', array( $this, 'add_fab_icon' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'add_fab_styles' ) );
	}

	/**
	 * Outputs "FAB" icon markup and SVG.
	 *
	 * @return void|string the HTML markup for the FAB or early exit.
	 */
	public function add_fab_icon() {

		if ( wp_doing_ajax() ) {
			return;
		}

		$svg_allowed = array(
			'svg'   => array(
				'id'              => true,
				'class'           => true,
				'aria-hidden'     => true,
				'aria-labelledby' => true,
				'role'            => true,
				'xmlns'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true, // <= Must be lower case!
			),
			'g'     => array( 'fill' => true ),
			'title' => array( 'title' => true ),
			'path'  => array(
				'd'    => true,
				'fill' => true,
			),
		);

		$gridicon_help = file_get_contents( __DIR__ . '/gridicon-help.svg', true );

		// Add tracking data to link to be picked up by Calypso for GA and Tracks usage.
		$tracking_href = add_query_arg(
			array(
				'utm_source'  => 'wp_admin',
				'utm_medium'  => 'other',
				'utm_content' => 'jetpack_masterbar_inline_help_click',
				'flags'       => 'a8c-analytics.on',
			),
			'https://wordpress.com/help'
		);

		load_template(
			__DIR__ . '/inline-help-template.php',
			true,
			array(
				'href'        => $tracking_href,
				'icon'        => $gridicon_help,
				'svg_allowed' => $svg_allowed,
			)
		);
	}

	/**
	 * Enqueues FAB CSS styles.
	 *
	 * @return void
	 */
	public function add_fab_styles() {
		$assets_base_path = '../../dist/inline-help/';

		Assets::register_script(
			'a8c-faux-inline-help',
			$assets_base_path . 'inline-help.js',
			__FILE__,
			array(
				'enqueue'  => true,
				'css_path' => $assets_base_path . 'inline-help.css',
			)
		);
	}
}
