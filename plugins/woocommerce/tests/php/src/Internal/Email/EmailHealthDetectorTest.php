<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Email;

use Automattic\WooCommerce\Internal\Email\EmailHealthDetector;
use WC_Unit_Test_Case;

/**
 * Tests for EmailHealthDetector.
 */
class EmailHealthDetectorTest extends WC_Unit_Test_Case {

	/**
	 * System under test.
	 *
	 * @var EmailHealthDetector
	 */
	private EmailHealthDetector $sut;

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new EmailHealthDetector();
	}

	/**
	 * @testdox Detects repeated local send failures.
	 */
	public function test_detects_repeated_local_send_failures(): void {
		$issues = $this->sut->build_detections(
			array(
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 10 ),
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 11 ),
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 12 ),
			),
			array()
		);

		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'repeated_local_send_failures', $codes );
	}

	/**
	 * @testdox Detects missing customer email success for recent orders.
	 */
	public function test_detects_recent_order_activity_missing_customer_emails(): void {
		$issues = $this->sut->build_detections(
			array(
				array( 'status' => 'sent', 'email_type' => 'new_order', 'order_id' => 101 ),
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 102 ),
			),
			array( 101, 102, 103 )
		);

		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'recent_order_activity_missing_customer_emails', $codes );
	}

	/**
	 * @testdox Detects no successful sends despite recent order activity.
	 */
	public function test_detects_no_successful_sends_despite_store_activity(): void {
		$issues = $this->sut->build_detections(
			array(
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 21 ),
				array( 'status' => 'failed', 'email_type' => 'new_order', 'order_id' => 22 ),
			),
			array( 21, 22 )
		);

		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'no_successful_recent_sends_despite_store_activity', $codes );
	}

	/**
	 * @testdox Returns no detections for healthy recent activity.
	 */
	public function test_returns_empty_for_healthy_activity(): void {
		$issues = $this->sut->build_detections(
			array(
				array( 'status' => 'sent', 'email_type' => 'customer_processing_order', 'order_id' => 31 ),
				array( 'status' => 'sent', 'email_type' => 'customer_completed_order', 'order_id' => 32 ),
			),
			array( 31, 32 )
		);

		$this->assertSame( array(), $issues );
	}
}
