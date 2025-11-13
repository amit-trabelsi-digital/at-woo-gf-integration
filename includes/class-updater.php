<?php
/**
 * Plugin Updater Class
 *
 * @package ATWooGFIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles remote plugin updates
 */
class AT_Woo_GF_Integration_Updater {

	/**
	 * Plugin slug
	 *
	 * @var string
	 */
	private $plugin_slug = 'at-woo-gf-integration';

	/**
	 * Plugin basename
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Update server URL
	 *
	 * @var string
	 */
	private $update_server;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin_basename = plugin_basename( AT_WOO_GF_INTEGRATION_FILE );
		$this->update_server = AT_WOO_GF_INTEGRATION_UPDATE_URL;

		// Hook into WordPress update system
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'purge_cache' ), 10, 2 );
	}

	/**
	 * Check for plugin updates
	 *
	 * @param object $transient Update transient data.
	 * @return object Modified transient data.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get remote version info
		$remote_info = $this->get_remote_info();

		if ( ! $remote_info ) {
			return $transient;
		}

		// Compare versions
		$current_version = AT_WOO_GF_INTEGRATION_VERSION;
		
		if ( version_compare( $current_version, $remote_info->version, '<' ) ) {
			$plugin_data = array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $remote_info->version,
				'url'         => $remote_info->homepage ?? 'https://alltech.co.il/',
				'package'     => $remote_info->download_url ?? '',
				'icons'       => array(
					'2x' => $remote_info->icon ?? '',
				),
				'tested'      => $remote_info->tested ?? '',
				'requires'    => $remote_info->requires ?? '',
				'requires_php' => $remote_info->requires_php ?? '',
			);

			$transient->response[ $this->plugin_basename ] = (object) $plugin_data;
		}

		return $transient;
	}

	/**
	 * Get plugin information for the plugins API
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string            $action The type of information being requested.
	 * @param object            $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		$remote_info = $this->get_remote_info();

		if ( ! $remote_info ) {
			return $result;
		}

		$plugin_info = array(
			'name'          => $remote_info->name ?? 'AT - WooCommerce Gravity Forms Integration',
			'slug'          => $this->plugin_slug,
			'version'       => $remote_info->version,
			'author'        => $remote_info->author ?? 'אמיר תומר - AllTech',
			'author_profile' => $remote_info->author_profile ?? 'https://alltech.co.il/',
			'homepage'      => $remote_info->homepage ?? 'https://alltech.co.il/',
			'short_description' => $remote_info->short_description ?? '',
			'sections'      => array(
				'description' => $remote_info->description ?? '',
				'changelog'   => $remote_info->changelog ?? '',
			),
			'download_link' => $remote_info->download_url ?? '',
			'tested'        => $remote_info->tested ?? '',
			'requires'      => $remote_info->requires ?? '',
			'requires_php'  => $remote_info->requires_php ?? '',
			'last_updated'  => $remote_info->last_updated ?? '',
			'banners'       => array(
				'low'  => $remote_info->banner_low ?? '',
				'high' => $remote_info->banner_high ?? '',
			),
		);

		return (object) $plugin_info;
	}

	/**
	 * Get remote plugin information
	 *
	 * @return object|false Remote plugin data or false on failure.
	 */
	private function get_remote_info() {
		$cache_key = 'at_woo_gf_integration_update_info';
		$cached = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( 
			$this->update_server . 'check-update.php?plugin=' . $this->plugin_slug . '&version=' . AT_WOO_GF_INTEGRATION_VERSION,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( empty( $data ) || ! is_object( $data ) ) {
			return false;
		}

		set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Purge cache after update
	 *
	 * @param object $upgrader_object Upgrader object.
	 * @param array  $options         Update options.
	 */
	public function purge_cache( $upgrader_object, $options ) {
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( 'at_woo_gf_integration_update_info' );
		}
	}
} 