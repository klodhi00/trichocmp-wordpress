<?php
/**
 * Plugin Name:       TrichoCMP
 * Plugin URI:        https://github.com/klodhi00/trichocmp-wordpress
 * Description:       Baner zgód na cookies TrichoCMP. Wklej ID strony z panelu cookies.trichopartner.com i gotowe.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Trichopartner
 * License:           GPL-2.0-or-later
 * Text Domain:       trichocmp
 * Update URI:        https://github.com/klodhi00/trichocmp-wordpress
 */

defined( 'ABSPATH' ) || exit;

define( 'TRICHOCMP_VERSION', '1.1.0' );
define( 'TRICHOCMP_FILE', __FILE__ );
define( 'TRICHOCMP_REPO', 'klodhi00/trichocmp-wordpress' );

/** Origin serving bootstrap.js, cmp.js and the consent API. */
function trichocmp_api() {
	return untrailingslashit( apply_filters( 'trichocmp_api_origin', 'https://cookies.trichopartner.com' ) );
}

function trichocmp_site_id() {
	$id = (string) get_option( 'trichocmp_site_id', '' );
	return preg_match( '/^[a-z0-9]{12,32}$/', $id ) ? $id : '';
}

/* ------------------------------------------------------------------ front end */

/**
 * Printed before anything else in <head>: bootstrap.js must set Consent Mode defaults before GTM / gtag
 * load. Echoed directly (not enqueued) so optimisers and ?ver= query strings can't reorder or defer it.
 */
add_action(
	'wp_head',
	function () {
		$id = trichocmp_site_id();
		if ( ! $id || is_admin() || ! apply_filters( 'trichocmp_enabled', true ) ) {
			return;
		}
		$api   = trichocmp_api();
		$attrs = 'data-trichocmp-plugin="' . esc_attr( TRICHOCMP_VERSION ) . '" data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1"';
		echo "<!-- TrichoCMP -->\n";
		echo '<script src="' . esc_url( $api . '/bootstrap.js' ) . '" ' . $attrs . "></script>\n"; // phpcs:ignore WordPress.WP.EnqueuedResources
		echo '<script src="' . esc_url( $api . '/cmp.js' ) . '" data-site="' . esc_attr( $id ) . '" async ' . $attrs . "></script>\n"; // phpcs:ignore WordPress.WP.EnqueuedResources
	},
	-1000
);

/* ------------------------------------------------------------------ server-side cookie guard */

/**
 * Some plugins set tracking cookies from PHP, in the HTML response itself (e.g. "Meta pixel for WordPress" sets
 * _fbp for its Conversions API), before any script in the browser can react. Right before the headers go out,
 * such Set-Cookie headers are dropped unless the visitor's TrichoCMP decision grants the matching Consent Mode
 * signal. Rules: cookie-name regex => signal; extend with the `trichocmp_server_cookie_rules` filter.
 */
function trichocmp_server_cookie_rules() {
	return (array) apply_filters(
		'trichocmp_server_cookie_rules',
		array(
			'/^_fb[pc]$/'             => 'ad_storage',
			'/^_gcl_/'                => 'ad_storage',
			'/^(_ga|_ga_.+|_gid)$/'   => 'analytics_storage',
		)
	);
}

/** Signals granted by the stored decision (same cookie the banner writes; expired or malformed = none). */
function trichocmp_granted_signals() {
	if ( empty( $_COOKIE['tcmp_consent'] ) ) {
		return array();
	}
	$d = json_decode( wp_unslash( (string) $_COOKIE['tcmp_consent'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( ! is_array( $d ) || 1 !== ( $d['v'] ?? null ) || ! isset( $d['x'] ) || (int) $d['x'] <= time() || ! is_array( $d['m'] ?? null ) ) {
		return array();
	}
	return array_map( 'strval', $d['m'] );
}

function trichocmp_filter_set_cookie_headers() {
	$headers = headers_list();
	$granted = null;
	$keep    = array();
	$dropped = false;
	foreach ( $headers as $h ) {
		if ( 0 !== stripos( $h, 'Set-Cookie:' ) ) {
			continue;
		}
		$name = trim( strtok( substr( $h, 11 ), '=' ) );
		$need = null;
		foreach ( trichocmp_server_cookie_rules() as $re => $signal ) {
			if ( preg_match( $re, $name ) ) {
				$need = $signal;
				break;
			}
		}
		if ( null !== $need ) {
			$granted = $granted ?? trichocmp_granted_signals();
			if ( ! in_array( $need, $granted, true ) ) {
				$dropped = true;
				continue;
			}
		}
		$keep[] = $h;
	}
	if ( $dropped ) {
		header_remove( 'Set-Cookie' );
		foreach ( $keep as $h ) {
			header( $h, false );
		}
	}
}

add_action(
	'init',
	function () {
		// admin-ajax counts too: front-end plugins set cookies through it (is_admin() is true there)
		if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ! trichocmp_site_id() || ! apply_filters( 'trichocmp_enabled', true ) ) {
			return;
		}
		header_register_callback( 'trichocmp_filter_set_cookie_headers' );
	},
	0
);

/* Keep cache/optimisation plugins away from our scripts (they would delay the banner or break Consent Mode). */
function trichocmp_exclude( $list ) {
	return array_merge( (array) $list, array( wp_parse_url( trichocmp_api(), PHP_URL_HOST ) ) );
}
add_filter( 'rocket_exclude_js', 'trichocmp_exclude' );
add_filter( 'rocket_delay_js_exclusions', 'trichocmp_exclude' );
add_filter( 'rocket_exclude_defer_js', 'trichocmp_exclude' );
add_filter( 'litespeed_optimize_js_excludes', 'trichocmp_exclude' );
add_filter( 'litespeed_optm_js_defer_exc', 'trichocmp_exclude' );
add_filter( 'litespeed_optm_gm_js_exc', 'trichocmp_exclude' );
add_filter( 'sgo_javascript_combine_excluded_external_paths', 'trichocmp_exclude' );
add_filter( 'sgo_js_async_exclude', 'trichocmp_exclude' );
add_filter(
	'autoptimize_filter_js_exclude',
	function ( $csv ) {
		return trim( $csv . ', ' . wp_parse_url( trichocmp_api(), PHP_URL_HOST ), ', ' );
	}
);

/* [trichocmp_preferences]Ustawienia cookies[/trichocmp_preferences] – link reopening the banner. */
add_shortcode(
	'trichocmp_preferences',
	function ( $atts, $content = '' ) {
		$text = '' !== trim( (string) $content ) ? $content : __( 'Ustawienia cookies', 'trichocmp' );
		return '<a href="#trichocmp" class="trichocmp-preferences">' . esc_html( $text ) . '</a>';
	}
);

/* [trichocmp_declaration] – cookie table rendered by cmp.js (for the cookie policy page). */
add_shortcode(
	'trichocmp_declaration',
	function () {
		return '<div data-trichocmp-declaration></div>';
	}
);

/* ------------------------------------------------------------------ settings */

add_action(
	'admin_init',
	function () {
		register_setting(
			'trichocmp',
			'trichocmp_site_id',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => function ( $value ) {
					$value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
					if ( '' !== $value && ! preg_match( '/^[a-z0-9]{12,32}$/', $value ) ) {
						add_settings_error( 'trichocmp_site_id', 'invalid', __( 'Nieprawidłowe ID strony. Skopiuj je z panelu TrichoCMP (zakładka Instalacja).', 'trichocmp' ) );
						return get_option( 'trichocmp_site_id', '' );
					}
					return $value;
				},
			)
		);
	}
);

add_action(
	'admin_menu',
	function () {
		add_options_page( 'TrichoCMP', 'TrichoCMP', 'manage_options', 'trichocmp', 'trichocmp_settings_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=trichocmp' ) ) . '">' . esc_html__( 'Ustawienia', 'trichocmp' ) . '</a>' );
		return $links;
	}
);

/** Live state of the site's banner on the CDN: live (with version), not_published, or an error string. */
function trichocmp_health( $id ) {
	$res = wp_remote_get( trichocmp_api() . '/v1/installations/' . rawurlencode( $id ) . '/health', array( 'timeout' => 8 ) );
	if ( is_wp_error( $res ) ) {
		return array( 'status' => 'error', 'error' => $res->get_error_message() );
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	return is_array( $body ) ? $body : array( 'status' => 'error', 'error' => 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
}

function trichocmp_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$id     = trichocmp_site_id();
	$health = $id ? trichocmp_health( $id ) : null;
	?>
	<div class="wrap">
		<h1>TrichoCMP</h1>
		<p><?php esc_html_e( 'Baner zgód na cookies. Wygląd, teksty i kategorie ustawiasz w panelu TrichoCMP – tutaj wystarczy ID strony.', 'trichocmp' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'trichocmp' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="trichocmp_site_id"><?php esc_html_e( 'ID strony', 'trichocmp' ); ?></label></th>
					<td>
						<input name="trichocmp_site_id" id="trichocmp_site_id" type="text" class="regular-text code" value="<?php echo esc_attr( get_option( 'trichocmp_site_id', '' ) ); ?>" placeholder="np. 3f9a1c0b7d2e4a8f" autocomplete="off" spellcheck="false" />
						<p class="description">
							<?php
							printf(
								/* translators: %s: panel URL */
								esc_html__( 'Znajdziesz je w %s → strona → Instalacja.', 'trichocmp' ),
								'<a href="' . esc_url( trichocmp_api() ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( trichocmp_api(), PHP_URL_HOST ) ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<?php if ( $id ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'trichocmp' ); ?></th>
					<td>
						<?php if ( 'live' === ( $health['status'] ?? '' ) ) : ?>
							<span style="color:#008a20">&#10003;</span>
							<?php
							/* translators: %d: banner version number */
							printf( esc_html__( 'Połączono – baner opublikowany (wersja %d).', 'trichocmp' ), (int) $health['releaseNumber'] );
							?>
						<?php elseif ( 'not_published' === ( $health['status'] ?? '' ) ) : ?>
							<span style="color:#dba617">&#9888;</span>
							<?php esc_html_e( 'ID poprawne, ale baner nie jest jeszcze opublikowany w panelu TrichoCMP.', 'trichocmp' ); ?>
						<?php else : ?>
							<span style="color:#d63638">&#10007;</span>
							<?php
							/* translators: %s: error message */
							printf( esc_html__( 'Nie udało się sprawdzić statusu (%s).', 'trichocmp' ), esc_html( $health['error'] ?? $health['status'] ?? '?' ) );
							?>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Przydatne', 'trichocmp' ); ?></h2>
		<ul style="list-style:disc;padding-left:20px">
			<li><code>[trichocmp_preferences]Ustawienia cookies[/trichocmp_preferences]</code> – <?php esc_html_e( 'link do ponownego otwarcia banera (np. w stopce).', 'trichocmp' ); ?></li>
			<li><code>[trichocmp_declaration]</code> – <?php esc_html_e( 'tabela cookies do polityki cookies.', 'trichocmp' ); ?></li>
			<li><?php esc_html_e( 'Wyłącz inne wtyczki cookies (np. CookieScript), inaczej pokażą się dwa banery.', 'trichocmp' ); ?></li>
		</ul>
	</div>
	<?php
}

add_action(
	'admin_notices',
	function () {
		$screen = get_current_screen();
		if ( trichocmp_site_id() || ! current_user_can( 'manage_options' ) || ( $screen && 'settings_page_trichocmp' === $screen->id ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . wp_kses_post(
			sprintf(
				/* translators: %s: settings URL */
				__( 'TrichoCMP: <a href="%s">wklej ID strony</a>, żeby włączyć baner cookies.', 'trichocmp' ),
				esc_url( admin_url( 'options-general.php?page=trichocmp' ) )
			)
		) . '</p></div>';
	}
);

/* ------------------------------------------------------------------ updates from GitHub releases */

/** Latest GitHub release with a trichocmp.zip asset, cached for 6 h. */
function trichocmp_latest_release( $force = false ) {
	$cached = get_site_transient( 'trichocmp_release' );
	if ( ! $force && false !== $cached ) {
		return $cached ? $cached : null;
	}
	$res     = wp_remote_get(
		'https://api.github.com/repos/' . TRICHOCMP_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'TrichoCMP-WordPress' ),
		)
	);
	$release = null;
	if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( 'trichocmp.zip' === ( $asset['name'] ?? '' ) ) {
				$release = array(
					'version' => ltrim( (string) $data['tag_name'], 'v' ),
					'package' => (string) $asset['browser_download_url'],
					'notes'   => (string) ( $data['body'] ?? '' ),
					'date'    => (string) ( $data['published_at'] ?? '' ),
				);
			}
		}
	}
	// Failures are cached as '' for 1 h so a GitHub outage or rate limit doesn't slow every admin page.
	set_site_transient( 'trichocmp_release', $release ? $release : '', $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $release;
}

add_filter(
	'pre_set_site_transient_update_plugins',
	function ( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$release = trichocmp_latest_release();
		$file    = plugin_basename( TRICHOCMP_FILE );
		$item    = (object) array(
			'id'          => 'github.com/' . TRICHOCMP_REPO,
			'slug'        => 'trichocmp',
			'plugin'      => $file,
			'new_version' => $release ? $release['version'] : TRICHOCMP_VERSION,
			'url'         => 'https://github.com/' . TRICHOCMP_REPO,
			'package'     => $release ? $release['package'] : '',
		);
		if ( $release && version_compare( $release['version'], TRICHOCMP_VERSION, '>' ) ) {
			$transient->response[ $file ] = $item;
		} else {
			$transient->no_update[ $file ] = $item; // lets the "Enable auto-updates" toggle appear
		}
		return $transient;
	}
);

/* "View details" modal on the Plugins / Updates screen. */
add_filter(
	'plugins_api',
	function ( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'trichocmp' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = trichocmp_latest_release();
		return (object) array(
			'name'          => 'TrichoCMP',
			'slug'          => 'trichocmp',
			'version'       => $release ? $release['version'] : TRICHOCMP_VERSION,
			'author'        => 'Trichopartner',
			'homepage'      => 'https://github.com/' . TRICHOCMP_REPO,
			'download_link' => $release ? $release['package'] : '',
			'last_updated'  => $release ? $release['date'] : '',
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'sections'      => array(
				'description' => 'Baner zgód na cookies TrichoCMP.',
				'changelog'   => $release && $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '',
			),
		);
	},
	10,
	3
);

/* GitHub zips may unpack into a differently named folder; force it back to trichocmp/ so the plugin stays active. */
add_filter(
	'upgrader_source_selection',
	function ( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		if ( ( $extra['plugin'] ?? '' ) !== plugin_basename( TRICHOCMP_FILE ) || 'trichocmp' === basename( untrailingslashit( $source ) ) ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . 'trichocmp/';
		return $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ? $target : $source;
	},
	10,
	4
);

add_action(
	'upgrader_process_complete',
	function () {
		delete_site_transient( 'trichocmp_release' );
	}
);

register_uninstall_hook( __FILE__, 'trichocmp_uninstall' );
function trichocmp_uninstall() {
	delete_option( 'trichocmp_site_id' );
	delete_site_transient( 'trichocmp_release' );
}
