<?php
/* Copyright (C) 2001-2002	Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2006-2013	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2012		Regis Houssin			<regis.houssin@capnetworks.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *     	\file       htdocs/public/payment/paymentok.php
 *		\ingroup    core
 *		\brief      File to show page after a successful payment
 *                  This page is called by payment system with url provided to it completed with parameter TOKEN=xxx
 *                  This token can be used to get more informations.
 *		\author	    Laurent Destailleur
 */

define("NOLOGIN",1);		// This means this output page does not require to be logged.
define("NOCSRFCHECK",1);	// We accept to go on this page from external web site.

// For MultiCompany module.
// Do not use GETPOST here, function is not defined and define must be done before including main.inc.php
// TODO This should be useless. Because entity must be retreive from object ref and not from url.
$entity=(! empty($_GET['entity']) ? (int) $_GET['entity'] : (! empty($_POST['entity']) ? (int) $_POST['entity'] : 1));
if (is_numeric($entity)) define("DOLENTITY", $entity);

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';

if (! empty($conf->paypal->enabled))
{
	require_once DOL_DOCUMENT_ROOT.'/paypal/lib/paypal.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/paypal/lib/paypalfunctions.lib.php';
}

$langs->load("main");
$langs->load("other");
$langs->load("dict");
$langs->load("bills");
$langs->load("companies");
$langs->load("paybox");
$langs->load("paypal");

// Clean parameters
if (! empty($conf->paypal->enabled))
{
	$PAYPAL_API_USER="";
	if (! empty($conf->global->PAYPAL_API_USER)) $PAYPAL_API_USER=$conf->global->PAYPAL_API_USER;
	$PAYPAL_API_PASSWORD="";
	if (! empty($conf->global->PAYPAL_API_PASSWORD)) $PAYPAL_API_PASSWORD=$conf->global->PAYPAL_API_PASSWORD;
	$PAYPAL_API_SIGNATURE="";
	if (! empty($conf->global->PAYPAL_API_SIGNATURE)) $PAYPAL_API_SIGNATURE=$conf->global->PAYPAL_API_SIGNATURE;
	$PAYPAL_API_SANDBOX="";
	if (! empty($conf->global->PAYPAL_API_SANDBOX)) $PAYPAL_API_SANDBOX=$conf->global->PAYPAL_API_SANDBOX;
	$PAYPAL_API_OK="";
	if ($urlok) $PAYPAL_API_OK=$urlok;
	$PAYPAL_API_KO="";
	if ($urlko) $PAYPAL_API_KO=$urlko;
	if (empty($PAYPAL_API_USER))
	{
	    dol_print_error('',"Paypal setup param PAYPAL_API_USER not defined");
	    return -1;
	}
	if (empty($PAYPAL_API_PASSWORD))
	{
	    dol_print_error('',"Paypal setup param PAYPAL_API_PASSWORD not defined");
	    return -1;
	}
	if (empty($PAYPAL_API_SIGNATURE))
	{
	    dol_print_error('',"Paypal setup param PAYPAL_API_SIGNATURE not defined");
	    return -1;
	}

    $PAYPALTOKEN=GETPOST('TOKEN');
    if (empty($PAYPALTOKEN)) $PAYPALTOKEN=GETPOST('token');
    $PAYPALPAYERID=GETPOST('PAYERID');
    if (empty($PAYPALPAYERID)) $PAYPALPAYERID=GETPOST('PayerID');
    $FULLTAG=GETPOST('FULLTAG');
    if (empty($FULLTAG)) $FULLTAG=GETPOST('fulltag');
}

$source=GETPOST('source');
$ref=GETPOST('ref');


// Detect $paymentmethod
$paymentmethod='';
if (preg_match('/PM=([^\.]+)/', $FULLTAG, $reg))
{
    $paymentmethod=$reg[1];
}
if (empty($paymentmethod))
{
    dol_print_error(null, 'The back url does not contains a parameter fulltag that should help us to find the payment method used');
    exit;
}
else
{
    dol_syslog("paymentmethod=".$paymentmethod);
}


$validpaymentmethod=array();
if (! empty($conf->paypal->enabled)) $validpaymentmethod['paypal']='paypal';
if (! empty($conf->paybox->enabled)) $validpaymentmethod['paybox']='paybox';

// Security check
if (empty($validpaymentmethod)) accessforbidden('', 0, 0, 1);


$ispaymentok = false;
// If payment is ok
$PAYMENTSTATUS=$TRANSACTIONID=$TAXAMT=$NOTE='';
// If payment is ko
$ErrorCode=$ErrorShortMsg=$ErrorLongMsg=$ErrorSeverityCode='';


$object = new stdClass();   // For triggers




/*
 * Actions
 */



/*
 * View
 */

dol_syslog("Callback url when a payment was done. query_string=".(empty($_SERVER["QUERY_STRING"])?'':$_SERVER["QUERY_STRING"])." script_uri=".(empty($_SERVER["SCRIPT_URI"])?'':$_SERVER["SCRIPT_URI"]), LOG_DEBUG, 0, '_payment');

$tracepost = "";
foreach($_POST as $k => $v) $tracepost .= "{$k} - {$v}\n";
dol_syslog("POST=".$tracepost, LOG_DEBUG, 0, '_payment');

$head='';
if (! empty($conf->global->PAYMENT_CSS_URL)) $head='<link rel="stylesheet" type="text/css" href="'.$conf->global->PAYMENT_CSS_URL.'?lang='.$langs->defaultlang.'">'."\n";

$conf->dol_hide_topmenu=1;
$conf->dol_hide_leftmenu=1;

llxHeader($head, $langs->trans("PaymentForm"), '', '', 0, 0, '', '', '', 'onlinepaymentbody');



// Show message
print '<span id="dolpaymentspan"></span>'."\n";
print '<div id="dolpaymentdiv" align="center">'."\n";


if (! empty($conf->paypal->enabled))
{
	if ($PAYPALTOKEN)
	{
	    // Get on url call
	    $token              = $PAYPALTOKEN;
	    $fulltag            = $FULLTAG;
	    $payerID            = $PAYPALPAYERID;
	    // Set by newpayment.php
	    $paymentType        = $_SESSION['PaymentType'];
	    $currencyCodeType   = $_SESSION['currencyCodeType'];
	    $FinalPaymentAmt    = $_SESSION["Payment_Amount"];
	    // From env
	    $ipaddress          = $_SESSION['ipaddress'];
	
		dol_syslog("Call paymentok with token=".$token." paymentType=".$paymentType." currencyCodeType=".$currencyCodeType." payerID=".$payerID." ipaddress=".$ipaddress." FinalPaymentAmt=".$FinalPaymentAmt." fulltag=".$fulltag, LOG_DEBUG, 0, '_paypal');
	
		// Validate record
	    if (! empty($paymentType))
	    {
	        dol_syslog("We call GetExpressCheckoutDetails", LOG_DEBUG, 0, '_payment');
	        $resArray=getDetails($token);
	        //var_dump($resarray);
	
	        dol_syslog("We call DoExpressCheckoutPayment token=".$token." paymentType=".$paymentType." currencyCodeType=".$currencyCodeType." payerID=".$payerID." ipaddress=".$ipaddress." FinalPaymentAmt=".$FinalPaymentAmt." fulltag=".$fulltag, LOG_DEBUG, 0, '_payment');
	        $resArray=confirmPayment($token, $paymentType, $currencyCodeType, $payerID, $ipaddress, $FinalPaymentAmt, $fulltag);
	
	        $ack = strtoupper($resArray["ACK"]);
	        if ($ack=="SUCCESS" || $ack=="SUCCESSWITHWARNING")
	        {
	        	$object->source		= $source;
	        	$object->ref		= $ref;
	        	$object->payerID	= $payerID;
	        	$object->fulltag	= $fulltag;
	        	$object->resArray	= $resArray;
	
	            // resArray was built from a string like that
	            // TOKEN=EC%2d1NJ057703V9359028&TIMESTAMP=2010%2d11%2d01T11%3a40%3a13Z&CORRELATIONID=1efa8c6a36bd8&ACK=Success&VERSION=56&BUILD=1553277&TRANSACTIONID=9B994597K9921420R&TRANSACTIONTYPE=expresscheckout&PAYMENTTYPE=instant&ORDERTIME=2010%2d11%2d01T11%3a40%3a12Z&AMT=155%2e57&FEEAMT=5%2e54&TAXAMT=0%2e00&CURRENCYCODE=EUR&PAYMENTSTATUS=Completed&PENDINGREASON=None&REASONCODE=None
	            $PAYMENTSTATUS=urldecode($resArray["PAYMENTSTATUS"]);   // Should contains 'Completed'
	            $TRANSACTIONID=urldecode($resArray["TRANSACTIONID"]);
	            $TAXAMT=urldecode($resArray["TAXAMT"]);
	            $NOTE=urldecode($resArray["NOTE"]);

	            $ispaymentok=True;
	        }
	        else
	        {
	            //Display a user friendly Error on the page using any of the following error information returned by PayPal
	            $ErrorCode = urldecode($resArray["L_ERRORCODE0"]);
	            $ErrorShortMsg = urldecode($resArray["L_SHORTMESSAGE0"]);
	            $ErrorLongMsg = urldecode($resArray["L_LONGMESSAGE0"]);
	            $ErrorSeverityCode = urldecode($resArray["L_SEVERITYCODE0"]);
	        }
	    }
	    else
	    {
	        dol_print_error('','Session expired');
	    }	    
	}
	else
	{
	    dol_print_error('','$PAYPALTOKEN not defined');
	}	
}



if ($ispaymentok)
{
    // Appel des triggers
    include_once DOL_DOCUMENT_ROOT . '/core/class/interfaces.class.php';
    $interface=new Interfaces($db);
    $result=$interface->run_triggers('PAYMENTONLINE_PAYMENT_OK',$object,$user,$langs,$conf);
    if ($result < 0) { $error++; $errors=$interface->errors; }
    // Fin appel triggers

    
    print $langs->trans("YourPaymentHasBeenRecorded")."<br>\n";
    print $langs->trans("ThisIsTransactionId",$TRANSACTIONID)."<br><br>\n";
    if (! empty($conf->global->PAYMENT_MESSAGE_OK)) print $conf->global->PAYMENT_MESSAGE_OK;
    
    $sendemail = '';
    if (! empty($conf->global->PAYMENTONLINE_SENDEMAIL)) $sendemail=$conf->global->PAYMENTONLINE_SENDEMAIL;
    // TODO Remove local option to keep only the generic one ?
    if ($paymentmethod == 'paypal' && ! empty($conf->global->PAYPAL_PAYONLINE_SENDEMAIL)) $sendemail=$conf->global->PAYPAL_PAYONLINE_SENDEMAIL;
    if ($paymentmethod == 'paybox' && ! empty($conf->global->PAYBOX_PAYONLINE_SENDEMAIL)) $sendemail=$conf->global->PAYBOX_PAYONLINE_SENDEMAIL;
    
	// Send an email
    if ($sendemail)
	{
		$sendto=$sendemail;
		$from=$conf->global->MAILING_EMAIL_FROM;
		// Define $urlwithroot
		$urlwithouturlroot=preg_replace('/'.preg_quote(DOL_URL_ROOT,'/').'$/i','',trim($dolibarr_main_url_root));
		$urlwithroot=$urlwithouturlroot.DOL_URL_ROOT;		// This is to use external domain name found into config file
		//$urlwithroot=DOL_MAIN_URL_ROOT;					// This is to use same domain name than current

		$urlback=$_SERVER["REQUEST_URI"];
		$topic='['.$conf->global->MAIN_APPLICATION_TITLE.'] '.$langs->transnoentitiesnoconv("NewPaypalPaymentReceived");
		$tmptag=dolExplodeIntoArray($fulltag,'.','=');
		$content="";
		if (! empty($tmptag['MEM']))
		{
			$langs->load("members");
			$url=$urlwithroot."/adherents/card_subscriptions.php?rowid=".$tmptag['MEM'];
			$content.=$langs->trans("PaymentSubscription")."<br>\n";
			$content.=$langs->trans("MemberId").': '.$tmptag['MEM']."<br>\n";
			$content.=$langs->trans("Link").': <a href="'.$url.'">'.$url.'</a>'."<br>\n";
		}
		else
		{
			$content.=$langs->transnoentitiesnoconv("NewOnlinePaymentReceived")."<br>\n";
		}
		$content.="<br>\n";
		$content.=$langs->transnoentitiesnoconv("TechnicalInformation").":<br>\n";
		$content.=$langs->transnoentitiesnoconv("PaymentSystem").': '.$paymentmethod."<br>\n";
		$content.=$langs->transnoentitiesnoconv("ReturnURLAfterPayment").': '.$urlback."<br>\n";
		$content.="tag=".$fulltag." token=".$token." paymentType=".$paymentType." currencycodeType=".$currencyCodeType." payerId=".$payerID." ipaddress=".$ipaddress." FinalPaymentAmt=".$FinalPaymentAmt;

		$ishtml=dol_textishtml($content);	// May contain urls

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$mailfile = new CMailFile($topic, $sendto, $from, $content, array(), array(), array(), '', '', 0, $ishtml);

		$result=$mailfile->sendfile();
		if ($result)
		{
			dol_syslog("EMail sent to ".$sendto, LOG_DEBUG, 0, '_payment');
		}
		else
		{
			dol_syslog("Failed to send EMail to ".$sendto, LOG_ERR, 0, '_payment');
		}
	}
}
else
{
    // Appel des triggers
    include_once DOL_DOCUMENT_ROOT . '/core/class/interfaces.class.php';
    $interface=new Interfaces($db);
    $result=$interface->run_triggers('PAYMENTONLINE_PAYMENT_KO',$object,$user,$langs,$conf);
    if ($result < 0) { $error++; $errors=$interface->errors; }
    // Fin appel triggers
    

    print $langs->trans('DoExpressCheckoutPaymentAPICallFailed') . "<br>\n";
    print $langs->trans('DetailedErrorMessage') . ": " . $ErrorLongMsg."<br>\n";
    print $langs->trans('ShortErrorMessage') . ": " . $ErrorShortMsg."<br>\n";
    print $langs->trans('ErrorCode') . ": " . $ErrorCode."<br>\n";
    print $langs->trans('ErrorSeverityCode') . ": " . $ErrorSeverityCode."<br>\n";
     
    if ($mysoc->email) print "\nPlease, send a screenshot of this page to ".$mysoc->email."<br>\n";
    
    $sendemail = '';
    if (! empty($conf->global->PAYMENTONLINE_SENDEMAIL)) $sendemail=$conf->global->PAYMENTONLINE_SENDEMAIL;
    // TODO Remove local option to keep only the generic one ?
    if ($paymentmethod == 'paypal' && ! empty($conf->global->PAYPAL_PAYONLINE_SENDEMAIL)) $sendemail=$conf->global->PAYPAL_PAYONLINE_SENDEMAIL;
    if ($paymentmethod == 'paybox' && ! empty($conf->global->PAYBOX_PAYONLINE_SENDEMAIL)) $sendemail=$conf->global->PAYBOX_PAYONLINE_SENDEMAIL;
    
    // Send an email
    if ($sendemail)
    {
        $sendto=$sendemail;
        $from=$conf->global->MAILING_EMAIL_FROM;
        // Define $urlwithroot
        $urlwithouturlroot=preg_replace('/'.preg_quote(DOL_URL_ROOT,'/').'$/i','',trim($dolibarr_main_url_root));
        $urlwithroot=$urlwithouturlroot.DOL_URL_ROOT;		// This is to use external domain name found into config file
        //$urlwithroot=DOL_MAIN_URL_ROOT;					// This is to use same domain name than current
         
        $urlback=$_SERVER["REQUEST_URI"];
        $topic='['.$conf->global->MAIN_APPLICATION_TITLE.'] '.$langs->transnoentitiesnoconv("ValidationOfPaypalPaymentFailed");
        $content="";
        $content.=$langs->transnoentitiesnoconv("PaypalConfirmPaymentPageWasCalledButFailed")."\n";
        $content.="\n";
        $content.=$langs->transnoentitiesnoconv("TechnicalInformation").":\n";
		$content.=$langs->transnoentitiesnoconv("PaymentSystem").': '.$paymentmethod."<br>\n";
        $content.=$langs->transnoentitiesnoconv("ReturnURLAfterPayment").': '.$urlback."\n";
        $content.="tag=".$fulltag."\ntoken=".$token." paymentType=".$paymentType." currencycodeType=".$currencyCodeType." payerId=".$payerID." ipaddress=".$ipaddress." FinalPaymentAmt=".$FinalPaymentAmt;
         
        $ishtml=dol_textishtml($content);	// May contain urls
         
        require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
        $mailfile = new CMailFile($topic, $sendto, $from, $content, array(), array(), array(), '', '', 0, $ishtml);
         
        $result=$mailfile->sendfile();
        if ($result)
        {
            dol_syslog("EMail sent to ".$sendto, LOG_DEBUG, 0, '_payment');
        }
        else
        {
            dol_syslog("Failed to send EMail to ".$sendto, LOG_ERR, 0, '_payment');
        }
    }
}


print "\n</div>\n";


htmlPrintOnlinePaymentFooter($mysoc,$langs);


llxFooter('', 'public');

$db->close();