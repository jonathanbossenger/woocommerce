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
	private const TRANSACTIONAL_EMAIL_LOG_SOURCE = 'transactional-emails';

	/**
	 * Message pattern marking failed sends.
	 */
	private const FAILED_PATTERN = ' failed to send';

	/**
	 * Message pattern marking non-send outcomes.
	 */
	private const NOT_SENT_PATTERN = ' not sent:';

	/**
	 * Message pattern marking disabled non-send outcomes.
	 */
	private const DISABLED_PATTERN = 'email type is disabled';

	/**
	 * Message pattern marking successful sends.
	 *
	 * Matches the EmailLogger success format:
	 * - Email "<email_type>" sent
	 * - Email "<email_type>" for <object_type> #<id> sent
	 *
	 * The optional object type segment allows namespaced class labels (backslashes),
	 * which may appear for non-order/product/user object contexts.
	 */
	private const SENT_MESSAGE_PATTERN = '/Email "[^"]+"(?: for [A-Za-z_\\\\]+ #\d+)? sent(?:\s|$)/';

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
	 * Maximum number of lines read from one log file during one detection pass.
	 */
	private const MAX_LOG_LINES_PER_FILE = 5000;

	/**
	 * Maximum number of recent orders inspected during one detection pass.
	 */
	private const MAX_RECENT_ORDERS = 500;

	/**
	 * Cache key for health detections.
	 */
	private const HEALTH_ISSUES_TRANSIENT_KEY = 'woocommerce_email_health_detector_issues';

	/**
	 * Cache duration for health detections (seconds).
	 */
	private const HEALTH_ISSUES_TRANSIENT_TTL = 300;

	/**
	 * In-request cache for the most recent detection result.
	 */
	private ?array $request_cached_issues = null;

	/**
	 * Detect suspicious transactional email gaps from recent activity.
	 *
	 * @return array[] Detection results.
	 */
	public function detect_suspicious_gaps(): array {
		if ( null !== $this->request_cached_issues ) {
			return $this->request_cached_issues;
		}

		$cached_issues = get_transient( self::HEALTH_ISSUES_TRANSIENT_KEY );
		if ( is_array( $cached_issues ) ) {
			$this->request_cached_issues = $cached_issues;
			return $cached_issues;
		}

		$window_start   = time() - self::DETECTION_WINDOW;
		$recent_events  = $this->collect_recent_email_events( $window_start );
		$recent_orders  = $this->collect_recent_order_ids( $window_start );
		$issues         = $this->build_detections( $recent_events, $recent_orders );

		/**
		 * Filter the cache TTL for email health detection results.
		 *
		 * @since 10.9.0
		 *
		 * @param int $cache_ttl Cache duration, in seconds.
		 */
		$cache_ttl = apply_filters(
			'woocommerce_email_health_detector_cache_ttl',
			self::HEALTH_ISSUES_TRANSIENT_TTL
		);

		$cache_ttl = ( ! is_numeric( $cache_ttl ) || (int) $cache_ttl < 1 )
			? self::HEALTH_ISSUES_TRANSIENT_TTL
			: (int) $cache_ttl;

		set_transient( self::HEALTH_ISSUES_TRANSIENT_KEY, $issues, $cache_ttl );
		$this->request_cached_issues = $issues;

		return $issues;
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

		$successful_customer_order_ids = $this->extract_successful_customer_order_ids( $sent_events );

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
		$events    = array();
		$log_files = \WC_Log_Handler_File::get_log_files();
		$log_dir   = trailingslashit( LoggingUtil::get_log_directory() );

		foreach ( $log_files as $filename ) {
			if ( 0 !== strpos( $filename, self::TRANSACTIONAL_EMAIL_LOG_SOURCE ) ) {
				continue;
			}

			$path = $log_dir . $filename;
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$modified_time = filemtime( $path );
			if ( false === $modified_time || $modified_time < $window_start ) {
				continue;
			}

			$file = new \SplFileObject( $path, 'r' );
			$lines_read = 0;
			while ( ! $file->eof() ) {
				if ( $lines_read >= self::MAX_LOG_LINES_PER_FILE ) {
					break;
				}

				$line = trim( (string) $file->fgets() );
				++$lines_read;
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

		if ( preg_match( '/^(\S+)\s+[A-Za-z]+\s+/', $line, $timestamp_match ) ) {
			$timestamp = strtotime( $timestamp_match[1] );
			if ( false !== $timestamp ) {
				$event['timestamp'] = (int) $timestamp;
			}
		}

		$context_marker = ' CONTEXT: ';
		$marker_offset  = strpos( $line, $context_marker );
		if ( false !== $marker_offset ) {
			$context = json_decode( substr( $line, $marker_offset + strlen( $context_marker ) ), true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $context ) ) {
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
			if ( false !== strpos( $line, self::FAILED_PATTERN ) ) {
				$event['status'] = 'failed';
			} elseif ( false !== strpos( $line, self::NOT_SENT_PATTERN ) ) {
				$event['status'] = false !== strpos( $line, self::DISABLED_PATTERN ) ? 'disabled' : 'skipped';
			} elseif ( 1 === preg_match( self::SENT_MESSAGE_PATTERN, $line ) ) {
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

		$max_recent_orders = (int) apply_filters(
			'woocommerce_email_health_detector_max_recent_orders',
			self::MAX_RECENT_ORDERS
		);
		if ( $max_recent_orders < 1 ) {
			$max_recent_orders = self::MAX_RECENT_ORDERS;
		}

		$order_ids = wc_get_orders(
			array(
				'return'       => 'ids',
				'limit'        => $max_recent_orders,
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

	/**
	 * Extract order IDs with successful customer email sends.
	 *
	 * @param array[] $sent_events Recent sent events.
	 * @return int[]
	 */
	private function extract_successful_customer_order_ids( array $sent_events ): array {
		$order_ids = array();

		foreach ( $sent_events as $event ) {
			$email_type = (string) ( $event['email_type'] ?? '' );
			$order_id   = (int) ( $event['order_id'] ?? 0 );

			if ( $order_id > 0 && $this->is_customer_email_type( $email_type ) ) {
				$order_ids[] = $order_id;
			}
		}

		return array_values(
			array_unique(
				array_map( 'intval', $order_ids )
			)
		);
	}

	/**
	 * Determine whether an email type is customer-facing.
	 *
	 * @param string $email_type Email type ID.
	 * @return bool
	 */
	private function is_customer_email_type( string $email_type ): bool {
		$customer_email_ids = $this->get_customer_email_ids();
		if ( ! empty( $customer_email_ids ) ) {
			return in_array( $email_type, $customer_email_ids, true );
		}

		return 0 === strpos( $email_type, 'customer_' );
	}

	/**
	 * Get customer-facing transactional email IDs.
	 *
	 * @return string[]
	 */
	private function get_customer_email_ids(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->mailer() ) {
			return array();
		}

		$emails = WC()->mailer()->get_emails();
		if ( ! is_array( $emails ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					function( $email ) {
						if ( ! $email instanceof \WC_Email || ! method_exists( $email, 'is_customer_email' ) || ! $email->is_customer_email() ) {
							return '';
						}

						return (string) ( $email->id ?? '' );
					},
					$emails
				)
			)
		);
	}
}
