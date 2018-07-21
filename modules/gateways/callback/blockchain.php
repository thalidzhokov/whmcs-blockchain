<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../blockchain/BlockchainDB.php';


use WHMCS\Database\Capsule;

$gatewayModule = basename(__FILE__, '.php');
$gateway = getGatewayVariables($gatewayModule);

if (!$gateway['type']) {
	die("Module Not Activated");
}

// Check address or secret
if (empty($_GET['address']) || empty($_GET['secret'])) {
	logTransaction($gateway['name'], $_GET, 'No address or secret');
	exit('No address or secret');
}

// Get payment data
$DB = new BlockchainDB();
$query = 'SELECT * FROM blockchain_payments WHERE address=%s AND secret=%s';
$q = $DB->fetch_assoc($DB->mysqlQuery($query, $_GET['address'], $_GET['secret']));
if (!$q) {
	logTransaction($gateway['name'], $_GET, 'No transaction found');
	exit('No transaction found');
}

// Check invoice status
$invoice = $DB->fetch_assoc($DB->mysqlQuery('SELECT * FROM tblinvoices WHERE id=%s', $q['invoice_id']));
if ($invoice['status'] != 'Unpaid') {
	exit('*ok*');
}

// Check transaction hash
if ($DB->fetch_assoc($DB->mysqlQuery('SELECT transid FROM tblaccounts WHERE transid=%s', $_GET['transaction_hash']))) {
	exit('*ok*');
}

// Check amount in BTC
$valueSatoshi = $_GET['value'];
$valueBTC = $valueSatoshi / 100000000;
if ($valueBTC < $q['amount']) {
	logTransaction($gateway['name'], $_GET, 'Invalid amount received');
	exit('Invalid amount');
}

// Check address
if ($_GET['address'] !== $gateway['receiving_address']) {
	logTransaction($gateway['name'], $_GET, 'Invalid receiving address');
	exit('Invalid address');
}

// Accept order
$status = 'confirming';
if (!$gateway['confirmations_required'] || $_GET['confirmations'] >= $gateway['confirmations_required']) {
	$status = 'paid';
	addInvoicePayment($q['invoice_id'], $_GET['input_transaction_hash'], $invoice['total'], 0, $gatewayModule);

	$order["orderid"] = Capsule::table('tblclients')->where('invoiceid', $q['invoice_id'])->value('id');
	$order["autosetup"] = true;
	$order["sendemail"] = true;
	$results = localAPI("acceptorder", $order, $gateway['whmcs_admin']);

	logTransaction($gateway['name'], $_GET, "Payment recieved");
	echo '*ok*';
}

// Update confirmations
$DB->mysqlQuery('UPDATE blockchain_payments SET confirmations=%s,status=%s WHERE invoice_id=%s', $_GET['confirmations'], $status, $q['invoice_id']);
