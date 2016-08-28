<?php

/*
	This file is part of Blockchain.info WHMCS Payment Gateway.
	
	Created by Alexander "Doctor McKay" Corn (http://www.doctormckay.com/)
	
	Contributions by	Ricky Burgin (http://ricky.burg.in/)
	
    Blockchain.info WHMCS Payment Gateway is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
	
    Blockchain.info WHMCS Payment Gateway is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
	
    You should have received a copy of the GNU General Public License
    along with Blockchain.info WHMCS Payment Gateway.  If not, see <http://www.gnu.org/licenses/>.
	
	WHMCS and Blockchain are not affiliated with this project in any manner.
*/

function blockchain_config() {
	return array(
		"FriendlyName" => array("Type" => "System", "Value" => "Blockchain.info"),
		"receiving_address" => array("FriendlyName" => "Bitcoin Address", "Type" => "text", "Size" => "64", "Description" => "Bitcoin address where received payments will be sent"),
		"confirmations_required" => array("FriendlyName" => "Confirmations Required", "Type" => "text", "Size" => "4", "Description" => "Number of confirmations required before an invoice is marked 'Paid'."),
	);
}

function blockchain_link($params) {
	mysql_query("CREATE TABLE IF NOT EXISTS `blockchain_payments` (`invoice_id` int(11) NOT NULL, `amount` float(11,8) NOT NULL, `address` varchar(64) NOT NULL, `secret` varchar(64) NOT NULL, `confirmations` int(11) NOT NULL, `status` enum('unpaid','confirming','paid') NOT NULL, PRIMARY KEY (`invoice_id`))");
	
	$q = mysql_fetch_array(mysql_query("SELECT * FROM `blockchain_payments` WHERE invoice_id = '{$params['invoiceid']}'"));
	if($q['address']) {
		$amount = $q['amount'];
		$address = $q['address'];
		$confirmations = $q['confirmations'];
	}
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://blockchain.info/tobtc?currency={$params['currency']}&value={$params['amount']}");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CAINFO, "./modules/gateways/blockchain/DigiCertCABundle.crt");
	$amount = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	
	if($status >= 300 || $amount < 0.0005) { // Blockchain.info will only relay a transaction if it's 0.0005 BTC or larger
		return "Transaction amount too low. Please try another payment method or open a ticket with Billing.";
	}
	
	$secret = '';
	$characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
	for($i = 0; $i < 64; $i++) {
		$secret .= substr($characters, rand(0, strlen($characters) - 1), 1);
	}
	
	$callback_url = urlencode($params['systemurl'] . "/modules/gateways/callback/blockchain.php?secret=$secret");
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://blockchain.info/api/receive?method=create&address={$params['receiving_address']}&callback=$callback_url");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CAINFO, "./modules/gateways/blockchain/DigiCertCABundle.crt");
	$response = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	
	if($status >= 300) {
		return "An error has occurred, please contact Billing or choose a different payment method. (Error ID: 1)";
	}
	
	$response = json_decode($response);
	if(!$response->input_address) {
		return "An error has occurred, please contact Billing or choose a different payment method. (Error ID: 2)";
	}
	
	mysql_query("INSERT INTO `blockchain_payments` SET invoice_id = '{$params['invoiceid']}', amount = '" . mysql_real_escape_string($amount) . "', address = '" . mysql_real_escape_string($response->input_address) . "', secret = '$secret', confirmations = '0', status = 'unpaid'");
	
	return "<iframe src='{$params['systemurl']}/modules/gateways/blockchain.php?invoice={$params['invoiceid']}' style='border:none; height:400px'>Your browser does not support frames.</iframe>";
}

if($_GET['invoice']) {
require('./../../dbconnect.php');
include("./../../includes/gatewayfunctions.php");
$gateway = getGatewayVariables('blockchain');
?>
<!doctype html>
<html>
	<head>
		<title>Blockchain.info Invoice Payment</title>
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
		<script src="blockchain/jquery.qrcode.js"></script>
                <script src="blockchain/qrcode.js"></script>
		<script type="text/javascript">
		function checkStatus() {
			$.get("blockchain.php?checkinvoice=<?php echo $_GET['invoice']; ?>", function(data) {
				if(data == 'paid') {
					parent.location.href = '<?php echo $gateway['systemurl']; ?>/viewinvoice.php?id=<?php echo $_GET['invoice']; ?>';
				} else if(data == 'unpaid') {
					setTimeout(checkStatus, 5000);
				} else {
					$("#content").html("Transaction confirming... " + data + "/<?php echo $gateway['confirmations_required']; ?> confirmations");
					setTimeout(checkStatus, 10000);
				}
			});
		}
		</script>
		<style>
		body {
			font-family:Tahoma;
			font-size:12px;
			text-align:center;
		}
		a:link, a:visited {
			color:#08c;
			text-decoration:none;
		}
		a:hover {
			color:#005580;
			text-decoration:underline
		}
		</style>
	</head>
	<body onload="checkStatus()">
		<p id="content"><center><div id="qrcodeCanvas"></div></center><br><br><?php echo blockchain_get_frame(); ?></p>
	</body>
</html>
<?php
}

function blockchain_get_frame() {
	global $gateway;
	
	$q = mysql_fetch_array(mysql_query("SELECT * FROM `blockchain_payments` WHERE invoice_id = '" . mysql_real_escape_string($_GET['invoice']) . "'"));
	if(!$q['address']) {
		return "An error has occurred, please contact Billing or choose a different payment method. (Error ID: 3)";
	}

	// QR code string for BTC wallet apps
	$qr_string = "bitcoin:{$q['address']}?amount={$q['amount']}&label=" . urlencode($gateway['companyname'] . ' Invoice #' . $q['invoice_id']);	
	
	return "<script>jQuery('#qrcodeCanvas').qrcode({ text : '{$qr_string}'});</script>Please send <b><a href='bitcoin:{$q['address']}?amount={$q['amount']}&label=" . urlencode($gateway['companyname'] . ' Invoice #' . $q['invoice_id']) . "'>{$q['amount']} BTC</a></b> to address:<br /><br /><b><a href='https://blockchain.info/address/{$q['address']}' target='_blank'>{$q['address']}</a></b><br /><br /><img src='" . $gateway['systemurl'] . "/images/loading.gif' />";
}

if($_GET['checkinvoice']) {
	header('Content-type: text/plain');
	require('./../../dbconnect.php');
	$q = mysql_fetch_array(mysql_query("SELECT * FROM `blockchain_payments` WHERE invoice_id = '" . mysql_real_escape_string($_GET['checkinvoice']) . "'"));
	
	if($q['status'] == 'paid') {
		echo 'paid';
	} elseif($q['status'] == 'confirming') {
		echo $q['confirmations'];
	} else {
		echo 'unpaid';
	}
}

?>
