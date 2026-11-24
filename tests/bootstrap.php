<?php
/**
 * Load the pure classes for PHPUnit.
 *
 * @package Chandpay
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/class-chandpay-money.php';
require dirname( __DIR__ ) . '/includes/class-chandpay-ledger.php';
require dirname( __DIR__ ) . '/includes/class-chandpay-cart.php';
require dirname( __DIR__ ) . '/includes/class-chandpay-http.php';
