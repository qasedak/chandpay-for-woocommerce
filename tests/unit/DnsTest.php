<?php
/**
 * Fixed-IP resolve lines for the TorobPay API host.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

/**
 * DNS override matching.
 */
class DnsTest extends TestCase {

	/**
	 * Only the configured API host gets a resolve entry.
	 */
	public function test_resolve_entries_match_the_api_host() {
		$entries = Chandpay_Http::resolve_entries(
			'https://cpg.torobpay.com/api/online/v1/oauth/token',
			'cpg.torobpay.com',
			'81.12.31.29'
		);

		$this->assertSame(
			array(
				'cpg.torobpay.com:443:81.12.31.29',
				'cpg.torobpay.com:80:81.12.31.29',
			),
			$entries
		);
	}

	/**
	 * Other hosts and bad addresses are left to normal DNS.
	 */
	public function test_resolve_entries_ignore_other_hosts_and_bad_ips() {
		$this->assertSame(
			array(),
			Chandpay_Http::resolve_entries( 'https://api.mydigipay.com/digipay/api/oauth/token', 'cpg.torobpay.com', '81.12.31.29' )
		);
		$this->assertSame(
			array(),
			Chandpay_Http::resolve_entries( 'https://cpg.torobpay.com/token', 'cpg.torobpay.com', 'not-an-ip' )
		);
	}
}
