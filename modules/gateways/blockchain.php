<?php

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../includes/invoicefunctions.php';
require_once __DIR__ . '/blockchain/Blockchain_DB.php';
require_once __DIR__ . '/blockchain/Blockchain_Helpers.php';

// FUNCTIONS
/**
 * @return array
 */
function blockchain_config()
{
	return array(
		"FriendlyName" => array(
			"Type" => "System",
			"Value" => "Blockchain.com"
		),
		"confirmations_required" => array(
			"FriendlyName" => "Confirmations Required",
			"Type" => "text",
			"Size" => "4",
			"Description" => "<p>Number of confirmations required before an invoice is marked \"Paid\"</p>"
		),
		"xpubkey" => array(
			"FriendlyName" => "xPub Key",
			"Type" => "text",
			"Size" => "64",
			"Description" => "<p><a href='https://login.blockchain.com'>Login</a> > Settings > Addresses > Manage > More Options > Show xPub</p>"
		),
		"v2apikey" => array(
			"FriendlyName" => "API Key",
			"Type" => "text",
			"Size" => "64",
			"Description" => "<p><a href='https://api.blockchain.info/customer/signup'>Request API Key</a></p>"),
	);
}

/**
 * @param $params
 * @return string
 */
function blockchain_link($params)
{
	$gatewayModule = basename(__FILE__, '.php');
	$gateway = getGatewayVariables($gatewayModule);

	_createTable();

	$paymentData = _getPaymentDataByInvoiceId($params['invoiceid']);

	// Get amount
	if (!empty($paymentData['amount'])) {
		$amount = $paymentData['amount'];
	} else {
		$amount = _getAmount($params['currency'], $params['amount']);
	}

	// Validate amount
	if (!is_numeric($amount)) {
		return "Can't get exchange rates. Please try another payment method or open a ticket.";
	}

	// Check amount size https://support.blockchain.com/hc/en-us/articles/210354003-What-is-the-minimum-amount-I-can-send-
	if ($amount < 0.00000547) {
		return "Transaction amount too low. Please try another payment method or open a ticket.";
	}

	// Get
	if (empty($paymentData['address'])) {
		$secret = _generateSecret($params['invoiceid'], $amount);

		if (empty($secret)) {
			return "Can't generate secret. Please try another payment method or open a ticket.";
		}

		$callbackUrl = urlencode($params['systemurl'] . "/modules/gateways/callback/blockchain.php?secret=$secret");

		$receiveUrl = "https://api.blockchain.info/v2/receive?xpub={$gateway['xpubkey']}&callback=$callbackUrl&key={$gateway['v2apikey']}";

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $receiveUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if (!in_array($status, [200, 301])) {
			return "Can't receive payment. Please try another payment method or open a ticket.";
		}

		$response = json_decode($response);

		if (!$response->address) {
			return "An error has occurred, please contact Billing or choose a different payment method.";
		}

		_setPaymentData($params['invoiceid'], $amount, $response->address, $secret);
	}

	$iframe = "<iframe src='{$params['systemurl']}/modules/gateways/blockchain.php?show={$params['invoiceid']}' style='border: none; height: 320px; width: 320px; float: right;'>Your browser does not support frames.</iframe>";

	return $iframe;
}

// SHOW INVOICE
if ($_GET['show'] && is_numeric($_GET['show'])) {
	$gatewayModule = basename(__FILE__, '.php');
	$gateway = getGatewayVariables($gatewayModule);
	$paymentData = _getPaymentDataByInvoiceId($_GET['show']);

	// QR code string for BTC wallet apps
	$qrString = "bitcoin:{$paymentData['address']}?amount={$paymentData['amount']}&label=" . urlencode($gateway['companyname'] . ' Invoice #' . $paymentData['invoice_id']); ?>
	<!DOCTYPE html>
	<html>
	<head>
		<title>Blockchain.com; Invoice #<?= $paymentData['invoice_id']; ?>;
			Amount: <?= $paymentData['amount']; ?></title>
		<script src="blockchain/jquery.min.js"></script>
		<script src="blockchain/jquery.qrcode.min.js"></script>
		<style>
			body {
				margin: 0;
				padding: 0;
				text-align: right;
			}
		</style>
	</head>
	<body>
	<div id="qr-canvas"></div>

	Please send <a id="qr-string" href="<?= $qrString; ?>"><?= $paymentData['amount']; ?> BTC</a> to address<br>
	<a href="https://www.blockchain.info/btc/address/<?= $paymentData['address']; ?>"
	   target="_blank"><?= $paymentData['address']; ?></a>

	<script type="text/javascript">
		function generateQR() {
			var $qrCanvas = $('#qr-canvas'),
				$qrString = $('#qr-string').attr('href');

			$qrCanvas.qrcode({
				text: $qrString
			});
		}

		generateQR();

		function checkStatus() {
			$.get("blockchain.php?check=<?= $paymentData['invoice_id']; ?>",
				function (data) {
					if (data == 'paid') {
						$('body').html("Invoice paid");
					} else if (data == 'unpaid') {
						setTimeout(checkStatus, 10000);
					} else {
						$('body').html("Transaction confirming... " + data + "/<?php echo $gateway['confirmations_required']; ?> confirmations");
						setTimeout(checkStatus, 10000);
					}
				});
		}

		checkStatus();
	</script>

	</body>
	</html>


	<?php
}

// CHECK INVOICE
if ($_GET['check'] && is_numeric($_GET['check'])) {
	header('Content-type: text/plain');

	$paymentData = _getPaymentDataByInvoiceId($_GET['check']);

	if ($paymentData['status'] == 'paid') {
		$status = 'paid';
	} else if ($paymentData['status'] == 'confirming') {
		$status = $paymentData['confirmations'];
	} else {
		$status = 'unpaid';
	}

	exit($status);
}

return;
