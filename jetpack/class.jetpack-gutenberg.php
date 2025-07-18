<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Handles server-side registration and use of all blocks and plugins available in Jetpack for the block editor, aka Gutenberg.
 * Works in tandem with client-side block registration via `index.json`
 *
 * @package automattic/jetpack
 */

use Automattic\Jetpack\Assets;
use Automattic\Jetpack\Blocks;
use Automattic\Jetpack\Connection\Initial_State as Connection_Initial_State;
use Automattic\Jetpack\Connection\Manager as Connection_Manager;
use Automattic\Jetpack\Constants;
use Automattic\Jetpack\Current_Plan as Jetpack_Plan;
use Automattic\Jetpack\Modules;
use Automattic\Jetpack\My_Jetpack\Initializer as My_Jetpack_Initializer;
use Automattic\Jetpack\Status;
use Automattic\Jetpack\Status\Host;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- TODO: Move the functions and such to some other file.

/**
 * General Gutenberg editor specific functionality
 */
class Jetpack_Gutenberg {

	/**
	 * Only these extensions can be registered. Used to control availability of beta blocks.
	 *
	 * @var array|null Extensions allowed list or `null` if not initialized yet.
	 * @see static::get_extensions()
	 */
	private static $extensions = null;

	/**
	 * Keeps track of the reasons why a given extension is unavailable.
	 *
	 * @var array Extensions availability information
	 */
	private static $availability = array();

	/**
	 * A cached array of the fully processed availability data. Keeps track of
	 * reasons why an extension is unavailable or missing.
	 *
	 * @var array Extensions availability information.
	 */
	private static $cached_availability = null;

	/**
	 * Site-specific features available.
	 * Their calculation can be expensive and slow, so we're caching it for the request.
	 *
	 * @var array Site-specific features
	 */
	private static $site_specific_features = array();

	/**
	 * List of deprecated blocks.
	 *
	 * @var array List of deprecated blocks.
	 */
	private static $deprecated_blocks = array(
		'jetpack/revue',
	);

	/**
	 * Storing the contents of the preset file.
	 *
	 * Already been json_decode.
	 *
	 * @var null|object JSON decoded object after first usage.
	 */
	private static $preset_cache = null;

	/**
	 * Check to see if a minimum version of Gutenberg is available. Because a Gutenberg version is not available in
	 * php if the Gutenberg plugin is not installed, if we know which minimum WP release has the required version we can
	 * optionally fall back to that.
	 *
	 * @param array  $version_requirements An array containing the required Gutenberg version and, if known, the WordPress version that was released with this minimum version.
	 * @param string $slug The slug of the block or plugin that has the gutenberg version requirement.
	 *
	 * @since 8.3.0
	 *
	 * @return boolean True if the version of gutenberg required by the block or plugin is available.
	 */
	public static function is_gutenberg_version_available( $version_requirements, $slug ) {
		global $wp_version;

		// Bail if we don't at least have the gutenberg version requirement, the WP version is optional.
		if ( empty( $version_requirements['gutenberg'] ) ) {
			return false;
		}

		// If running a local dev build of gutenberg plugin GUTENBERG_DEVELOPMENT_MODE is set so assume correct version.
		if ( defined( 'GUTENBERG_DEVELOPMENT_MODE' ) && GUTENBERG_DEVELOPMENT_MODE ) {
			return true;
		}

		$version_available = false;

		// If running a production build of the gutenberg plugin then GUTENBERG_VERSION is set, otherwise if WP version
		// with required version of Gutenberg is known check that.
		if ( defined( 'GUTENBERG_VERSION' ) ) {
			$version_available = version_compare( GUTENBERG_VERSION, $version_requirements['gutenberg'], '>=' );
		} elseif ( ! empty( $version_requirements['wp'] ) ) {
			$version_available = version_compare( $wp_version, $version_requirements['wp'], '>=' );
		}

		if ( ! $version_available ) {
			$slug = self::remove_extension_prefix( $slug );
			self::set_extension_unavailable(
				$slug,
				'incorrect_gutenberg_version',
				array(
					'required_feature' => $slug,
					'required_version' => $version_requirements,
					'current_version'  => array(
						'wp'        => $wp_version,
						'gutenberg' => defined( 'GUTENBERG_VERSION' ) ? GUTENBERG_VERSION : null,
					),
				)
			);
		}

		return $version_available;
	}

	/**
	 * Prepend the 'jetpack/' prefix to a block name
	 *
	 * @param string $block_name The block name.
	 *
	 * @return string The prefixed block name.
	 */
	private static function prepend_block_prefix( $block_name ) {
		return 'jetpack/' . $block_name;
	}

	/**
	 * Remove the 'jetpack/' or jetpack-' prefix from an extension name
	 *
	 * @param string $extension_name The extension name.
	 *
	 * @return string The unprefixed extension name.
	 */
	public static function remove_extension_prefix( $extension_name ) {
		if ( str_starts_with( $extension_name, 'jetpack/' ) || str_starts_with( $extension_name, 'jetpack-' ) ) {
			return substr( $extension_name, strlen( 'jetpack/' ) );
		}
		return $extension_name;
	}

	/**
	 * Whether two arrays share at least one item
	 *
	 * @param array $a An array.
	 * @param array $b Another array.
	 *
	 * @return boolean True if $a and $b share at least one item
	 */
	protected static function share_items( $a, $b ) {
		return array_intersect( $a, $b ) !== array();
	}

	/**
	 * Set a (non-block) extension as available
	 *
	 * @param string $slug Slug of the extension.
	 */
	public static function set_extension_available( $slug ) {
		$slug                        = self::remove_extension_prefix( $slug );
		self::$availability[ $slug ] = true;
	}

	/**
	 * Set the reason why an extension (block or plugin) is unavailable
	 *
	 * @param string $slug Slug of the extension.
	 * @param string $reason A string representation of why the extension is unavailable.
	 * @param array  $details A free-form array containing more information on why the extension is unavailable.
	 */
	public static function set_extension_unavailable( $slug, $reason, $details = array() ) {
		if (
			// Extensions that require a plan may be eligible for upgrades.
			'missing_plan' === $reason
			&& (
				/**
				 * Filter 'jetpack_block_editor_enable_upgrade_nudge' with `true` to enable or `false`
				 * to disable paid feature upgrade nudges in the block editor.
				 *
				 * When this is changed to default to `true`, you should also update `modules/memberships/class-jetpack-memberships.php`
				 * See https://github.com/Automattic/jetpack/pull/13394#pullrequestreview-293063378
				 *
				 * @since 7.7.0
				 *
				 * @param boolean
				 */
				! apply_filters( 'jetpack_block_editor_enable_upgrade_nudge', false )
				/** This filter is documented in _inc/lib/admin-pages/class.jetpack-react-page.php */
				|| ! apply_filters( 'jetpack_show_promotions', true )
			)
		) {
			// The block editor may apply an upgrade nudge if `missing_plan` is the reason.
			// Add a descriptive suffix to disable behavior but provide informative reason.
			$reason .= '__nudge_disabled';
		}
		$slug                        = self::remove_extension_prefix( $slug );
		self::$availability[ $slug ] = array(
			'reason'  => $reason,
			'details' => $details,
		);
	}

	/**
	 * Used to initialize the class, no longer in use.
	 *
	 * @return void
	 * @deprecated 12.2 No longer needed.
	 */
	public static function init() {
		_deprecated_function( __METHOD__, '12.2' );
	}

	/**
	 * Resets the class to its original state
	 *
	 * Used in unit tests
	 *
	 * @return void
	 */
	public static function reset() {
		self::$extensions          = null;
		self::$availability        = array();
		self::$cached_availability = null;
	}

	/**
	 * Return the Gutenberg extensions (blocks and plugins) directory
	 *
	 * @return string The Gutenberg extensions directory
	 */
	public static function get_blocks_directory() {
		/**
		 * Filter to select Gutenberg blocks directory
		 *
		 * @since 6.9.0
		 *
		 * @param string default: '_inc/blocks/'
		 */
		return apply_filters( 'jetpack_blocks_directory', '_inc/blocks/' );
	}

	/**
	 * Checks for a given .json file in the blocks folder.
	 *
	 * @deprecated 14.3
	 *
	 * @param string $preset The name of the .json file to look for.
	 *
	 * @return bool True if the file is found.
	 */
	public static function preset_exists( $preset ) {
		_deprecated_function( __METHOD__, '14.3' );
		return file_exists( JETPACK__PLUGIN_DIR . self::get_blocks_directory() . $preset . '.json' );
	}

	/**
	 * Decodes JSON loaded from the preset file in the blocks folder
	 *
	 * @since 14.3 Deprecated argument. Only one value is ever used.
	 *
	 * @param null $deprecated No longer used.
	 *
	 * @return mixed Returns an object if the file is present, or false if a valid .json file is not present.
	 */
	public static function get_preset( $deprecated = null ) {
		if ( $deprecated ) {
			_deprecated_argument( __METHOD__, '$$next-version', 'The $preset argument is no longer needed or used.' );
		}

		if ( self::$preset_cache ) {
			return self::$preset_cache;
		}

		self::$preset_cache = json_decode(
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			file_get_contents( JETPACK__PLUGIN_DIR . self::get_blocks_directory() . 'index.json' )
		);
		return self::$preset_cache;
	}

	/**
	 * Returns a list of Jetpack Gutenberg extensions (blocks and plugins), based on index.json
	 *
	 * @return array A list of blocks: eg [ 'publicize', 'markdown' ]
	 */
	public static function get_jetpack_gutenberg_extensions_allowed_list() {
		$preset_extensions_manifest = ( defined( 'TESTING_IN_JETPACK' ) && TESTING_IN_JETPACK ) ? array() : self::get_preset();
		$blocks_variation           = self::blocks_variation();

		return self::get_extensions_preset_for_variation( $preset_extensions_manifest, $blocks_variation );
	}

	/**
	 * Returns a diff from a combined list of allowed extensions and extensions determined to be excluded
	 *
	 * @param  array $allowed_extensions An array of allowed extensions.
	 *
	 * @return array A list of blocks: eg array( 'publicize', 'markdown' )
	 */
	public static function get_available_extensions( $allowed_extensions = null ) {
		$exclusions         = get_option( 'jetpack_excluded_extensions', array() );
		$allowed_extensions = $allowed_extensions === null ? self::get_jetpack_gutenberg_extensions_allowed_list() : $allowed_extensions;

		return array_diff( $allowed_extensions, $exclusions );
	}

	/**
	 * Return true if the extension has been registered and there's nothing in the availablilty array.
	 *
	 * @param string $extension The name of the extension.
	 *
	 * @return bool whether the extension has been registered and there's nothing in the availablilty array.
	 */
	public static function is_registered_and_no_entry_in_availability( $extension ) {
		return self::is_registered( 'jetpack/' . $extension ) && ! isset( self::$availability[ $extension ] );
	}

	/**
	 * Return true if the extension has a true entry in the availablilty array.
	 *
	 * @param string $extension The name of the extension.
	 *
	 * @return bool whether the extension has a true entry in the availablilty array.
	 */
	public static function is_available( $extension ) {
		return isset( self::$availability[ $extension ] ) && true === self::$availability[ $extension ];
	}

	/**
	 * Get the availability of each block / plugin, or return the cached availability
	 * if it has already been calculated. Avoids re-registering extensions when not
	 * necessary.
	 *
	 * @return array A list of block and plugins and their availability status.
	 */
	public static function get_cached_availability() {
		if ( null === self::$cached_availability ) {
			self::$cached_availability = self::get_availability();
		}
		return self::$cached_availability;
	}

	/**
	 * Get availability of each block / plugin.
	 *
	 * @return array A list of block and plugins and their availablity status
	 */
	public static function get_availability() {
		/**
		 * Fires before Gutenberg extensions availability is computed.
		 *
		 * In the function call you supply, use `Blocks::jetpack_register_block()` to set a block as available.
		 * Alternatively, use `Jetpack_Gutenberg::set_extension_available()` (for a non-block plugin), and
		 * `Jetpack_Gutenberg::set_extension_unavailable()` (if the block or plugin should not be registered
		 * but marked as unavailable).
		 *
		 * @since 7.0.0
		 */
		do_action( 'jetpack_register_gutenberg_extensions' );

		$available_extensions = array();

		foreach ( static::get_extensions() as $extension ) {
			$is_available                       = self::is_registered_and_no_entry_in_availability( $extension ) || self::is_available( $extension );
			$available_extensions[ $extension ] = array(
				'available' => $is_available,
			);

			if ( ! $is_available ) {
				$reason  = isset( self::$availability[ $extension ] ) ? self::$availability[ $extension ]['reason'] : 'missing_module';
				$details = isset( self::$availability[ $extension ] ) ? self::$availability[ $extension ]['details'] : array();
				$available_extensions[ $extension ]['unavailable_reason'] = $reason;
				$available_extensions[ $extension ]['details']            = $details;
			}
		}

		return $available_extensions;
	}

	/**
	 * Return the list of extensions that are available.
	 *
	 * @since 11.9
	 *
	 * @return array A list of block and plugins and their availability status.
	 */
	public static function get_extensions() {
		if ( ! static::should_load() ) {
			return array();
		}

		if ( null === self::$extensions ) {
			/**
			 * Filter the list of block editor extensions that are available through Jetpack.
			 *
			 * @since 7.0.0
			 *
			 * @param array
			 */
			self::$extensions = apply_filters( 'jetpack_set_available_extensions', self::get_available_extensions() );
		}

		return self::$extensions;
	}

	/**
	 * Check if an extension/block is already registered
	 *
	 * @since 7.2
	 *
	 * @param string $slug Name of extension/block to check.
	 *
	 * @return bool
	 */
	public static function is_registered( $slug ) {
		return WP_Block_Type_Registry::get_instance()->is_registered( $slug );
	}

	/**
	 * Check if Gutenberg editor is available
	 *
	 * @since 6.7.0
	 *
	 * @return bool
	 */
	public static function is_gutenberg_available() {
		return true;
	}

	/**
	 * Check whether conditions indicate Gutenberg Extensions (blocks and plugins) should be loaded
	 *
	 * Loading blocks and plugins is enabled by default and may be disabled via filter:
	 *   add_filter( 'jetpack_gutenberg', '__return_false' );
	 *
	 * @since 6.9.0
	 *
	 * @return bool
	 */
	public static function should_load() {
		if ( ! Jetpack::is_connection_ready() && ! ( new Status() )->is_offline_mode() ) {
			return false;
		}

		$return = true;

		if ( ! ( new Modules() )->is_active( 'blocks' ) ) {
			$return = false;
		}

		/**
		 * Filter to enable Gutenberg blocks.
		 *
		 * Defaults to true if (connected or in offline mode) and the Blocks module is active.
		 *
		 * @since 6.5.0
		 * @since 13.9 Filter is able to activate or deactivate Gutenberg blocks.
		 *
		 * @param bool true Whether to load Gutenberg blocks
		 */
		return (bool) apply_filters( 'jetpack_gutenberg', $return );
	}

	/**
	 * Queue a script to set `Jetpack_Block_Assets_Base_Url`.
	 *
	 * In certain cases Webpack needs to know a base to load additional assets from.
	 * Normally it can determine that itself, but when JS concatenation is involved that tends to confuse it.
	 * We work around that by explicitly outputting a variable with the correct URL.
	 * We set that as its own "script" so we can reliably only output it once.
	 */
	private static function register_blocks_assets_base_url() {
		if ( ! wp_script_is( 'jetpack-blocks-assets-base-url', 'registered' ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- No actual script, so no version needed.
			wp_register_script( 'jetpack-blocks-assets-base-url', false, array(), null, array( 'in_footer' => false ) );
			$json_encode_flags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
			if ( get_option( 'blog_charset' ) === 'UTF-8' ) {
				$json_encode_flags |= JSON_UNESCAPED_UNICODE;
			}
			wp_add_inline_script(
				'jetpack-blocks-assets-base-url',
				'var Jetpack_Block_Assets_Base_Url=' . wp_json_encode( plugins_url( self::get_blocks_directory(), JETPACK__PLUGIN_FILE ), $json_encode_flags ) . ';',
				'before'
			);
		}
	}

	/**
	 * Only enqueue block assets when needed.
	 *
	 * @param string $type Slug of the block or absolute path to the block source code directory.
	 * @param array  $script_dependencies Script dependencies. Will be merged with automatically
	 *                                    detected script dependencies from the webpack build.
	 *
	 * @return void
	 */
	public static function load_assets_as_required( $type, $script_dependencies = array() ) {
		if ( is_admin() ) {
			// A block's view assets will not be required in wp-admin.
			return;
		}

		// Retrieve the feature from block.json if a path is passed.
		if ( path_is_absolute( $type ) ) {
			$metadata = Blocks::get_block_metadata_from_file( Blocks::get_path_to_block_metadata( $type ) );
			$feature  = Blocks::get_block_feature_from_metadata( $metadata );

			if ( ! empty( $feature ) ) {
				$type = $feature;
			}
		}

		$type = sanitize_title_with_dashes( $type );
		self::load_styles_as_required( $type );
		self::load_scripts_as_required( $type, $script_dependencies );
	}

	/**
	 * Only enqueue block sytles when needed.
	 *
	 * @param string $type Slug of the block.
	 *
	 * @since 7.2.0
	 *
	 * @return void
	 */
	public static function load_styles_as_required( $type ) {
		if ( is_admin() ) {
			// A block's view assets will not be required in wp-admin.
			return;
		}

		// Enqueue styles.
		$style_relative_path = self::get_blocks_directory() . $type . '/view' . ( is_rtl() ? '.rtl' : '' ) . '.css';
		if ( self::block_has_asset( $style_relative_path ) ) {
			$style_version = self::get_asset_version( $style_relative_path );
			$view_style    = plugins_url( $style_relative_path, JETPACK__PLUGIN_FILE );
			$view_style    = add_query_arg( 'minify', 'false', $view_style );

			// If this is a customizer preview, render the style directly to the preview after autosave.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_customize_preview() && ! empty( $_GET['customize_autosaved'] ) ) {
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
				echo '<link rel="stylesheet" id="jetpack-block-' . esc_attr( $type ) . '" href="' . esc_attr( $view_style ) . '&amp;ver=' . esc_attr( $style_version ) . '" media="all">';
			} else {
				wp_enqueue_style( 'jetpack-block-' . $type, $view_style, array(), $style_version );
				wp_style_add_data( 'jetpack-block-' . $type, 'path', JETPACK__PLUGIN_DIR . $style_relative_path );
			}
		}
	}

	/**
	 * Only enqueue block scripts when needed.
	 *
	 * @param string $type Slug of the block.
	 * @param array  $script_dependencies Script dependencies. Will be merged with automatically
	 *                             detected script dependencies from the webpack build.
	 *
	 * @since 7.2.0
	 *
	 * @return void
	 */
	public static function load_scripts_as_required( $type, $script_dependencies = array() ) {
		if ( is_admin() ) {
			// A block's view assets will not be required in wp-admin.
			return;
		}

		self::register_blocks_assets_base_url();

		// Enqueue script.
		$script_relative_path  = self::get_blocks_directory() . $type . '/view.js';
		$script_deps_path      = JETPACK__PLUGIN_DIR . self::get_blocks_directory() . $type . '/view.asset.php';
		$script_dependencies[] = 'jetpack-blocks-assets-base-url';
		if ( file_exists( $script_deps_path ) ) {
			$asset_manifest      = include $script_deps_path;
			$script_dependencies = array_unique( array_merge( $script_dependencies, $asset_manifest['dependencies'] ) );
		}

		if ( ! Blocks::is_amp_request() && self::block_has_asset( $script_relative_path ) ) {
			$script_version = self::get_asset_version( $script_relative_path );
			$view_script    = plugins_url( $script_relative_path, JETPACK__PLUGIN_FILE );
			$view_script    = add_query_arg( 'minify', 'false', $view_script );

			// Enqueue dependencies.
			wp_enqueue_script( 'jetpack-block-' . $type, $view_script, $script_dependencies, $script_version, false );

			// If this is a customizer preview, enqueue the dependencies and render the script directly to the preview after autosave.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_customize_preview() && ! empty( $_GET['customize_autosaved'] ) ) {
				// The Map block is dependent on wp-element, and it doesn't appear to to be possible to load
				// this dynamically into the customizer iframe currently.
				if ( 'map' === $type ) {
					echo '<div>' . esc_html_e( 'No map preview available. Publish and refresh to see this widget.', 'jetpack' ) . '</div>';
					echo '<script>';
					echo 'Array.from(document.getElementsByClassName(\'wp-block-jetpack-map\')).forEach(function(element){element.style.display = \'none\';})';
					echo '</script>';
				} else {
					echo '<script id="jetpack-block-' . esc_attr( $type ) . '" src="' . esc_attr( $view_script ) . '&amp;ver=' . esc_attr( $script_version ) . '"></script>';
				}
			}
		}
	}

	/**
	 * Check if an asset exists for a block.
	 *
	 * @param string $file Path of the file we are looking for.
	 *
	 * @return bool $block_has_asset Does the file exist.
	 */
	public static function block_has_asset( $file ) {
		return file_exists( JETPACK__PLUGIN_DIR . $file );
	}

	/**
	 * Get the version number to use when loading the file. Allows us to bypass cache when developing.
	 *
	 * @param string $file Path of the file we are looking for.
	 *
	 * @return string $script_version Version number.
	 */
	public static function get_asset_version( $file ) {
		return Jetpack::is_development_version() && self::block_has_asset( $file )
			? filemtime( JETPACK__PLUGIN_DIR . $file )
			: JETPACK__VERSION;
	}

	/**
	 * Load Gutenberg editor assets
	 *
	 * @since 6.7.0
	 *
	 * @return void
	 */
	public static function enqueue_block_editor_assets() {
		if ( ! self::should_load() ) {
			return;
		}

		$status = new Status();

		// Required for Analytics. See _inc/lib/admin-pages/class.jetpack-admin-page.php.
		if ( ! $status->is_offline_mode() && Jetpack::is_connection_ready() ) {
			wp_enqueue_script( 'jp-tracks', '//stats.wp.com/w.js', array(), gmdate( 'YW' ), true );
		}

		$blocks_dir       = self::get_blocks_directory();
		$blocks_variation = self::blocks_variation();

		if ( 'production' !== $blocks_variation ) {
			$blocks_env = '-' . esc_attr( $blocks_variation );
		} else {
			$blocks_env = '';
		}

		self::register_blocks_assets_base_url();

		Assets::register_script(
			'jetpack-blocks-editor',
			"{$blocks_dir}editor{$blocks_env}.js",
			JETPACK__PLUGIN_FILE,
			array(
				'textdomain'   => 'jetpack',
				'dependencies' => array( 'jetpack-blocks-assets-base-url' ),
			)
		);

		/**
		 * This can be called multiple times per page load in the admin, during the `enqueue_block_assets` action.
		 * These assets are necessary for the admin for editing but are not necessary for each pattern preview.
		 * Therefore we dequeue them, so they don't load for each pattern preview iframe.
		 */
		if ( ! wp_should_load_block_editor_scripts_and_styles() ) {
			wp_dequeue_script( 'jp-tracks' );
			wp_dequeue_script( 'jetpack-blocks-editor' );

			return;
		}

		// Hack around #20357 (specifically, that the editor bundle depends on
		// wp-edit-post but wp-edit-post's styles break the Widget Editor and
		// Site Editor) until a real fix gets unblocked.
		// @todo Remove this once #20357 is properly fixed.
		$wp_styles_fix = wp_styles()->query( 'jetpack-blocks-editor', 'registered' );
		if ( empty( $wp_styles_fix ) ) {
			wp_die( 'Your installation of Jetpack is incomplete. Please run "jetpack build plugins/jetpack" in your dev env.' );
		}
		wp_styles()->query( 'jetpack-blocks-editor', 'registered' )->deps = array();

		Assets::enqueue_script( 'jetpack-blocks-editor' );

		if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
			$user                      = wp_get_current_user();
			$user_data                 = array(
				'email'    => $user->user_email,
				'userid'   => $user->ID,
				'username' => $user->user_login,
			);
			$blog_id                   = get_current_blog_id();
			$is_current_user_connected = true;
		} else {
			$user_data                 = Jetpack_Tracks_Client::get_connected_user_tracks_identity();
			$blog_id                   = Jetpack_Options::get_option( 'id', 0 );
			$is_current_user_connected = ( new Connection_Manager( 'jetpack' ) )->is_user_connected();
		}

		if ( $blocks_variation === 'beta' && $is_current_user_connected ) {
			wp_enqueue_style( 'recoleta-font', '//s1.wp.com/i/fonts/recoleta/css/400.min.css', array(), Constants::get_constant( 'JETPACK__VERSION' ) );
		}
		// AI Assistant
		$ai_assistant_state = array(
			'is-enabled' => apply_filters( 'jetpack_ai_enabled', true ),
		);

		$screen_base = null;
		if ( function_exists( 'get_current_screen' ) ) {
			$current_screen = get_current_screen();
			$screen_base    = $current_screen ? $current_screen->base : null;
		}

		$modules = array();
		if ( class_exists( 'Jetpack_Core_API_Module_List_Endpoint' ) ) {
			$module_list_endpoint = new Jetpack_Core_API_Module_List_Endpoint();
			$modules              = $module_list_endpoint->get_modules();
		}

		$jetpack_plan  = Jetpack_Plan::get();
		$initial_state = array(
			'available_blocks' => self::get_availability(),
			'blocks_variation' => $blocks_variation,
			'modules'          => $modules,
			'jetpack'          => array(
				'is_active'                     => Jetpack::is_connection_ready(),
				'is_current_user_connected'     => $is_current_user_connected,
				/** This filter is documented in class.jetpack-gutenberg.php */
				'enable_upgrade_nudge'          => apply_filters( 'jetpack_block_editor_enable_upgrade_nudge', false ),
				'is_private_site'               => $status->is_private_site(),
				'is_coming_soon'                => $status->is_coming_soon(),
				'is_offline_mode'               => $status->is_offline_mode(),
				'is_newsletter_feature_enabled' => class_exists( '\Jetpack_Memberships' ),
				// this is the equivalent of JP initial state siteData.showMyJetpack (class-jetpack-redux-state-helper)
				// used to determine if we can link to My Jetpack from the block editor
				'is_my_jetpack_available'       => My_Jetpack_Initializer::should_initialize(),
				'jetpack_plan'                  => array(
					'data' => $jetpack_plan['product_slug'],
				),
				/**
				 * Enable the RePublicize UI in the block editor context.
				 *
				 * @module publicize
				 *
				 * @since 10.3.0
				 * @deprecated 11.5 This is a feature flag that is no longer used.
				 *
				 * @param bool true Enable the RePublicize UI in the block editor context. Defaults to true.
				 */
				'republicize_enabled'           => apply_filters( 'jetpack_block_editor_republicize_feature', true ),
			),
			'siteFragment'     => $status->get_site_suffix(),
			'adminUrl'         => esc_url( admin_url() ),
			'tracksUserData'   => $user_data,
			'wpcomBlogId'      => $blog_id,
			'allowedMimeTypes' => wp_get_mime_types(),
			'siteLocale'       => str_replace( '_', '-', get_locale() ),
			'ai-assistant'     => $ai_assistant_state,
			'screenBase'       => $screen_base,
			/**
			 * Add your own feature flags to the block editor.
			 *
			 * You can access the feature flags in the block editor via hasFeatureFlag( 'your-feature-flag' ) function.
			 *
			 * @since 14.8
			 *
			 * @param array true Enable the RePublicize UI in the block editor context. Defaults to true.
			 */
			'feature_flags'    => apply_filters( 'jetpack_block_editor_feature_flags', array() ),
			'pluginBasePath'   => plugins_url( '', Constants::get_constant( 'JETPACK__PLUGIN_FILE' ) ),
		);

		wp_localize_script(
			'jetpack-blocks-editor',
			'Jetpack_Editor_Initial_State',
			$initial_state
		);

		// Adds Connection package initial state.
		Connection_Initial_State::render_script( 'jetpack-blocks-editor' );

		// Register and enqueue the Jetpack Chrome AI token script
		wp_register_script(
			'jetpack-chrome-ai-token',
			'https://widgets.wp.com/jetpack-chrome-ai/v1/3p-token.js',
			array(),
			gmdate( 'Ymd' ) . floor( (int) gmdate( 'G' ) / 12 ), // Cache buster: changes twice daily (morning/afternoon) in case we need to rotate the tokens
			true
		);
		wp_enqueue_script( 'jetpack-chrome-ai-token' );
	}

	/**
	 * Some blocks do not depend on a specific module,
	 * and can consequently be loaded outside of the usual modules.
	 * We will look for such modules in the extensions/ directory.
	 *
	 * @since 7.1.0
	 * @see wp_common_block_scripts_and_styles()
	 */
	public static function load_independent_blocks() {
		if ( self::should_load() ) {
			/**
			 * Look for files that match our list of available Jetpack Gutenberg extensions (blocks and plugins).
			 * If available, load them.
			 */
			$directories = array( 'blocks', 'plugins', 'extended-blocks' );

			foreach ( static::get_extensions() as $extension ) {
				foreach ( $directories as $dirname ) {
					$path = JETPACK__PLUGIN_DIR . "extensions/{$dirname}/{$extension}/{$extension}.php";

					if ( file_exists( $path ) ) {
						include_once $path;
						continue 2;
					}
				}
			}
		}
	}

	/**
	 * Loads PHP components of block editor extensions.
	 *
	 * @since 8.9.0
	 */
	public static function load_block_editor_extensions() {
		if ( self::should_load() ) {
			// Block editor extensions to load.
			$extensions_to_load = array(
				'extended-blocks',
				'plugins',
			);

			// Collect the extension paths.
			foreach ( $extensions_to_load as $extension_to_load ) {
				$extensions_folder = glob( JETPACK__PLUGIN_DIR . 'extensions/' . $extension_to_load . '/*' );

				// Require each of the extension files, in case it exists.
				foreach ( $extensions_folder as $extension_folder ) {
					$name                = basename( $extension_folder );
					$extension_file_path = JETPACK__PLUGIN_DIR . 'extensions/' . $extension_to_load . '/' . $name . '/' . $name . '.php';

					if ( file_exists( $extension_file_path ) ) {
						include_once $extension_file_path;
					}
				}
			}
		}
	}

	/**
	 * Determine whether a site should use the default set of blocks, or a custom set.
	 * Possible variations are currently beta, experimental, and production.
	 *
	 * @since 8.1.0
	 *
	 * @return string $block_varation production|beta|experimental
	 */
	public static function blocks_variation() {
		// Default to production blocks.
		$block_varation = 'production';

		/*
		 * Prefer to use this JETPACK_BLOCKS_VARIATION constant
		 * or the jetpack_blocks_variation filter
		 * to set the block variation in your code.
		 */
		$default = Constants::get_constant( 'JETPACK_BLOCKS_VARIATION' );
		if ( ! empty( $default ) && in_array( $default, array( 'beta', 'experimental', 'production' ), true ) ) {
			$block_varation = $default;
		}

		/**
		* Alternative to `JETPACK_BETA_BLOCKS`, set to `true` to load Beta Blocks.
		*
		* @since 6.9.0
		* @deprecated 11.8.0 Use jetpack_blocks_variation filter instead.
		*
		* @param boolean
		*/
		$is_beta = apply_filters_deprecated(
			'jetpack_load_beta_blocks',
			array( false ),
			'jetpack-11.8.0',
			'jetpack_blocks_variation'
		);

		/*
		 * Switch to beta blocks if you use the JETPACK_BETA_BLOCKS constant
		 * or the deprecated jetpack_load_beta_blocks filter.
		 * This only applies when not using the newer JETPACK_BLOCKS_VARIATION constant.
		 */
		if (
			empty( $default )
			&& (
				$is_beta
				|| Constants::is_true( 'JETPACK_BETA_BLOCKS' )
			)
		) {
			$block_varation = 'beta';
		}

		/**
		* Alternative to `JETPACK_EXPERIMENTAL_BLOCKS`, set to `true` to load Experimental Blocks.
		*
		* @since 6.9.0
		* @deprecated 11.8.0 Use jetpack_blocks_variation filter instead.
		*
		* @param boolean
		*/
		$is_experimental = apply_filters_deprecated(
			'jetpack_load_experimental_blocks',
			array( false ),
			'jetpack-11.8.0',
			'jetpack_blocks_variation'
		);

		/*
		 * Switch to experimental blocks if you use the JETPACK_EXPERIMENTAL_BLOCKS constant
		 * or the deprecated jetpack_load_experimental_blocks filter.
		 * This only applies when not using the newer JETPACK_BLOCKS_VARIATION constant.
		 */
		if (
			empty( $default )
			&& (
				$is_experimental
				|| Constants::is_true( 'JETPACK_EXPERIMENTAL_BLOCKS' )
			)
		) {
			$block_varation = 'experimental';
		}

		/**
		 * Allow customizing the variation of blocks in use on a site.
		 * Overwrites any previously set values, whether by constant or filter.
		 *
		 * @since 8.1.0
		 *
		 * @param string $block_variation Can be beta, experimental, and production. Defaults to production.
		 */
		return apply_filters( 'jetpack_blocks_variation', $block_varation );
	}

	/**
	 * Get a list of extensions available for the variation you chose.
	 *
	 * @since 8.1.0
	 *
	 * @param object $preset_extensions_manifest List of extensions available in Jetpack.
	 * @param string $blocks_variation           Subset of blocks. production|beta|experimental.
	 *
	 * @return array $preset_extensions Array of extensions for that variation
	 */
	public static function get_extensions_preset_for_variation( $preset_extensions_manifest, $blocks_variation ) {
		$preset_extensions = isset( $preset_extensions_manifest->{ $blocks_variation } )
				? (array) $preset_extensions_manifest->{ $blocks_variation }
				: array();

		/*
		 * Experimental and Beta blocks need the production blocks as well.
		 */
		if (
			'experimental' === $blocks_variation
			|| 'beta' === $blocks_variation
		) {
			$production_extensions = isset( $preset_extensions_manifest->production )
				? (array) $preset_extensions_manifest->production
				: array();

			$preset_extensions = array_unique( array_merge( $preset_extensions, $production_extensions ) );
		}

		/*
		 * Beta blocks need the experimental blocks as well.
		 *
		 * If you've chosen to see Beta blocks,
		 * we want to make all blocks available to you:
		 * - Production
		 * - Experimental
		 * - Beta
		 */
		if ( 'beta' === $blocks_variation ) {
			$production_extensions = isset( $preset_extensions_manifest->experimental )
				? (array) $preset_extensions_manifest->experimental
				: array();

			$preset_extensions = array_unique( array_merge( $preset_extensions, $production_extensions ) );
		}

		return $preset_extensions;
	}

	/**
	 * Validate a URL used in a SSR block.
	 *
	 * @since 8.3.0
	 *
	 * @param string $url      URL saved as an attribute in block.
	 * @param array  $allowed  Array of allowed hosts for that block, or regexes to check against.
	 * @param bool   $is_regex Array of regexes matching the URL that could be used in block.
	 *
	 * @return bool|string
	 */
	public static function validate_block_embed_url( $url, $allowed = array(), $is_regex = false ) {
		if (
			empty( $url )
			|| ! is_array( $allowed )
			|| empty( $allowed )
		) {
			return false;
		}

		$url_components = wp_parse_url( $url );

		// Bail early if we cannot find a host.
		if ( empty( $url_components['host'] ) ) {
			return false;
		}

		// Normalize URL.
		$url = sprintf(
			'%s://%s%s%s',
			isset( $url_components['scheme'] ) ? $url_components['scheme'] : 'https',
			$url_components['host'],
			isset( $url_components['path'] ) ? $url_components['path'] : '/',
			isset( $url_components['query'] ) ? '?' . $url_components['query'] : ''
		);

		if ( ! empty( $url_components['fragment'] ) ) {
			$url = $url . '#' . rawurlencode( $url_components['fragment'] );
		}

		/*
		 * If we're using an allowed list of hosts,
		 * check if the URL belongs to one of the domains allowed for that block.
		 */
		if (
			false === $is_regex
			&& in_array( $url_components['host'], $allowed, true )
		) {
			return $url;
		}

		/*
		 * If we are using an array of regexes to check against,
		 * loop through that.
		 */
		if ( true === $is_regex ) {
			foreach ( $allowed as $regex ) {
				if ( 1 === preg_match( $regex, $url ) ) {
					return $url;
				}
			}
		}

		return false;
	}

	/**
	 * Determines whether a preview of the block with an upgrade nudge should
	 * be displayed for admins on the site frontend.
	 *
	 * @since 8.4.0
	 *
	 * @param array $availability_for_block The availability for the block.
	 *
	 * @return bool
	 */
	public static function should_show_frontend_preview( $availability_for_block ) {
		return (
			isset( $availability_for_block['details']['required_plan'] )
			&& current_user_can( 'manage_options' )
			&& ! is_feed()
		);
	}

	/**
	 * Output an UpgradeNudge Component on the frontend of a site.
	 *
	 * @since 8.4.0
	 *
	 * @param string $plan The plan that users need to purchase to make the block work.
	 *
	 * @return string
	 */
	public static function upgrade_nudge( $plan ) {
		require_once JETPACK__PLUGIN_DIR . '_inc/lib/components.php';
		return Jetpack_Components::render_upgrade_nudge(
			array(
				'plan' => $plan,
			)
		);
	}

	/**
	 * Output a notice within a block.
	 *
	 * @since 8.6.0
	 *
	 * @param string $message Notice we want to output.
	 * @param string $status  Status of the notice. Can be one of success, info, warning, error. info by default.
	 * @param string $classes List of CSS classes.
	 *
	 * @return string
	 */
	public static function notice( $message, $status = 'info', $classes = '' ) {
		if (
			empty( $message )
			|| ! in_array( $status, array( 'success', 'info', 'warning', 'error' ), true )
		) {
			return '';
		}

		$color = '';
		switch ( $status ) {
			case 'success':
				$color = '#00a32a';
				break;
			case 'warning':
				$color = '#dba617';
				break;
			case 'error':
				$color = '#d63638';
				break;
			case 'info':
			default:
				$color = '#72aee6';
				break;
		}

		return sprintf(
			'<div class="jetpack-block__notice %1$s %3$s" style="border-left:5px solid %4$s;padding:1em;background-color:#f8f9f9;">%2$s</div>',
			esc_attr( $status ),
			wp_kses(
				$message,
				array(
					'br' => array(),
					'p'  => array(),
					'a'  => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			),
			esc_attr( $classes ),
			sanitize_hex_color( $color )
		);
	}

	/**
	 * Retrieve site-specific features for Simple sites.
	 *
	 * We're caching the data for the lifetime of the request, because it can be slow to calculate,
	 * and it can be called multiple times per single request.
	 *
	 * We intentionally don't use object caching or any other type of persistent caching,
	 * in order to avoid complex cache invalidation on subscription addition or removal.
	 *
	 * @since 10.7
	 *
	 * @return array
	 */
	private static function get_site_specific_features() {
		$current_blog_id = get_current_blog_id();

		if ( isset( self::$site_specific_features[ $current_blog_id ] ) ) {
			return self::$site_specific_features[ $current_blog_id ];
		}

		if ( ! class_exists( 'Store_Product_List' ) ) {
			require WP_CONTENT_DIR . '/admin-plugins/wpcom-billing/store-product-list.php';
		}

		$site_specific_features                           = Store_Product_List::get_site_specific_features_data( $current_blog_id );
		self::$site_specific_features[ $current_blog_id ] = $site_specific_features;

		return $site_specific_features;
	}

	/**
	 * Set the availability of the block as the editor
	 * is loaded.
	 *
	 * @param string $slug Slug of the block.
	 */
	public static function set_availability_for_plan( $slug ) {
		$slug = self::remove_extension_prefix( $slug );

		if ( Jetpack_Plan::supports( $slug ) ) {
			self::set_extension_available( $slug );
			return;
		}

		// Check what's the minimum plan where the feature is available.
		$plan           = '';
		$features_data  = array();
		$is_simple_site = defined( 'IS_WPCOM' ) && IS_WPCOM;
		$is_atomic_site = ( new Host() )->is_woa_site();

		if ( $is_simple_site || $is_atomic_site ) {
			// Simple sites.
			if ( $is_simple_site ) {
				$features_data = self::get_site_specific_features();
			} else {
				// Atomic sites.
				$option = get_option( 'jetpack_active_plan' );
				if ( isset( $option['features'] ) ) {
					$features_data = $option['features'];
				}
			}

			if ( ! empty( $features_data['available'][ $slug ] ) ) {
				$plan = $features_data['available'][ $slug ][0];
			}
		} else {
			// Jetpack sites.
			$plan = Jetpack_Plan::get_minimum_plan_for_feature( $slug );
		}

		self::set_extension_unavailable(
			$slug,
			'missing_plan',
			array(
				'required_feature' => $slug,
				'required_plan'    => $plan,
			)
		);
	}

	/**
	 * Wraps the suplied render_callback in a function to check
	 * the availability of the block before rendering it.
	 *
	 * @param string   $slug The block slug, used to check for availability.
	 * @param callable $render_callback The render_callback that will be called if the block is available.
	 */
	public static function get_render_callback_with_availability_check( $slug, $render_callback ) {
		return function ( $prepared_attributes, $block_content, $block ) use ( $render_callback, $slug ) {
			$availability = self::get_cached_availability();
			$bare_slug    = self::remove_extension_prefix( $slug );
			if ( isset( $availability[ $bare_slug ] ) && $availability[ $bare_slug ]['available'] ) {
				return call_user_func( $render_callback, $prepared_attributes, $block_content, $block );
			}

			// A preview of the block is rendered for admins on the frontend with an upgrade nudge.
			if ( isset( $availability[ $bare_slug ] ) ) {
				if ( self::should_show_frontend_preview( $availability[ $bare_slug ] ) ) {
					$block_preview = call_user_func( $render_callback, $prepared_attributes, $block_content, $block );

					// If the upgrade nudge isn't already being displayed by a parent block, display the nudge.
					if ( isset( $block->attributes['shouldDisplayFrontendBanner'] ) && $block->attributes['shouldDisplayFrontendBanner'] ) {
						$upgrade_nudge = self::upgrade_nudge( $availability[ $bare_slug ]['details']['required_plan'] );
						return $upgrade_nudge . $block_preview;
					}

					return $block_preview;
				}
			}

			return null;
		};
	}

	/**
	 * Display a message to site editors and roles above when a block is no longer supported.
	 * This is only displayed on the frontend.
	 *
	 * @since 12.3
	 *
	 * @param string $block_content The block content.
	 * @param array  $block         The full block, including name and attributes.
	 *
	 * @return string
	 */
	public static function display_deprecated_block_message( $block_content, $block ) {
		if ( isset( $block['blockName'] ) && in_array( $block['blockName'], self::$deprecated_blocks, true ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				$block_content = self::notice(
					__( 'This block is no longer supported. Its contents will no longer be displayed to your visitors and as such this block should be removed.', 'jetpack' ),
					'warning',
					'jetpack-block-deprecated'
				);
			} else {
				$block_content = '';
			}
		}

		return $block_content;
	}

	/**
	 * Temporarily bypasses _doing_it_wrong() notices for block metadata collection registration.
	 *
	 * WordPress 6.7 introduced block metadata collections (with strict path validation).
	 * Any sites using symlinks for plugins will fail the validation which causes the metadata
	 * collection to not be registered. However, the blocks will still fall back to the regular
	 * registration and no functionality is affected.
	 * While this validation is being discussed in WordPress Core (#62140),
	 * this method allows registration to proceed by temporarily disabling
	 * the relevant notice.
	 *
	 * @since 14.2
	 *
	 * @param bool   $trigger       Whether to trigger the error.
	 * @param string $function      The function that was called.
	 * @param string $message       A message explaining what was done incorrectly.
	 * @param string $version       The version of WordPress where the message was added.
	 * @return bool Whether to trigger the error.
	 */
	public static function bypass_block_metadata_doing_it_wrong( $trigger, $function, $message, $version ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( $function === 'WP_Block_Metadata_Registry::register_collection' ) {
			return false;
		}
		return $trigger;
	}

	/**
	 * Register block metadata collection for Jetpack blocks.
	 * This allows for more efficient block metadata loading by avoiding
	 * individual block.json file reads at runtime.
	 *
	 * Uses wp_register_block_metadata_collection() if available (WordPress 6.7+)
	 * and if the manifest file exists. The manifest file is auto-generated
	 * during the build process.
	 *
	 * Runs on plugins_loaded to ensure registration happens before individual
	 * blocks register themselves on init.
	 *
	 * @static
	 * @since 14.1
	 * @return void
	 */
	public static function register_block_metadata_collection() {
		$meta_file_path = JETPACK__PLUGIN_DIR . '_inc/blocks/blocks-manifest.php';
		if ( function_exists( 'wp_register_block_metadata_collection' ) && file_exists( $meta_file_path ) ) {
			add_filter( 'doing_it_wrong_trigger_error', array( __CLASS__, 'bypass_block_metadata_doing_it_wrong' ), 10, 4 );

			// @phan-suppress-next-line PhanUndeclaredFunction -- New in WP 6.7. We're checking if it exists first. @phan-suppress-current-line UnusedPluginSuppression
			wp_register_block_metadata_collection(
				JETPACK__PLUGIN_DIR . '_inc/blocks/',
				$meta_file_path
			);

			remove_filter( 'doing_it_wrong_trigger_error', array( __CLASS__, 'bypass_block_metadata_doing_it_wrong' ), 10 );
		}
	}
}

if ( ( new Host() )->is_woa_site() ) {
	/**
	* Enable upgrade nudge for Atomic sites.
	 * This feature is false as default,
	 * so let's enable it through this filter.
	 *
	 * More doc: https://github.com/Automattic/jetpack/blob/trunk/projects/plugins/jetpack/extensions/README.md#upgrades-for-blocks
	 */
	add_filter( 'jetpack_block_editor_enable_upgrade_nudge', '__return_true' );
}
