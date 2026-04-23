<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Email;

use Automattic\WooCommerce\Internal\Email\EmailLogger;
use WC_Unit_Test_Case;

/**
 * Tests for the EmailLogger class.
 */
class EmailLoggerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var EmailLogger
	 */
	private $sut;

	/**
	 * Fake logger instance for capturing log calls.
	 *
	 * @var object
	 */
	private $fake_logger;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut         = new EmailLogger();
		$this->fake_logger = $this->create_fake_logger();

		add_filter(
			'woocommerce_logging_class',
			function () {
				return $this->fake_logger;
			}
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_logging_class' );
		parent::tearDown();
	}

	/**
	 * @testdox Register method adds a hook for woocommerce_email_sent.
	 */
	public function test_register_adds_hook(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_email_sent', array( $this->sut, 'handle_woocommerce_email_sent' ) ),
			'Expected hook to be registered for woocommerce_email_sent'
		);

		remove_action( 'woocommerce_email_sent', array( $this->sut, 'handle_woocommerce_email_sent' ) );
	}

	/**
	 * @testdox Logs an info entry when email is sent successfully.
	 */
	public function test_logs_info_on_success(): void {
		$email = $this->create_mock_email( 'customer_processing_order', 'customer@example.com' );

		$this->sut->handle_woocommerce_email_sent( true, 'customer_processing_order', $email );

		$this->assertCount( 1, $this->fake_logger->info_calls, 'Expected one info log entry for a successful send' );
		$this->assertEmpty( $this->fake_logger->warning_calls, 'Expected no warning log entries for a successful send' );
	}

	/**
	 * @testdox Logs a warning entry when email fails to send.
	 */
	public function test_logs_warning_on_failure(): void {
		$email = $this->create_mock_email( 'customer_processing_order', 'customer@example.com' );

		$this->sut->handle_woocommerce_email_sent( false, 'customer_processing_order', $email );

		$this->assertCount( 1, $this->fake_logger->warning_calls, 'Expected one warning log entry for a failed send' );
		$this->assertEmpty( $this->fake_logger->info_calls, 'Expected no info log entries for a failed send' );
	}

	/**
	 * @testdox Log context contains email_id, status, trigger_time, and recipient_hash.
	 */
	public function test_log_context_contains_required_fields(): void {
		$email = $this->create_mock_email( 'new_order', 'admin@example.com' );

		$this->sut->handle_woocommerce_email_sent( true, 'new_order', $email );

		$context = $this->fake_logger->info_calls[0]['context'];

		$this->assertArrayHasKey( 'email_id', $context, 'Context should contain email_id' );
		$this->assertArrayHasKey( 'status', $context, 'Context should contain status' );
		$this->assertArrayHasKey( 'trigger_time', $context, 'Context should contain trigger_time' );
		$this->assertArrayHasKey( 'recipient_hash', $context, 'Context should contain recipient_hash' );
		$this->assertSame( 'new_order', $context['email_id'] );
		$this->assertSame( 'sent', $context['status'] );
		$this->assertSame( 'email-log', $context['source'] );
	}

	/**
	 * @testdox Status is "failed" when email send was unsuccessful.
	 */
	public function test_status_is_failed_on_unsuccessful_send(): void {
		$email = $this->create_mock_email( 'customer_processing_order', 'customer@example.com' );

		$this->sut->handle_woocommerce_email_sent( false, 'customer_processing_order', $email );

		$context = $this->fake_logger->warning_calls[0]['context'];

		$this->assertSame( 'failed', $context['status'] );
	}

	/**
	 * @testdox Recipient email address is stored as an MD5 hash.
	 */
	public function test_recipient_is_hashed(): void {
		$recipient = 'customer@example.com';
		$email     = $this->create_mock_email( 'customer_processing_order', $recipient );

		$this->sut->handle_woocommerce_email_sent( true, 'customer_processing_order', $email );

		$context = $this->fake_logger->info_calls[0]['context'];

		$this->assertSame(
			md5( strtolower( $recipient ) ),
			$context['recipient_hash'],
			'Recipient should be stored as an MD5 hash'
		);
		$this->assertStringNotContainsString(
			$recipient,
			$context['recipient_hash'],
			'Raw recipient email should not appear in the log context'
		);
	}

	/**
	 * @testdox Object type and ID are included when email has a related WC object.
	 */
	public function test_object_context_included_for_wc_order(): void {
		$order = wc_create_order();
		$email = $this->create_mock_email( 'customer_processing_order', 'customer@example.com', $order );

		$this->sut->handle_woocommerce_email_sent( true, 'customer_processing_order', $email );

		$context = $this->fake_logger->info_calls[0]['context'];

		$this->assertArrayHasKey( 'object_type', $context, 'Context should contain object_type for a WC_Order' );
		$this->assertArrayHasKey( 'object_id', $context, 'Context should contain object_id for a WC_Order' );
		$this->assertSame( (int) $order->get_id(), $context['object_id'] );
	}

	/**
	 * @testdox Object context is omitted when the email has no related object.
	 */
	public function test_object_context_omitted_when_no_object(): void {
		$email = $this->create_mock_email( 'customer_new_account', 'customer@example.com', false );

		$this->sut->handle_woocommerce_email_sent( true, 'customer_new_account', $email );

		$context = $this->fake_logger->info_calls[0]['context'];

		$this->assertArrayNotHasKey( 'object_type', $context, 'Context should not contain object_type when no object is set' );
		$this->assertArrayNotHasKey( 'object_id', $context, 'Context should not contain object_id when no object is set' );
	}

	/**
	 * @testdox Trigger time is recorded in ISO 8601 UTC format.
	 */
	public function test_trigger_time_format(): void {
		$email = $this->create_mock_email( 'new_order', 'admin@example.com' );

		$this->sut->handle_woocommerce_email_sent( true, 'new_order', $email );

		$trigger_time = $this->fake_logger->info_calls[0]['context']['trigger_time'];

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$trigger_time,
			'trigger_time should be in ISO 8601 UTC format'
		);
	}

	/**
	 * Create a mock WC_Email object for testing.
	 *
	 * @param string     $email_id  Email type ID.
	 * @param string     $recipient Recipient email address.
	 * @param mixed      $object    Related WooCommerce object or false.
	 * @return \WC_Email
	 */
	private function create_mock_email( string $email_id, string $recipient, $object = false ): \WC_Email {
		$email         = $this->getMockBuilder( \WC_Email::class )
			->disableOriginalConstructor()
			->getMock();
		$email->id     = $email_id;
		$email->object = $object;
		$email->expects( $this->any() )->method( 'get_recipient' )->willReturn( $recipient );

		return $email;
	}

	/**
	 * Create a fake logger that records all log calls.
	 *
	 * @return object Anonymous class implementing WC_Logger_Interface.
	 */
	private function create_fake_logger(): object {
		return new class() implements \WC_Logger_Interface {
			/** @var array */
			public array $debug_calls = array();
			/** @var array */
			public array $info_calls = array();
			/** @var array */
			public array $notice_calls = array();
			/** @var array */
			public array $warning_calls = array();
			/** @var array */
			public array $error_calls = array();
			/** @var array */
			public array $critical_calls = array();
			/** @var array */
			public array $alert_calls = array();
			/** @var array */
			public array $emergency_calls = array();

			public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {}
			public function debug( $message, $context = array() ) {
				$this->debug_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
			public function info( $message, $context = array() ) {
				$this->info_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
			public function notice( $message, $context = array() ) {
				$this->notice_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
			public function warning( $message, $context = array() ) {
				$this->warning_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
			public function error( $message, $context = array() ) {
				$this->error_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
			public function critical( $message, $context = array() ) {
				$this->critical_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
			public function alert( $message, $context = array() ) {
				$this->alert_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
			public function emergency( $message, $context = array() ) {
				$this->emergency_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
			public function log( $level, $message, $context = array() ) {}
		};
	}
}
