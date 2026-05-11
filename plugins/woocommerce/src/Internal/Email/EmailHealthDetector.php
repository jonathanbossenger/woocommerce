<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Email;

use Automattic\WooCommerce\Utilities\LoggingUtil;

/**
 * Detects suspicious gaps in recent transactional email activity.
 *
 * @since 10.9.0
 * @internal
 */
class EmailHealthDetector {

	/**
	 * Log source for transactional email activity.
	 */
	private const LOG_SOURCE = 'transactional-emails';

	/**
	 * Detection window in seconds.
	 */
	private const DETECTION_WINDOW = DAY_IN_SECONDS;

	/**
	 * Minimum failed sends in the window to consider repeated failures.
	 */
	private const REPEATED_FAILURE_THRESHOLD = 3;

	/**
	 * Minimum send attempts required before evaluating failure ratio.
	 */
	private const HIGH_FAILURE_MIN_ATTEMPTS = 5;

	/**
	 * Failure ratio threshold considered suspicious.
	 */
	private const HIGH_FAILURE_RATIO = 0.5;

	/**
	 * Detect suspicious transactional email gaps from recent activity.
	 *
	 * @return array[] Detection results.
	 */
	public function detect_suspicious_gaps(): array {
		$window_start   = time() - self::DETECTION_WINDOW;
		$recent_events  = $this->collect_recent_email_events( $window_start );
		$recent_orders  = $this->collect_recent_order_ids( $window_start );

		return $this->build_detections( $recent_events, $recent_orders );
	}

	/**
	 * Build detection results from normalized events and recent order IDs.
	 *
	 * @param array[] $events           Event list with email_type, status, and optional order_id.
	 * @param int[]   $recent_order_ids Recent order IDs with store activity.
	 * @return array[]
	 */
	public function build_detections( array $events, array $recent_order_ids ): array {
		$issues = array();

		$failed_events = array_values(
			array_filter(
				$events,
				fn( array $event ) => ( $event['status'] ?? '' ) === 'failed'
			)
		);
		$sent_events   = array_values(
			array_filter(
				$events,
				fn( array $event ) => ( $event['status'] ?? '' ) === 'sent'
			)
		);

		if ( count( $failed_events ) >= self::REPEATED_FAILURE_THRESHOLD ) {
			$issues[] = array(
				'code'     => 'repeated_local_send_failures',
				'severity' => 'warning',
				'message'  => __( 'Detected repeated local transactional email send failures in the last 24 hours.', 'woocommerce' ),
				'details'  => array(
					'failed_count' => count( $failed_events ),
				),
			);
		}

		$send_attempts = count( $sent_events ) + count( $failed_events );
		if ( $send_attempts >= self::HIGH_FAILURE_MIN_ATTEMPTS ) {
			$failure_ratio = count( $failed_events ) / $send_attempts;
			if ( $failure_ratio >= self::HIGH_FAILURE_RATIO ) {
				$issues[] = array(
					'code'     => 'high_recent_failure_ratio',
					'severity' => 'warning',
					'message'  => __( 'A high share of recent transactional email send attempts failed.', 'woocommerce' ),
					'details'  => array(
						'failed_count'  => count( $failed_events ),
						'attempt_count' => $send_attempts,
					),
				);
			}
		}

		$recent_order_count = count( $recent_order_ids );
		if ( $recent_order_count > 0 && count( $sent_events ) < 1 ) {
			$issues[] = array(
				'code'     => 'no_successful_recent_sends_despite_store_activity',
				'severity' => 'warning',
				'message'  => __( 'Store activity was detected, but no successful transactional emails were recorded recently.', 'woocommerce' ),
				'details'  => array(
					'recent_order_count' => $recent_order_count,
				),
			);
		}

		$successful_customer_order_ids = array_unique(
			array_map(
				'intval',
				array_filter(
					array_map(
						function( array $event ) {
							$email_type = (string) ( $event['email_type'] ?? '' );
							$order_id   = (int) ( $event['order_id'] ?? 0 );
							if ( str_starts_with( $email_type, 'customer_' ) && $order_id > 0 ) {
								return $order_id;
							}
							return 0;
						},
						$sent_events
					)
				)
			)
		);

		$missing_customer_email_order_ids = array_values(
			array_diff(
				array_map( 'intval', $recent_order_ids ),
				$successful_customer_order_ids
			)
		);

		if ( ! empty( $missing_customer_email_order_ids ) ) {
			$issues[] = array(
				'code'     => 'recent_order_activity_missing_customer_emails',
				'severity' => 'warning',
				'message'  => __( 'Recent order activity was detected without matching successful customer emails for one or more orders.', 'woocommerce' ),
				'details'  => array(
					'recent_order_count'                 => $recent_order_count,
					'orders_missing_customer_email_count' => count( $missing_customer_email_order_ids ),
				),
			);
		}

		return $issues;
	}

	/**
	 * Collect recent normalized transactional email events from log files.
	 *
	 * @param int $window_start Unix timestamp.
	 * @return array[]
	 */
	private function collect_recent_email_events( int $window_start ): array {
		$events        = array();
		$log_files     = \WC_Log_Handler_File::get_log_files();
		$log_dir       = trailingslashit( LoggingUtil::get_log_directory() );
		$source_prefix = self::LOG_SOURCE;

		foreach ( $log_files as $filename ) {
			if ( ! str_starts_with( $filename, $source_prefix ) ) {
				continue;
			}

			$path = $log_dir . $filename;
			if ( ! is_readable( $path ) ) {
				continue;
			}
			if ( filemtime( $path ) < $window_start ) {
				continue;
			}

			$file = new \SplFileObject( $path, 'r' );
			while ( ! $file->eof() ) {
				$line = trim( (string) $file->fgets() );
				if ( '' === $line ) {
					continue;
				}

				$event = $this->parse_log_line( $line );
				if ( empty( $event ) ) {
					continue;
				}

				if ( ( $event['timestamp'] ?? 0 ) < $window_start ) {
					continue;
				}

				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Parse one transactional email log line into a normalized event.
	 *
	 * @param string $line Raw log line.
	 * @return array<string,mixed>
	 */
	private function parse_log_line( string $line ): array {
		$event = array(
			'timestamp'  => 0,
			'email_type' => '',
			'status'     => '',
			'order_id'   => 0,
		);

		if ( preg_match( '/^(\S+)\s+[A-Z]+\s+/', $line, $timestamp_match ) ) {
			$event['timestamp'] = (int) strtotime( $timestamp_match[1] );
		}

		$context_marker = ' CONTEXT: ';
		$marker_offset  = strpos( $line, $context_marker );
		if ( false !== $marker_offset ) {
			$context = json_decode( substr( $line, $marker_offset + strlen( $context_marker ) ), true );
			if ( is_array( $context ) ) {
				$event['email_type'] = isset( $context['email_type'] ) ? (string) $context['email_type'] : '';
				$event['status']     = isset( $context['status'] ) ? (string) $context['status'] : '';
				$event['order_id']   = isset( $context['order'] ) ? (int) $context['order'] : 0;
			}
		}

		if ( '' === $event['email_type'] && preg_match( '/Email "([^"]+)"/', $line, $email_match ) ) {
			$event['email_type'] = (string) $email_match[1];
		}

		if ( 0 === $event['order_id'] && preg_match( '/for order #(\d+)/', $line, $order_match ) ) {
			$event['order_id'] = (int) $order_match[1];
		}

		if ( '' === $event['status'] ) {
			if ( str_contains( $line, ' failed to send' ) ) {
				$event['status'] = 'failed';
			} elseif ( str_contains( $line, ' not sent:' ) ) {
				$event['status'] = str_contains( $line, 'email type is disabled' ) ? 'disabled' : 'skipped';
			} elseif ( str_contains( $line, ' sent' ) ) {
				$event['status'] = 'sent';
			}
		}

		return '' !== $event['status'] && '' !== $event['email_type'] ? $event : array();
	}

	/**
	 * Collect IDs of orders created recently.
	 *
	 * @param int $window_start Unix timestamp.
	 * @return int[]
	 */
	private function collect_recent_order_ids( int $window_start ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$order_ids = wc_get_orders(
			array(
				'return'       => 'ids',
				'limit'        => 200,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>' . gmdate( 'Y-m-d H:i:s', $window_start ),
			)
		);

		if ( ! is_array( $order_ids ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_map( 'intval', $order_ids )
			)
		);
	}
}
