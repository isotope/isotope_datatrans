<?php if (!defined('TL_ROOT')) die('You cannot access this file directly!');

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2011 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  Isotope eCommerce Workgroup 2009-2011
 * @author     Andreas Schempp <andreas@schempp.ch>
 * @author     Leo Unglaub <leo@leo-unglaub.net>
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 * @version    $Id: $
 */


class PaymentDatatrans extends IsotopePayment
{

	/**
	 * Return a list of status options.
	 * @return array
	 */
	public function statusOptions()
	{
		return array('pending', 'processing', 'complete', 'on_hold');
	}


	/**
	 * Perform server to server data check
	 */
	public function processPostSale()
	{
		// Verify payment status
		if ($this->Input->post('status') != 'success')
		{
			$this->log('Payment for order ID "' . $this->Input->post('refno') . '" failed. -> '. $this->Input->post('status') .': '.$this->Input->post('errorMessage')."\n\nPOST: " . print_r($_POST, true), __METHOD__, TL_ERROR);
			return false;
		}
		
		$objOrder = new IsotopeOrder();

		if (!$objOrder->findBy('id', $this->Input->post('refno')))
		{
			$this->log('Order ID "' . $this->Input->post('refno') . '" not found', __METHOD__, TL_ERROR);
			return false;
		}
		
		//>@PL changed key generation
		// Validate HMAC sign
		//if ($this->Input->post('sign2') != hash_hmac('md5', $this->datatrans_id.$this->Input->post('amount').$this->Input->post('currency').$this->Input->post('uppTransactionId'), $this->datatrans_sign))
		$str = $this->datatrans_id.$this->Input->post('amount').$this->Input->post('currency').$this->Input->post('uppTransactionId');
		$key = $this->hexstr($this->datatrans_sign);
		$h = $this->hmac($key, $str);
		if ($this->Input->post('sign2') != $h )
		{
			$this->log('Invalid HMAC signature for Order ID ' . $this->Input->post('refno'), __METHOD__, TL_ERROR);
			return false;
		}

		// For maximum security, also validate individual parameters
		if (!$this->validateParameters(array
		(
			'refno'		=> $objOrder->id,
			'currency'	=> $objOrder->currency,
			'amount'	=> round($objOrder->grandTotal * 100),
			'reqtype'	=> ($this->trans_type == 'auth' ? 'NOA' : 'CAA'),
		)))
		{
			return false;
		}
		
		$objOrder->checkout();
		$objOrder->date_payed = time();
		$objOrder->save();
	}


	/**
	 * Validate post parameters and complete order
	 * @return bool
	 */
	public function processPayment()
	{
		$objOrder = new IsotopeOrder();
		if (!$objOrder->findBy('cart_id', $this->Isotope->Cart->id))
		{
			return false;
		}

		if ($objOrder->date_payed > 0 && $objOrder->date_payed <= time())
		{
			unset($_SESSION['PAYMENT_TIMEOUT']);
			return true;
		}

		if (!isset($_SESSION['PAYMENT_TIMEOUT']))
		{
			$_SESSION['PAYMENT_TIMEOUT'] = 60;
		}
		else
		{
			$_SESSION['PAYMENT_TIMEOUT'] = $_SESSION['PAYMENT_TIMEOUT'] - 5;
		}

		if ($_SESSION['PAYMENT_TIMEOUT'] === 0)
		{
			global $objPage;
			$this->log('Payment could not be processed.', __METHOD__, TL_ERROR);
			$this->redirect($this->generateFrontendUrl($objPage->row(), '/step/failed'));
		}

		// Reload page every 5 seconds and check if payment was successful
		$GLOBALS['TL_HEAD'][] = '<meta http-equiv="refresh" content="5,' . $this->Environment->base . $this->Environment->request . '">';

		$objTemplate = new FrontendTemplate('mod_message');
		$objTemplate->type = 'processing';
		$objTemplate->message = $GLOBALS['TL_LANG']['MSC']['payment_processing'];
		return $objTemplate->parse();
	}


	/**
	 * Generate the submit form for datatrans and if javascript is enabled redirect automaticly
	 * @return string
	 */
	public function checkoutForm()
	{
		$objOrder = new IsotopeOrder();

		if (!$objOrder->findBy('cart_id', $this->Isotope->Cart->id))
		{
			$this->redirect($this->addToUrl('step=failed', true));
		}
		
		$arrAddress = $this->Isotope->Cart->billing_address;

		$arrParams = array
		(
			'merchantId'			=> $this->datatrans_id,
			'amount'				=> round($this->Isotope->Cart->grandTotal * 100),
			'currency'				=> $this->Isotope->Config->currency,
			'refno'					=> $objOrder->id,
			'language'				=> $GLOBALS['TL_LANGUAGE'],
			'reqtype'				=> ($this->trans_type == 'auth' ? 'NOA' : 'CAA'),
			'uppCustomerDetails'	=> 'yes',
			'uppCustomerTitle'		=> $arrAddress['salutation'],
			'uppCustomerFirstName'	=> $arrAddress['firstname'],
			'uppCustomerLastName'	=> $arrAddress['lastname'],
			'uppCustomerStreet'		=> $arrAddress['street_1'],
			'uppCustomerStreet2'	=> $arrAddress['street_2'],
			'uppCustomerCity'		=> $arrAddress['city'],
			'uppCustomerCountry'	=> $arrAddress['country'],
			'uppCustomerZipCode'	=> $arrAddress['postal'],
			'uppCustomerPhone'		=> $arrAddress['phone'],
			'uppCustomerEmail'		=> $arrAddress['email'],
			'successUrl'			=> ampersand($this->Environment->base . $this->addToUrl('step=complete', true) . '?uid=' . $objOrder->uniqid),
			'errorUrl'				=> ampersand($this->Environment->base . $this->addToUrl('step=failed', true)),
			'cancelUrl'				=> ampersand($this->Environment->base . $this->addToUrl('step=failed', true)),
			'mod'					=> 'pay',
			'id'					=> $this->id,
		);
		
		//>@PL changed key generation
		// Security signature (see Security Level 2)
		//$arrParams['sign'] = hash_hmac('md5', $arrParams['merchantId'].$arrParams['amount'].$arrParams['currency'].$arrParams['refno'], $this->datatrans_sign);
		
		$str = $arrParams['merchantId'].$arrParams['amount'].$arrParams['currency'].$arrParams['refno'];
		$key = $this->hexstr($this->datatrans_sign);
		$arrParams['sign'] = $this->hmac($key, $str);
		//<@PL
		
		$objTemplate = new FrontendTemplate('iso_payment_datatrans');
		$objTemplate->id = $this->id;
		$objTemplate->action = ('https://' . ($this->debug ? 'pilot' : 'payment') . '.datatrans.biz/upp/jsp/upStart.jsp');
		$objTemplate->params = $arrParams;
		$objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][0];
		$objTemplate->message = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][1];
		$objTemplate->slabel = specialchars($GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][2]);

		return $objTemplate->parse();
	}
	
	
	/**
	 * Validate array of post parameter agains required values
	 * @param array
	 * @return boolean
	 */
	private function validateParameters(array $arrData)
	{
		foreach ($arrData as $key => $value)
		{
			if ($this->Input->post($key) != $value)
			{
				$this->log('Wrong data for parameter "' . $key . '" (Order ID "' . $this->Input->post('refno') . ').', __METHOD__, TL_ERROR);
				return false;
			}
		}
		
		return true;
	}
	
	
	
	
	
	/********************************************************************/
	/*** FUNCTIONS FOR KEY GENERATION -> signUtils.inc (Datatrans)  *****/
	/********************************************************************/
	
	//>@PL include functions for datatrans key generation
	
	private function hexstr($hex)
	{
	   // translate byte array to hex string
	   $string="";
	   for ($i=0;$i<strlen($hex)-1;$i+=2)
	       $string.=chr(hexdec($hex[$i].$hex[$i+1]));
	   return $string;
	}

	private function hmac ($key, $data)
	{
	   // RFC 2104 HMAC implementation for php.
	   // Creates an md5 HMAC.
	   // Eliminates the need to install mhash to compute a HMAC

	   $b = 64; // byte length for md5
	   if (strlen($key) > $b) {
	       $key = pack("H*",md5($key));
	   }
	   $key  = str_pad($key, $b, chr(0x00));
	   $ipad = str_pad('', $b, chr(0x36));
	   $opad = str_pad('', $b, chr(0x5c));
	   $k_ipad = $key ^ $ipad ;
	   $k_opad = $key ^ $opad;

	   return md5($k_opad  . pack("H*",md5($k_ipad . $data)));
	}

	private function sign($key, $merchId, $amount, $ccy, $idno){
		$str=$merchId.$amount.$ccy.$idno;
		$key2=hexstr($key);
		return hmac($key2, $str);
	}
	
	//<@PL
	
}

