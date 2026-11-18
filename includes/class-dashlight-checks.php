<?php
/**
 * The four checks, their stored results, and the plain-English messages for each.
 *
 * @package DashLight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the checks and turns results into messages.
 */
class DashLight_Checks {

	const GOOD     = 'good';
	const WARNING  = 'warning';
	const CRITICAL = 'critical';
	const UNKNOWN  = 'unknown';

	const DATA_OPTION   = 'dashlight_data';
	const BOOTSTRAP_KEY = 'dashlight_rdap_bootstrap';
	const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

	/**
	 * Check keys in display order.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array( 'domain', 'ssl', 'updates', 'backups' );
	}

	/**
	 * Stored data: results, last run time, sent reminders.
	 *
	 * @return array
	 */
	public static function data() {
		$data = get_option( self::DATA_OPTION, array() );
		return wp_parse_args(
			is_array( $data ) ? $data : array(),
			array(
				'results' => array(),
				'checked' => 0,
				'sent'    => array(),
			)
		);
	}

	/**
	 * Saves stored data.
	 *
	 * @param array $data Data to save.
	 */
	public static function save( $data ) {
		update_option( self::DATA_OPTION, $data, false );
	}

	/**
	 * Runs some or all checks and stores the results.
	 *
	 * @param string[]|null $only Check keys to run, or null for all.
	 * @return array Results keyed by check.
	 */
	public static function refresh( $only = null ) {
		$data = self::data();
		$keys = null === $only ? self::keys() : array_intersect( self::keys(), (array) $only );

		foreach ( $keys as $key ) {
			$data['results'][ $key ] = call_user_func( array( __CLASS__, 'check_' . $key ) );
		}

		if ( null === $only ) {
			$data['checked'] = time();
		}

		self::save( $data );
		return $data['results'];
	}

	/**
	 * Worst status across the checks. Unknown results don't count against the site.
	 *
	 * @param array $results Results keyed by check.
	 * @return string
	 */
	public static function overall( $results ) {
		$rank  = array(
			self::GOOD     => 1,
			self::WARNING  => 2,
			self::CRITICAL => 3,
		);
		$worst = self::UNKNOWN;
		$level = 0;
		foreach ( $results as $result ) {
			$status = isset( $result['status'] ) ? $result['status'] : self::UNKNOWN;
			if ( isset( $rank[ $status ] ) && $rank[ $status ] > $level ) {
				$level = $rank[ $status ];
				$worst = $status;
			}
		}
		return $worst;
	}

	/**
	 * Days from now until a timestamp, rounded up, so "5 hours left" counts as 1 day. Zero or less once it has passed.
	 *
	 * @param int $timestamp Unix time.
	 * @return int
	 */
	public static function days_until( $timestamp ) {
		$seconds = (int) $timestamp - time();
		return $seconds > 0 ? (int) ceil( $seconds / DAY_IN_SECONDS ) : (int) floor( $seconds / DAY_IN_SECONDS );
	}

	/**
	 * The site's host name, without "www.".
	 *
	 * @return string
	 */
	public static function site_host() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	/**
	 * True for hosts with no public registration to look up.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	public static function is_local_host( $host ) {
		if ( '' === $host || false === strpos( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		$tld = substr( strrchr( $host, '.' ), 1 );
		return in_array( $tld, array( 'local', 'localhost', 'test', 'example', 'invalid', 'internal', 'lan', 'home' ), true );
	}

	// Domain renewal.

	/**
	 * Domain renewal check.
	 *
	 * @return array
	 */
	public static function check_domain() {
		$host = self::site_host();

		if ( self::is_local_host( $host ) ) {
			return array(
				'status' => self::UNKNOWN,
				'reason' => 'local',
			);
		}

		$lookup = self::rdap_expiration( $host );
		if ( $lookup ) {
			return self::grade_domain( $lookup['expires'], 'lookup', $lookup['domain'] );
		}

		$settings = DashLight_Settings::get();
		if ( '' !== $settings['domain_date'] ) {
			$manual = strtotime( $settings['domain_date'] . ' 23:59:59 UTC' );
			if ( $manual ) {
				return self::grade_domain( $manual, 'manual', $host );
			}
		}

		return array(
			'status' => self::UNKNOWN,
			'reason' => 'not_found',
			'domain' => $host,
		);
	}

	/**
	 * Turns an expiration date into a status.
	 *
	 * @param int    $expires Unix time.
	 * @param string $source  'lookup' or 'manual'.
	 * @param string $domain  Domain name.
	 * @return array
	 */
	protected static function grade_domain( $expires, $source, $domain ) {
		$days = self::days_until( $expires );

		if ( 'manual' === $source && $expires <= time() ) {
			// A manual date that has passed is usually just out of date, not an expired domain.
			return array(
				'status'  => self::WARNING,
				'reason'  => 'manual_passed',
				'expires' => $expires,
				'source'  => $source,
				'domain'  => $domain,
			);
		}

		if ( $days > 60 ) {
			$status = self::GOOD;
		} elseif ( $days > 14 ) {
			$status = self::WARNING;
		} else {
			$status = self::CRITICAL;
		}

		return array(
			'status'  => $status,
			'expires' => $expires,
			'source'  => $source,
			'domain'  => $domain,
		);
	}

	/**
	 * Looks up a domain's expiration date through RDAP.
	 *
	 * @param string $host Host name.
	 * @return array|null { expires: int, domain: string } or null.
	 */
	public static function rdap_expiration( $host ) {
		$labels = explode( '.', $host );
		$tld    = end( $labels );
		$base   = self::rdap_server( $tld );
		if ( ! $base ) {
			return null;
		}

		// Try the last two labels (business.com), then three (business.co.uk).
		$candidates = array( implode( '.', array_slice( $labels, -2 ) ) );
		if ( count( $labels ) >= 3 ) {
			$candidates[] = implode( '.', array_slice( $labels, -3 ) );
		}

		foreach ( $candidates as $name ) {
			$response = wp_remote_get(
				trailingslashit( $base ) . 'domain/' . rawurlencode( $name ),
				array(
					'timeout' => 10,
					'headers' => array( 'Accept' => 'application/rdap+json, application/json' ),
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $body['events'] ) || ! is_array( $body['events'] ) ) {
				continue;
			}
			foreach ( $body['events'] as $event ) {
				if ( isset( $event['eventAction'], $event['eventDate'] ) && 'expiration' === $event['eventAction'] ) {
					$time = strtotime( $event['eventDate'] );
					if ( $time ) {
						return array(
							'expires' => $time,
							'domain'  => $name,
						);
					}
				}
			}
		}

		return null;
	}

	/**
	 * Finds the registry's RDAP server for a top-level domain, using IANA's bootstrap file.
	 *
	 * @param string $tld Top-level domain, e.g. "com".
	 * @return string|null Base URL.
	 */
	public static function rdap_server( $tld ) {
		$map = get_transient( self::BOOTSTRAP_KEY );

		if ( ! is_array( $map ) ) {
			$map      = array();
			$response = wp_remote_get( self::BOOTSTRAP_URL, array( 'timeout' => 10 ) );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$json = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $json['services'] ) && is_array( $json['services'] ) ) {
					foreach ( $json['services'] as $service ) {
						if ( ! isset( $service[0], $service[1] ) || ! is_array( $service[0] ) || ! is_array( $service[1] ) ) {
							continue;
						}
						$url = '';
						foreach ( $service[1] as $candidate ) {
							if ( 0 === strpos( $candidate, 'https://' ) ) {
								$url = $candidate;
								break;
							}
						}
						if ( '' === $url ) {
							continue;
						}
						foreach ( $service[0] as $suffix ) {
							$map[ strtolower( $suffix ) ] = $url;
						}
					}
				}
			}
			// Cache a good file for 30 days; retry a failed download in an hour.
			set_transient( self::BOOTSTRAP_KEY, $map, $map ? 30 * DAY_IN_SECONDS : HOUR_IN_SECONDS );
		}

		$tld = strtolower( $tld );
		return isset( $map[ $tld ] ) ? $map[ $tld ] : null;
	}

	// Security certificate.

	/**
	 * Security certificate check.
	 *
	 * @return array
	 */
	public static function check_ssl() {
		$url  = home_url();
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return array(
				'status' => self::CRITICAL,
				'reason' => 'no_https',
			);
		}

		if ( '' === $host || ! function_exists( 'openssl_x509_parse' ) || ! function_exists( 'stream_socket_client' ) ) {
			return array(
				'status' => self::UNKNOWN,
				'reason' => 'unreadable',
			);
		}

		$port    = wp_parse_url( $url, PHP_URL_PORT );
		$port    = $port ? (int) $port : 443;
		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					// Read the certificate even if it's expired, so we can report that.
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);

		$errno  = 0;
		$errstr = '';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a blocked connection is an expected outcome, reported as "couldn't check".
		$client = @stream_socket_client( 'ssl://' . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context );
		if ( ! $client ) {
			return array(
				'status' => self::UNKNOWN,
				'reason' => 'unreadable',
			);
		}

		$params = stream_context_get_params( $client );
		fclose( $client ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing a network socket, not a file.

		$cert = isset( $params['options']['ssl']['peer_certificate'] ) ? $params['options']['ssl']['peer_certificate'] : null;
		$info = $cert ? openssl_x509_parse( $cert ) : false;
		if ( ! $info || empty( $info['validTo_time_t'] ) ) {
			return array(
				'status' => self::UNKNOWN,
				'reason' => 'unreadable',
			);
		}

		$expires = (int) $info['validTo_time_t'];
		$days    = self::days_until( $expires );

		if ( $days > 14 ) {
			$status = self::GOOD;
		} elseif ( $days > 3 ) {
			$status = self::WARNING;
		} else {
			$status = self::CRITICAL;
		}

		return array(
			'status'  => $status,
			'expires' => $expires,
		);
	}

	// Updates.

	/**
	 * Waiting updates check. Reads WordPress's own update data directly, so it also works during the daily
	 * scheduled check, when no one is logged in.
	 *
	 * @return array
	 */
	public static function check_updates() {
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );
		$core    = get_site_transient( 'update_core' );

		$plugin_count = ( is_object( $plugins ) && ! empty( $plugins->response ) && is_array( $plugins->response ) ) ? count( $plugins->response ) : 0;
		$theme_count  = ( is_object( $themes ) && ! empty( $themes->response ) && is_array( $themes->response ) ) ? count( $themes->response ) : 0;

		$core_waiting = false;
		if ( is_object( $core ) && ! empty( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $update ) {
				if ( isset( $update->response ) && 'upgrade' === $update->response ) {
					$core_waiting = true;
					break;
				}
			}
		}

		$total = $plugin_count + $theme_count;
		if ( $core_waiting || $total >= 5 ) {
			$status = self::CRITICAL;
		} elseif ( $total > 0 ) {
			$status = self::WARNING;
		} else {
			$status = self::GOOD;
		}

		return array(
			'status'  => $status,
			'plugins' => $plugin_count,
			'themes'  => $theme_count,
			'core'    => $core_waiting,
		);
	}

	// Backups.

	/**
	 * Most recent backup a known backup plugin reports.
	 *
	 * @return array
	 */
	public static function check_backups() {
		$found = array();

		// UpdraftPlus stores its last backup in this option. Verify against the current UpdraftPlus release.
		$updraft = get_option( 'updraft_last_backup' );
		if ( is_array( $updraft ) && ! empty( $updraft['backup_time'] ) ) {
			$found[] = array(
				'time'   => (int) $updraft['backup_time'],
				'source' => 'UpdraftPlus',
			);
		}

		/**
		 * Lets other code report a backup DashLight can't see on its own.
		 *
		 * @param array $found List of [ 'time' => int, 'source' => string ].
		 */
		$found = apply_filters( 'dashlight_backups', $found );

		$latest = null;
		foreach ( (array) $found as $backup ) {
			if ( isset( $backup['time'], $backup['source'] ) && ( null === $latest || (int) $backup['time'] > $latest['time'] ) ) {
				$latest = array(
					'time'   => (int) $backup['time'],
					'source' => sanitize_text_field( $backup['source'] ),
				);
			}
		}

		if ( ! $latest ) {
			return array(
				'status' => self::UNKNOWN,
				'reason' => 'none',
			);
		}

		$age = (int) floor( ( time() - $latest['time'] ) / DAY_IN_SECONDS );
		if ( $age <= 7 ) {
			$status = self::GOOD;
		} elseif ( $age <= 30 ) {
			$status = self::WARNING;
		} else {
			$status = self::CRITICAL;
		}

		return array(
			'status' => $status,
			'time'   => $latest['time'],
			'source' => $latest['source'],
		);
	}

	// Messages.

	/**
	 * Label for a check.
	 *
	 * @param string $key Check key.
	 * @return string
	 */
	public static function label( $key ) {
		$labels = array(
			'domain'  => __( 'Domain', 'dashlight' ),
			'ssl'     => __( 'Certificate', 'dashlight' ),
			'updates' => __( 'Updates', 'dashlight' ),
			'backups' => __( 'Backups', 'dashlight' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * One-line summary for the overall status.
	 *
	 * @param string $status Overall status.
	 * @return string
	 */
	public static function summary( $status ) {
		switch ( $status ) {
			case self::GOOD:
				return __( 'Everything looks good.', 'dashlight' );
			case self::WARNING:
				return __( 'A few things need attention soon.', 'dashlight' );
			case self::CRITICAL:
				return __( 'Something needs attention now.', 'dashlight' );
			default:
				return __( "We couldn't check everything yet.", 'dashlight' );
		}
	}

	/**
	 * Formats a date in the site's date format.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	protected static function format_date( $timestamp ) {
		return wp_date( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Message, and an optional link, for one result.
	 *
	 * @param string $key    Check key.
	 * @param array  $result Result.
	 * @return array { text: string, link: string, link_text: string }
	 */
	public static function message( $key, $result ) {
		$status   = isset( $result['status'] ) ? $result['status'] : self::UNKNOWN;
		$reason   = isset( $result['reason'] ) ? $result['reason'] : '';
		$settings = admin_url( 'options-general.php?page=' . DashLight_Settings::PAGE );
		$out      = array(
			'text'      => '',
			'link'      => '',
			'link_text' => '',
		);

		switch ( $key ) {
			case 'domain':
				if ( 'local' === $reason ) {
					$out['text'] = __( "This site isn't on a public domain yet, so there's nothing to look up.", 'dashlight' );
				} elseif ( self::UNKNOWN === $status ) {
					$out['text']      = __( "We couldn't find your domain's renewal date.", 'dashlight' );
					$out['link']      = $settings;
					$out['link_text'] = __( 'Add it in settings', 'dashlight' );
				} elseif ( 'manual_passed' === $reason ) {
					/* translators: %s: date */
					$out['text']      = sprintf( __( 'The renewal date you entered (%s) has passed. If you renewed, update it.', 'dashlight' ), self::format_date( $result['expires'] ) );
					$out['link']      = $settings;
					$out['link_text'] = __( 'Update the date', 'dashlight' );
				} else {
					$days = self::days_until( $result['expires'] );
					if ( self::GOOD === $status ) {
						/* translators: %s: date */
						$out['text'] = sprintf( __( 'Your domain renews on %s.', 'dashlight' ), self::format_date( $result['expires'] ) );
					} elseif ( (int) $result['expires'] <= time() ) {
						$out['text'] = __( 'Your domain has expired. Renew it right away, or your website and email will stop working.', 'dashlight' );
					} else {
						$out['text'] = sprintf(
							/* translators: %d: number of days */
							_n( "Your domain expires in %d day. If it's set to renew automatically, you're covered.", "Your domain expires in %d days. If it's set to renew automatically, you're covered.", $days, 'dashlight' ),
							$days
						);
					}
				}
				break;

			case 'ssl':
				if ( 'no_https' === $reason ) {
					$out['text'] = __( "Your site isn't using a secure connection. Ask your host to turn on https.", 'dashlight' );
				} elseif ( self::UNKNOWN === $status ) {
					$out['text'] = __( "We couldn't read your security certificate. Your host may block this check.", 'dashlight' );
				} else {
					$days = self::days_until( $result['expires'] );
					if ( self::GOOD === $status ) {
						/* translators: %s: date */
						$out['text'] = sprintf( __( 'Your security certificate is good until %s.', 'dashlight' ), self::format_date( $result['expires'] ) );
					} elseif ( (int) $result['expires'] <= time() ) {
						$out['text'] = __( 'Your security certificate has expired, so visitors may see a warning. Contact your host.', 'dashlight' );
					} else {
						$out['text'] = sprintf(
							/* translators: %d: number of days */
							_n( "Your security certificate expires in %d day. It usually renews by itself. If it doesn't, contact your host.", "Your security certificate expires in %d days. It usually renews by itself. If it doesn't, contact your host.", $days, 'dashlight' ),
							$days
						);
					}
				}
				break;

			case 'updates':
				if ( self::GOOD === $status ) {
					$out['text'] = __( 'Everything is up to date.', 'dashlight' );
					break;
				}
				$parts = array();
				if ( ! empty( $result['core'] ) ) {
					$parts[] = __( 'WordPress', 'dashlight' );
				}
				if ( ! empty( $result['plugins'] ) ) {
					/* translators: %d: number of plugins */
					$parts[] = sprintf( _n( '%d plugin', '%d plugins', $result['plugins'], 'dashlight' ), $result['plugins'] );
				}
				if ( ! empty( $result['themes'] ) ) {
					/* translators: %d: number of themes */
					$parts[] = sprintf( _n( '%d theme', '%d themes', $result['themes'], 'dashlight' ), $result['themes'] );
				}
				/* translators: %s: list of what needs updating, e.g. "WordPress, 3 plugins" */
				$out['text'] = sprintf( __( 'Updates waiting: %s.', 'dashlight' ), implode( ', ', $parts ) );
				if ( current_user_can( 'update_plugins' ) ) {
					$out['link']      = admin_url( 'update-core.php' );
					$out['link_text'] = __( 'Update now', 'dashlight' );
				}
				break;

			case 'backups':
				if ( self::UNKNOWN === $status ) {
					$out['text'] = __( "We can't see a backup plugin. Your host may back up your site, so check with them.", 'dashlight' );
				} else {
					$out['text'] = sprintf(
						/* translators: 1: time since the backup, e.g. "2 days", 2: backup plugin name */
						__( 'Last backup: %1$s ago, by %2$s.', 'dashlight' ),
						human_time_diff( $result['time'] ),
						$result['source']
					);
				}
				break;
		}

		return $out;
	}
}
