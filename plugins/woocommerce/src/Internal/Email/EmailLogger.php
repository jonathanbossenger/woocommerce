<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Email;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Email;
use WC_Log_Levels;
use WC_Order;
use WC_Product;
use WP_Error;
use WP_User;

/**
 * Logs transactional email send attempts so store owners can inspect what WooCommerce attempted locally.
 *
 * Records are written to the WooCommerce logger under the `transactional-emails` source and include the email type,
 * related object, recipient address, and the local send state. Failure reasons are captured from wp_mail_failed.
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
	 * Holds the PHPMailer error message from the most recent failed wp_mail() call.
	 *
	 * @var string|null
	 */
	private ?string $last_mail_error = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ), 10, 1 );
		add_action( 'woocommerce_email_sent', array( $this, 'handle_woocommerce_email_sent' ), 10, 3 );
	}

	/**
	 * Capture the PHPMailer error from a failed wp_mail() call so it can be included in the log entry.
	 *
	 * Note: wp_mail_failed is a global hook; any plugin's failed wp_mail() will fire it. In the unlikely
	 * event that a non-WooCommerce wp_mail() failure fires between a WooCommerce send failure and the
	 * woocommerce_email_sent action, its error message could overwrite the WooCommerce one. The post-send
	 * reset of $last_mail_error keeps the window as narrow as possible.
	 *
	 * @param WP_Error $error The error returned by wp_mail.
	 * @return void
	 */
	public function capture_mail_error( WP_Error $error ): void {
		$this->last_mail_error = $error->get_error_message();
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
			$this->last_mail_error = null;
			return;
		}

		$object_context = $this->get_object_context( $email->object );
		$object_label   = isset( $object_context['type'], $object_context['id'] )
			? sprintf( ' for %s #%d', $object_context['type'], $object_context['id'] )
			: '';

		if ( $success ) {
			$message = sprintf( 'Email "%s"%s sent', $email_id, $object_label );
		} else {
			$reason  = $this->last_mail_error ? ': ' . $this->last_mail_error : '';
			$message = sprintf( 'Email "%s"%s failed to send%s', $email_id, $object_label, $reason );
		}

		$this->last_mail_error = null;

		$context = array(
			'source'     => self::LOG_SOURCE,
			'email_type' => $email_id,
			'status'     => $success ? 'sent' : 'failed',
			'recipient'  => $email->get_recipient(),
		);

		if ( ! empty( $object_context ) ) {
			$context[ $object_context['type'] ] = $object_context['id'] ?? null;
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
	 * Extract loggable context from the WooCommerce object attached to the email.
	 *
	 * Returns a stable short type identifier rather than the raw class name so that log aggregation
	 * is not brittle across subclasses (e.g. WC_Order_Refund still returns type 'order').
	 *
	 * @param mixed $wc_object The email's related object (WC_Order, WC_Product, WP_User, etc.) or false/null.
	 * @return array{type: string, id: int}|array{} Type and ID of the object, or empty when no object is set.
	 */
	private function get_object_context( $wc_object ): array {
		if ( ! is_object( $wc_object ) ) {
			return array();
		}

		if ( $wc_object instanceof WC_Order ) {
			$type = 'order';
		} elseif ( $wc_object instanceof WC_Product ) {
			$type = 'product';
		} elseif ( $wc_object instanceof WP_User ) {
			$type = 'user';
		} else {
			$type = get_class( $wc_object );
		}

		if ( method_exists( $wc_object, 'get_id' ) ) {
			$id = (int) $wc_object->get_id();
		} elseif ( property_exists( $wc_object, 'ID' ) ) {
			$id = (int) $wc_object->ID;
		} else {
			return array( 'type' => $type );
		}

		return array(
			'type' => $type,
			'id'   => $id,
		);
	}
}
