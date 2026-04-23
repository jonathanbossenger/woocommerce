<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Email;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Email;

/**
 * Logs transactional email send attempts so store owners can inspect what WooCommerce attempted locally.
 *
 * Records are written to the WooCommerce logger under the `email-log` source and include the email type,
 * trigger time, related object, a hashed recipient identifier, and the local send state.
 *
 * @since 10.8.0
 * @internal
 */
class EmailLogger implements RegisterHooksInterface {

	/**
	 * Logger source used for all email log entries.
	 */
	private const LOG_SOURCE = 'email-log';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_email_sent', array( $this, 'handle_woocommerce_email_sent' ), 10, 3 );
	}

	/**
	 * Handle the woocommerce_email_sent action.
	 *
	 * @internal
	 *
	 * @param bool      $success  Whether the email was sent successfully.
	 * @param string    $email_id The email type ID (e.g. `customer_processing_order`).
	 * @param WC_Email  $email    The WC_Email instance.
	 * @return void
	 */
	public function handle_woocommerce_email_sent( $success, string $email_id, WC_Email $email ): void {
		$status  = $success ? 'sent' : 'failed';
		$message = sprintf( 'Email "%s" %s.', $email_id, $success ? 'sent' : 'failed to send' );

		$context = array(
			'source'         => self::LOG_SOURCE,
			'email_id'       => $email_id,
			'status'         => $status,
			'trigger_time'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'recipient_hash' => $this->get_recipient_hash( $email->get_recipient() ),
		);

		$object_context = $this->get_object_context( $email->object );
		if ( ! empty( $object_context ) ) {
			$context = array_merge( $context, $object_context );
		}

		$logger = wc_get_logger();
		if ( $success ) {
			$logger->info( $message, $context );
		} else {
			$logger->warning( $message, $context );
		}
	}

	/**
	 * Return an MD5 hash for each recipient address.
	 *
	 * Using a hash preserves the ability to correlate log entries with a known
	 * address without storing raw PII in the log.
	 *
	 * @param string $recipient Comma-separated list of email addresses.
	 * @return string Comma-separated list of MD5 hashes, one per address.
	 */
	private function get_recipient_hash( string $recipient ): string {
		$emails = array_filter( array_map( 'trim', explode( ',', $recipient ) ) );
		$hashed = array_map(
			fn( string $email ) => md5( strtolower( $email ) ),
			$emails
		);
		return implode( ', ', $hashed );
	}

	/**
	 * Extract loggable context from the WooCommerce object attached to the email.
	 *
	 * @param mixed $object The email's related object (WC_Order, WC_Product, WP_User, etc.) or false/null.
	 * @return array<string, mixed> Array with `object_type` and `object_id` keys, or empty array when no object is available.
	 */
	private function get_object_context( $object ): array {
		if ( ! is_object( $object ) ) {
			return array();
		}

		$object_id = null;
		if ( method_exists( $object, 'get_id' ) ) {
			$object_id = (int) $object->get_id();
		} elseif ( property_exists( $object, 'ID' ) ) {
			$object_id = (int) $object->ID;
		}

		$context = array(
			'object_type' => get_class( $object ),
		);

		if ( null !== $object_id ) {
			$context['object_id'] = $object_id;
		}

		return $context;
	}
}
