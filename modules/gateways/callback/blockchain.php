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

require('./../../../dbconnect.php');
require('./../../../includes/functions.php');
require('./../../../includes/gatewayfunctions.php');
require('./../../../includes/invoicefunctions.php');

$gatewaymodule = "blockchain";

$gateway = getGatewayVariables($gatewaymodule);
if(!$gateway['type']) {
	die("Module Not Activated");
}

if($_GET['test']) {
	die("Test mode not allowed.");
}

$q = mysql_fetch_array(mysql_query("SELECT * FROM `blockchain_payments` WHERE address = '" . mysql_real_escape_string($_GET['input_address']) . "' AND secret = '" . mysql_real_escape_string($_GET['secret']) . "'"));

$invoice = mysql_fetch_array(mysql_query("SELECT * FROM `tblinvoices` WHERE id = '{$q['invoice_id']}'"));
if($invoice['status'] != 'Unpaid') {
	die('*ok*');
}

if(mysql_num_rows(mysql_query("SELECT transid FROM `tblaccounts` WHERE transid = '" . mysql_real_escape_string($_GET['input_transaction_hash']) . "'"))) {
	die('*ok*');
}

if($_GET['value'] / 100000000 != $q['amount']) {
	logTransaction($gateway['name'], $_GET, "Unsuccessful: Invalid amount received");
	die('Invalid amount');
}

if($_GET['input_address'] != $q['address']) {
	logTransaction($gateway['name'], $_GET, "Unsuccessful: Invalid input address");
	die('Invalid address');
}

if($_GET['destination_address'] != $gateway['receiving_address']) {
	logTransaction($gateway['name'], $_GET, "Unsuccessful: Invalid receiving address");
	die('Invalid receiving address');
}

$status = '';
if($_GET['confirmations'] < $gateway['confirmations_required'] && !$_GET['shared']) {
	$status = 'confirming';
} elseif(!$gateway['confirmations_required'] || $_GET['shared']) {
	$status = 'paid';
	addInvoicePayment($q['invoice_id'], $_GET['input_transaction_hash'], $invoice['total'], (($_GET['shared']) ? $invoice['total'] * 0.005 : 0), $gatewaymodule);
	logTransaction($gateway['name'], $_GET, "Successful");
	echo '*ok*';
}

mysql_query("UPDATE `blockchain_payments` SET confirmations = '" . mysql_real_escape_string($_GET['confirmations']) . "', status = '$status' WHERE invoice_id = '{$q['invoice_id']}'");
?>
