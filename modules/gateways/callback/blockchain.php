<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../blockchain/Blockchain_DB.php';
require_once __DIR__ . '/../blockchain/Blockchain_Helpers.php';


$gatewayModule = basename(__FILE__, '.php');
$gateway = getGatewayVariables($gatewayModule);
if (!$gateway['type']) {
	die("Module Not Activated");
}
/*

hash - The block hash.
confirmations - The number of confirmations of this block.
height - The block height.
timestamp - The unix timestamp indicating when the block was added.
size - The block size in bytes.
{custom parameter} - Any parameters included in the callback URL will be passed back to the callback URL in the notification.

 */

// Check request parameters
if (empty($_GET['secret']) ||
	empty($_GET['address']) ||
	empty($_GET['transaction_hash']) ||
	empty($_GET['value']) ||
	!isset($_GET['confirmations'])
) {
	logTransaction($gateway['name'], $_GET, 'No address or secret');
	exit('No secret, address or other data');
}

// Get payment data
$paymentData = _getPaymentDataBySecretAndAddress($_GET['secret'], $_GET['address']);

if (empty($paymentData)) {
	logTransaction($gateway['name'], $_GET, 'No transaction found');
	exit('No transaction found');
}

// Check amount in BTC
$valueSatoshi = $_GET['value'];
$valueBTC = $valueSatoshi / 100000000;

if ($valueBTC < $paymentData['amount']) {
	logTransaction($gateway['name'], $_GET, 'Invalid amount received');
	exit('Invalid amount');
}

// Check address
if ($_GET['address'] != $paymentData['address']) {
	logTransaction($gateway['name'], $_GET, 'Invalid receiving address');
	exit('Invalid address');
}

// Check invoice status
$invoice = _getWHMCSInvoice($paymentData['invoice_id']);
$invoiceStatus = !empty($invoice['status'])
	? $invoice['status']
	: False;

if ($invoiceStatus == 'Paid') {
	logTransaction($gateway['name'], $_GET, "Invoice status Paid *ok*");
	exit('*ok*');
}

// Check transaction hash
$transId = _getWHMCSTransId($_GET['transaction_hash']);

if (!empty($transId)) {
	logTransaction($gateway['name'], $_GET, "Transaction Hash *ok*");
	exit('*ok*');
}

// Accept order
$status = 'confirming';

if ($_GET['confirmations'] >= $gateway['confirmations_required']) {
	$status = 'paid';
	addInvoicePayment($paymentData['invoice_id'], $_GET['transaction_hash'], $invoice['total'], 0, $gatewayModule);
	logTransaction($gateway['name'], $_GET, "Payment recieved");
	echo '*ok*';
}

// Update confirmations
$status != 'paid' && logTransaction($gateway['name'], $_GET, "Confirming");
_setConfirmationsAndStatus($paymentData['invoice_id'], $_GET['confirmations'], $status);
