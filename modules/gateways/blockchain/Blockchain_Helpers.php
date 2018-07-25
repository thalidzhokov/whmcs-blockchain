<?php

if (!class_exists('Blockchain_DB')) {
	require_once __DIR__ . '/Blockchain_DB.php';
}

/**
 * @return bool|mysqli_result
 */
function _createTable()
{
	$DB = new Blockchain_DB();

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
 * @param int $invoiceId
 * @return array|null
 */
function _getPaymentDataByInvoiceId($invoiceId = 0)
{
	$DB = new Blockchain_DB();

	$query = 'SELECT * FROM blockchain_payments WHERE invoice_id=%s';
	$rtn = $DB->fetch_assoc($DB->mysqlQuery($query, $invoiceId));

	return $rtn;
}

/**
 * @param string $secret
 * @param string $address
 * @return array|null
 */
function _getPaymentDataBySecretAndAddress($secret = '', $address = '')
{
	$DB = new Blockchain_DB();

	$query = 'SELECT * FROM blockchain_payments WHERE secret=%s AND address=%s';
	$rtn = $DB->fetch_assoc($DB->mysqlQuery($query, $secret, $address));

	return $rtn;
}

/**
 * @param int $invoiceId
 * @return array|null
 */
function _getWHMCSInvoice($invoiceId = 0)
{
	$DB = new Blockchain_DB();

	$query = 'SELECT * FROM tblinvoices WHERE id=%s';
	$rtn = $DB->fetch_assoc($DB->mysqlQuery($query, $invoiceId));

	return $rtn;
}

/**
 * @param string $transid
 * @return array|null
 */
function _getWHMCSTransId($transid = '')
{
	$DB = new Blockchain_DB();

	$query = 'SELECT * FROM tblaccounts WHERE transid=%s';
	$rtn = $DB->fetch_assoc($DB->mysqlQuery($query, $transid));
	$rtn = !empty($rtn['transid'])
		? $rtn['transid']
		: Null;

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
	$DB = new Blockchain_DB();

	$query = 'INSERT INTO blockchain_payments SET invoice_id=%s,  amount=%s,address=%s,secret=%s';
	$rtn = $DB->mysqlQuery($query, $invoiceId, $amount, $address, $secret);

	return $rtn;
}

/**
 * @param int $invoiceId
 * @param int $confirmations
 * @param string $status
 * @return bool|mysqli_result
 */
function _setConfirmationsAndStatus($invoiceId = 0, $confirmations = 0, $status = '')
{
	$DB = new Blockchain_DB();

	$query = 'UPDATE blockchain_payments SET confirmations=%s,status=%s WHERE invoice_id=%s';
	$rtn = $DB->mysqlQuery($query, $confirmations, $status, $invoiceId);

	return $rtn;
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
		for ($i = 0; $i < 16; $i++) {
			$secret .= substr($characters, rand(0, strlen($characters) - 1), 1);
		}
	}

	return $secret;
}