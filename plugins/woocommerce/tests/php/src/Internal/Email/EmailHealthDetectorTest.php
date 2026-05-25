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
	 * @testdox Detects high recent failure ratio at the minimum attempts threshold.
	 */
	public function test_detects_high_recent_failure_ratio_at_min_attempts_threshold(): void {
		$issues = $this->sut->build_detections(
			array(
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 301 ),
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 302 ),
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 303 ),
				array( 'status' => 'sent', 'email_type' => 'customer_processing_order', 'order_id' => 304 ),
				array( 'status' => 'sent', 'email_type' => 'customer_processing_order', 'order_id' => 305 ),
			),
			array()
		);

		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'high_recent_failure_ratio', $codes );
	}

	/**
	 * @testdox Does not detect high recent failure ratio when just under threshold.
	 */
	public function test_does_not_detect_high_recent_failure_ratio_just_under_threshold(): void {
		$issues = $this->sut->build_detections(
			array(
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 311 ),
				array( 'status' => 'failed', 'email_type' => 'customer_processing_order', 'order_id' => 312 ),
				array( 'status' => 'sent', 'email_type' => 'customer_processing_order', 'order_id' => 313 ),
				array( 'status' => 'sent', 'email_type' => 'customer_processing_order', 'order_id' => 314 ),
				array( 'status' => 'sent', 'email_type' => 'customer_processing_order', 'order_id' => 315 ),
			),
			array()
		);

		$codes = array_column( $issues, 'code' );
		$this->assertNotContains( 'high_recent_failure_ratio', $codes );
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

		$this->assertEmpty( $issues );
	}
}
