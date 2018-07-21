<?php

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../includes/invoicefunctions.php';
require_once __DIR__ . '/blockchain/BlockchainDB.php';

use WHMCS\Database\Capsule;

$gatewayModule = basename(__FILE__, '.php');
$gateway = getGatewayVariables($gatewayModule);

if (!$gateway['type']) {
	die("Module Not Activated");
}

// FUNCTIONS
/**
 * @param int $invoiceId
 * @return array|null
 */
function _getPaymentData($invoiceId = 0)
{
	$DB = new BlockchainDB();

	$query = 'SELECT * FROM blockchain_payments WHERE invoice_id=%s';
	$rtn = $DB->fetch_assoc($DB->mysqlQuery($query, $invoiceId));

	return $rtn;
}

/**
 * @param int $invoiceId
 * @param int $amount
 * @param string $address
 * @param string $secret
 * @return bool|mysqli_result
 */
function _setPaymentData($invoiceId = 0, $amount = 0, $address = '', $secret = '')
{
	$DB = new BlockchainDB();

	$query = 'INSERT INTO blockchain_payments SET invoice_id=%s,  amount=%s,address=%s,secret=%s';
	$rtn = $DB->mysqlQuery($query, $invoiceId, $amount, $address, $secret);

	return $rtn;
}

/**
 * @return bool|mysqli_result
 */
function _createTable()
{
	$DB = new BlockchainDB();

	$query = 'CREATE TABLE IF NOT EXISTS blockchain_payments (
invoice_id int(11) NOT NULL, 
amount float(11,8) NOT NULL, 
address varchar(64) NOT NULL, 
secret varchar(64) NOT NULL, 
confirmations int(11) NOT NULL DEFAULT 0, 
status enum("unpaid", "confirming", "paid") NOT NULL DEFAULT "unpaid", 
PRIMARY KEY (invoice_id)
)';
	$rtn = $DB->mysqlQuery($query);

	return $rtn;
}

/**
 * @param string $currency
 * @param int $amount
 * @return bool|mixed
 */
function _getAmount($currency = '', $amount = 0)
{
	$url = "https://www.blockchain.com/tobtc?currency={$currency}&value={$amount}";
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, True);
	$response = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if (in_array($status, [200, 301]) && is_numeric($response)) {
		return $response;
	} else {
		return False;
	}
}

/**
 * @param string $xpub
 * @param string $key
 * @return bool|mixed
 */
function _getGap($xpub = '', $key = '')
{
	$url = "https://api.blockchain.info/v2/receive/checkgap?xpub={$xpub}&key={$key}";

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$response = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if (in_array($status, [200, 301]) && is_string($response)) {
		$response = json_decode($response);

		return $response->gap;
	} else {
		return False;
	}
}

/**
 * @param int $invoiceId
 * @param int $amount
 * @return string
 */
function _generateSecret($invoiceId = 0, $amount = 0)
{
	$secret = False;

	if (!empty($invoiceId) && is_numeric($invoiceId) &&
		!empty($amount) && is_numeric($amount)
	) {
		$secret = 'I' . preg_replace('/\D/', '', $invoiceId);
		$secret .= 'A' . preg_replace('/\D/', '', $amount);
		$secret .= 'X';

		// Random add 16 characters
		$characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
		for($i = 0; $i < 16; $i++) {
			$secret .= substr($characters, rand(0, strlen($characters) - 1), 1);
		}
	}

	return $secret;
}

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
	global $gateway;

	_createTable();

	$amount = _getAmount($params['currency'], $params['amount']);

	if (!is_numeric($amount)) {
		return "Can't get exchange rates. Please try another payment method or open a ticket.";
	}

	// https://support.blockchain.com/hc/en-us/articles/210354003-What-is-the-minimum-amount-I-can-send-
	if ($amount < 0.00000547) {
		return "Transaction amount too low. Please try another payment method or open a ticket.";
	}

	$paymentData = _getPaymentData($params['invoiceid']);

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
	$paymentData = _getPaymentData($_GET['show']);

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
    <a href="https://www.blockchain.info/address/<?= $paymentData['address']; ?>"
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

	$paymentData = _getPaymentData($_GET['check']);

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
