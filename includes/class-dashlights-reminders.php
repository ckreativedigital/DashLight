<?php
/**
 * Daily scheduled check and expiration reminder emails.
 *
 * @package DashLights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schedules the daily check and sends reminders that are due.
 */
class DashLights_Reminders {

	const HOOK = 'dashlights_daily';

	/**
	 * Days before expiration that each reminder goes out, largest first.
	 *
	 * @return array
	 */
	public static function thresholds() {
		return array(
			'domain' => array( 30, 7 ),
			'ssl'    => array( 7, 1 ),
		);
	}

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );

		// Reschedule if the event was lost (for example, after a migration).
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * On activation: first check runs on the next page load, then daily.
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * On deactivation: stop the daily check.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The daily job: run every check, then send any reminders that are due.
	 */
	public static function run() {
		$results = DashLights_Checks::refresh();

		$settings = DashLights_Settings::get();
		if ( empty( $settings['reminders'] ) ) {
			return;
		}

		$data = DashLights_Checks::data();
		foreach ( self::thresholds() as $key => $days_list ) {
			if ( empty( $results[ $key ] ) ) {
				continue;
			}
			$milestone = self::due_milestone( $key, $results[ $key ], $days_list, $data['sent'] );
			if ( null === $milestone ) {
				continue;
			}
			if ( self::send( $key, $results[ $key ] ) ) {
				$data['sent'] = self::mark_sent( $key, $results[ $key ]['expires'], $milestone, $days_list, $data['sent'] );
			}
		}
		DashLights_Checks::save( $data );
	}

	/**
	 * Which reminder, if any, is due for a result.
	 *
	 * @param string $key       Check key.
	 * @param array  $result    Check result.
	 * @param array  $days_list Thresholds in days, largest first.
	 * @param array  $sent      Sent-reminder record.
	 * @return int|string|null Threshold in days, 'expired', or null.
	 */
	public static function due_milestone( $key, $result, $days_list, $sent ) {
		if ( empty( $result['expires'] ) || ( isset( $result['reason'] ) && 'manual_passed' === $result['reason'] ) ) {
			return null;
		}

		$record  = isset( $sent[ $key ] ) && is_array( $sent[ $key ] ) ? $sent[ $key ] : array();
		$already = ( isset( $record['expires'] ) && (int) $record['expires'] === (int) $result['expires'] && isset( $record['sent'] ) ) ? (array) $record['sent'] : array();

		if ( (int) $result['expires'] <= time() ) {
			// Only the certificate gets an "expired" email, once.
			return ( 'ssl' === $key && ! in_array( 'expired', $already, true ) ) ? 'expired' : null;
		}

		$days = DashLights_Checks::days_until( $result['expires'] );
		$due  = null;
		foreach ( $days_list as $threshold ) {
			if ( $days <= $threshold ) {
				$due = $threshold; // Keeps the smallest threshold reached.
			}
		}

		return ( null !== $due && ! in_array( $due, $already, true ) ) ? $due : null;
	}

	/**
	 * Records a sent reminder. Larger thresholds count as sent too, so a later run never sends the 30-day
	 * email after the 7-day one. A new expiration date (it renewed) starts a fresh record.
	 *
	 * @param string     $key       Check key.
	 * @param int        $expires   Expiration time.
	 * @param int|string $milestone What was sent.
	 * @param array      $days_list Thresholds in days.
	 * @param array      $sent      Sent-reminder record.
	 * @return array
	 */
	public static function mark_sent( $key, $expires, $milestone, $days_list, $sent ) {
		$record = isset( $sent[ $key ] ) && is_array( $sent[ $key ] ) && isset( $sent[ $key ]['expires'] ) && (int) $sent[ $key ]['expires'] === (int) $expires
			? $sent[ $key ]
			: array(
				'expires' => (int) $expires,
				'sent'    => array(),
			);

		$marks = array( $milestone );
		if ( 'expired' === $milestone ) {
			$marks = array_merge( $marks, $days_list );
		} else {
			foreach ( $days_list as $threshold ) {
				if ( $threshold >= $milestone ) {
					$marks[] = $threshold;
				}
			}
		}

		$record['sent'] = array_values( array_unique( array_merge( (array) $record['sent'], $marks ), SORT_REGULAR ) );
		$sent[ $key ]   = $record;
		return $sent;
	}

	/**
	 * Sends one reminder email.
	 *
	 * @param string $key    'domain' or 'ssl'.
	 * @param array  $result Check result.
	 * @return bool
	 */
	public static function send( $key, $result ) {
		$site     = DashLights_Checks::site_host();
		$date     = wp_date( get_option( 'date_format' ), $result['expires'] );
		$days     = DashLights_Checks::days_until( $result['expires'] );
		$expired  = (int) $result['expires'] <= time();
		$settings = admin_url( 'options-general.php?page=' . DashLights_Settings::PAGE );

		if ( 'domain' === $key ) {
			$domain  = isset( $result['domain'] ) ? $result['domain'] : $site;
			$subject = sprintf(
				/* translators: 1: domain name, 2: number of days */
				_n( 'Your domain %1$s expires in %2$d day', 'Your domain %1$s expires in %2$d days', $days, 'dashlights' ),
				$domain,
				$days
			);
			$body = sprintf(
				/* translators: 1: domain name, 2: date */
				__( "Your domain %1\$s expires on %2\$s.\n\nIf it's set to renew automatically, you don't need to do anything. If not, renew it with the company you registered it with before that date, or your website and email will stop working.", 'dashlights' ),
				$domain,
				$date
			);
		} elseif ( $expired ) {
			/* translators: %s: site address */
			$subject = sprintf( __( 'The security certificate for %s has expired', 'dashlights' ), $site );
			/* translators: %s: site address */
			$body = sprintf( __( "The security certificate for %s has expired. Visitors may see a security warning instead of your website.\n\nContact your hosting company and ask them to renew it.", 'dashlights' ), $site );
		} else {
			$subject = sprintf(
				/* translators: 1: site address, 2: number of days */
				_n( 'The security certificate for %1$s expires in %2$d day', 'The security certificate for %1$s expires in %2$d days', $days, 'dashlights' ),
				$site,
				$days
			);
			$body = sprintf(
				/* translators: 1: site address, 2: date */
				__( "The security certificate for %1\$s expires on %2\$s.\n\nIt usually renews by itself. If it hasn't renewed by then, contact your hosting company. Without it, visitors will see a security warning instead of your website.", 'dashlights' ),
				$site,
				$date
			);
		}

		$footer = "\n\n—\n" . sprintf(
			/* translators: 1: site address, 2: settings page address */
			__( "Sent by DashLights on %1\$s. Turn these emails off here: %2\$s\nWant someone to handle this for you? https://ckreativedigital.com/website-management/", 'dashlights' ),
			$site,
			$settings
		);

		return wp_mail( DashLights_Settings::recipient(), $subject, $body . $footer );
	}
}
