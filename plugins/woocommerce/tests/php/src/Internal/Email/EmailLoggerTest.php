<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Email;

use Automattic\WooCommerce\Internal\Email\EmailLogger;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use WC_Unit_Test_Case;

/**
 * Tests for the EmailLogger class.
 *
 * @covers \Automattic\WooCommerce\Internal\Email\EmailLogger
 */
class EmailLoggerTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;

	/**
	 * The System Under Test.
	 *
	 * @var EmailLogger
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new EmailLogger();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_email_log_enabled' );
		remove_all_filters( 'woocommerce_email_log_context' );
		remove_action( 'woocommerce_email_sent', array( $this->sut, 'handle_woocommerce_email_sent' ) );
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
	}

	/**
	 * @testdox Logs an info entry when email is sent successfully.
	 */
	public function test_logs_info_on_success(): void {
		$email = $this->create_mock_email( 'customer_processing_order', 'customer@example.com' );

		$this->sut->handle_woocommerce_email_sent( true, 'customer_processing_order', $email );

		$this->assertLogged( 'info', 'customer_processing_order' );
	}

	/**
	 * @testdox Logs a warning entry when email fails to send.
	 */
	public function test_logs_warning_on_failure(): void {
		$email = $this->create_mock_email( 'customer_processing_order', 'customer@example.com' );

		$this->sut->handle_woocommerce_email_sent( false, 'customer_processing_order', $email );

		$this->assertLogged( 'warning', 'customer_processing_order' );
	}

	/**
	 * @testdox Log context contains email_id, status, and recipient_hash.
	 */
	public function test_log_context_contains_required_fields(): void {
		$email = $this->create_mock_email( 'new_order', 'admin@example.com' );

		$this->sut->handle_woocommerce_email_sent( true, 'new_order', $email );

		$this->assertLogged(
			'info',
			'new_order',
			array(
				'source'   => 'transactional-emails',
				'email_id' => 'new_order',
				'status'   => 'sent',
			)
		);
	}

	/**
	 * @testdox Status is "failed" when email send was unsuccessful.
	 */
	public function test_status_is_failed_on_unsuccessful_send(): void {
		$email = $this->create_mock_email( 'customer_processing_order', 'customer@example.com' );

		$this->sut->handle_woocommerce_email_sent( false, 'customer_processing_order', $email );

		$this->assertLogged( 'warning', 'customer_processing_order', array( 'status' => 'failed' ) );
	}

	/**
	 * @testdox Recipient email address is stored as a site-salted hash.
	 */
	public function test_recipient_is_hashed(): void {
		$recipient = 'customer@example.com';
		$email     = $this->create_mock_email( 'customer_processing_order', $recipient );

		$this->sut->handle_woocommerce_email_sent( true, 'customer_processing_order', $email );

		$context = $this->captured_logs[0]['context'];

		$this->assertArrayHasKey( 'recipient_hash', $context );
		$this->assertSame(
			wp_hash( strtolower( $recipient ) ),
			$context['recipient_hash'],
			'Recipient should be stored as a site-salted hash'
		);
		$this->assertStringNotContainsString(
			$recipient,
			$context['recipient_hash'],
			'Raw recipient email should not appear in the log context'
		);
	}

	/**
	 * @testdox Empty recipient results in an empty recipient_hash.
	 */
	public function test_empty_recipient_produces_empty_hash(): void {
		$email = $this->create_mock_email( 'new_order', '' );

		$this->sut->handle_woocommerce_email_sent( true, 'new_order', $email );

		$context = $this->captured_logs[0]['context'];

		$this->assertSame( '', $context['recipient_hash'], 'Empty recipient should yield an empty recipient_hash' );
	}

	/**
	 * @testdox Object type is normalized to a stable short identifier for WC_Order.
	 */
	public function test_object_type_normalized_for_order(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 42 );
		$email = $this->create_mock_email( 'customer_processing_order', 'customer@example.com', $order );

		$this->sut->handle_woocommerce_email_sent( true, 'customer_processing_order', $email );

		$this->assertLogged(
			'info',
			'customer_processing_order',
			array(
				'object_type' => 'order',
				'object_id'   => 42,
			)
		);
	}

	/**
	 * @testdox Object type is normalized to a stable short identifier for WC_Product.
	 */
	public function test_object_type_normalized_for_product(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_id' )->willReturn( 10 );
		$email = $this->create_mock_email( 'some_product_email', 'customer@example.com', $product );

		$this->sut->handle_woocommerce_email_sent( true, 'some_product_email', $email );

		$this->assertLogged( 'info', 'some_product_email', array( 'object_type' => 'product' ) );
	}

	/**
	 * @testdox Object type is normalized to a stable short identifier for WP_User.
	 */
	public function test_object_type_normalized_for_user(): void {
		$user     = new \WP_User();
		$user->ID = 5;
		$email    = $this->create_mock_email( 'customer_new_account', 'customer@example.com', $user );

		$this->sut->handle_woocommerce_email_sent( true, 'customer_new_account', $email );

		$this->assertLogged(
			'info',
			'customer_new_account',
			array(
				'object_type' => 'user',
				'object_id'   => 5,
			)
		);
	}

	/**
	 * @testdox Object context is omitted when the email has no related object.
	 */
	public function test_object_context_omitted_when_no_object(): void {
		$email = $this->create_mock_email( 'customer_new_account', 'customer@example.com', false );

		$this->sut->handle_woocommerce_email_sent( true, 'customer_new_account', $email );

		$context = $this->captured_logs[0]['context'];

		$this->assertArrayNotHasKey( 'object_type', $context, 'Context should not contain object_type when no object is set' );
		$this->assertArrayNotHasKey( 'object_id', $context, 'Context should not contain object_id when no object is set' );
	}

	/**
	 * @testdox woocommerce_email_log_enabled filter can disable logging entirely.
	 */
	public function test_log_enabled_filter_can_disable_logging(): void {
		add_filter( 'woocommerce_email_log_enabled', '__return_false' );

		$email = $this->create_mock_email( 'customer_processing_order', 'customer@example.com' );
		$this->sut->handle_woocommerce_email_sent( true, 'customer_processing_order', $email );

		$this->assertEmpty( $this->captured_logs, 'No log entry should be written when the enabled filter returns false' );
	}

	/**
	 * @testdox woocommerce_email_log_context filter can modify context before logging.
	 */
	public function test_log_context_filter_can_modify_context(): void {
		add_filter(
			'woocommerce_email_log_context',
			function ( array $context ) {
				$context['custom_key'] = 'custom_value';
				return $context;
			}
		);

		$email = $this->create_mock_email( 'new_order', 'admin@example.com' );
		$this->sut->handle_woocommerce_email_sent( true, 'new_order', $email );

		$this->assertLogged( 'info', 'new_order', array( 'custom_key' => 'custom_value' ) );
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
}
