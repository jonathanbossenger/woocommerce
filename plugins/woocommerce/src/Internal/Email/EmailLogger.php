<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Email;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Email;
use WC_Log_Levels;
use WC_Order;
use WC_Product;
use WP_User;

/**
 * Logs transactional email send attempts so store owners can inspect what WooCommerce attempted locally.
 *
 * Records are written to the WooCommerce logger under the `transactional-emails` source and include the email type,
 * related object, a hashed recipient identifier, and the local send state.
 *
 * @since 10.8.0
 * @internal
 */
class EmailLogger implements RegisterHooksInterface {

	/**
	 * Logger source used for all email log entries.
	 */
	private const LOG_SOURCE = 'transactional-emails';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_email_sent', array( $this, 'handle_woocommerce_email_sent' ), 10, 3 );
	}

	/**
	 * Handle the woocommerce_email_sent action.
	 *
	 * @param bool     $success  Whether the email was sent successfully.
	 * @param string   $email_id The email type ID (e.g. `customer_processing_order`).
	 * @param WC_Email $email    The WC_Email instance.
	 * @return void
	 */
	public function handle_woocommerce_email_sent( $success, string $email_id, WC_Email $email ): void {
		/**
		 * Filter whether to log this transactional email attempt.
		 *
		 * Return false to skip logging for a particular email or globally.
		 *
		 * @since 10.8.0
		 *
		 * @param bool     $enabled  Whether logging is enabled.
		 * @param string   $email_id The email type ID.
		 * @param WC_Email $email    The WC_Email instance.
		 */
		if ( ! apply_filters( 'woocommerce_email_log_enabled', true, $email_id, $email ) ) {
			return;
		}

		// Log message is intentionally not translated for consistency with class-wc-emails.php.
		$status  = $success ? 'sent' : 'failed';
		$message = sprintf( 'Email "%s" %s.', $email_id, $success ? 'sent' : 'failed to send' );

		$context = array(
			'source'         => self::LOG_SOURCE,
			'email_id'       => $email_id,
			'status'         => $status,
			'recipient_hash' => $this->get_recipient_hash( $email->get_recipient() ),
		);

		$object_context = $this->get_object_context( $email->object );
		if ( ! empty( $object_context ) ) {
			$context['object_type'] = $object_context['object_type'];
			if ( isset( $object_context['object_id'] ) ) {
				$context['object_id'] = $object_context['object_id'];
			}
		}

		/**
		 * Filter the context array logged for each transactional email attempt.
		 *
		 * @since 10.8.0
		 *
		 * @param array    $context  The context array to be logged.
		 * @param string   $email_id The email type ID.
		 * @param WC_Email $email    The WC_Email instance.
		 */
		$context = (array) apply_filters( 'woocommerce_email_log_context', $context, $email_id, $email );

		$level = $success ? WC_Log_Levels::INFO : WC_Log_Levels::WARNING;
		wc_get_logger()->log( $level, $message, $context );
	}

	/**
	 * Return a site-salted hash for each recipient address.
	 *
	 * Using a hash preserves the ability to correlate log entries with a known
	 * address without storing raw PII in the log.
	 *
	 * @param string $recipient Comma-separated list of email addresses.
	 * @return string Comma-separated list of hashes, one per address, or an empty string when no valid addresses are present.
	 */
	private function get_recipient_hash( string $recipient ): string {
		$emails = array_filter( array_map( 'trim', explode( ',', $recipient ) ) );
		if ( empty( $emails ) ) {
			return '';
		}
		$hashed = array_map(
			fn( string $email ) => wp_hash( strtolower( $email ) ),
			$emails
		);
		return implode( ', ', $hashed );
	}

	/**
	 * Extract loggable context from the WooCommerce object attached to the email.
	 *
	 * Returns a stable short `object_type` identifier rather than the raw class name so that
	 * log aggregation and search are not brittle across subclasses (e.g. `WC_Order_Refund`).
	 *
	 * @param mixed $wc_object The email's related object (WC_Order, WC_Product, WP_User, etc.) or false/null.
	 * @return array<string, mixed> Array with `object_type` and optionally `object_id`, or empty when no object is set.
	 */
	private function get_object_context( $wc_object ): array {
		if ( ! is_object( $wc_object ) ) {
			return array();
		}

		if ( $wc_object instanceof WC_Order ) {
			$object_type = 'order';
		} elseif ( $wc_object instanceof WC_Product ) {
			$object_type = 'product';
		} elseif ( $wc_object instanceof WP_User ) {
			$object_type = 'user';
		} else {
			$object_type = get_class( $wc_object );
		}

		$context = array( 'object_type' => $object_type );

		if ( method_exists( $wc_object, 'get_id' ) ) {
			$context['object_id'] = (int) $wc_object->get_id();
		} elseif ( property_exists( $wc_object, 'ID' ) ) {
			$context['object_id'] = (int) $wc_object->ID;
		}

		return $context;
	}
}
