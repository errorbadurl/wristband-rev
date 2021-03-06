<?php

namespace App\Http\Controllers;

use App;
use App\Http\Controllers\Controller;
use App\Models\AddOns;
use App\Models\Carts;
use App\Models\Discounts;
use App\Models\MoldingFee;
use App\Models\Orders;
use App\Models\Prices;
use App\Models\TimeProduction;
use App\Models\TimeShipping;
use App\Wristbands\Classes\ClipartList;
use App\Wristbands\Classes\Colors;
use App\Wristbands\Classes\ColorsList;
use App\Wristbands\Classes\FontList;
use App\Wristbands\Classes\Styles;
use App\Wristbands\Classes\Sizes;
use File;
use Illuminate\Http\Request;
use Input;
use Mail;
use Session;
use Storage;
use URL;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;

class CartController extends Controller
{

	public function index(Request $request)
	{
		$data = [
			'items' => (Session::has('_cart')) ? Session::get('_cart') : []
		];
		return view('cart', $data);
    }

	public function cartAdd(Request $request)
	{
		// Check if cart session exists.
        if(!Session::has('_cart')) {
			// Get & create first item.
			$id = Session::getId();
			$data = [ $id => $request->data ];
			Session::put('_cart', $data); // Save first order to cart.
        } else {
			// Get id for the new item.
			$id = Session::getId();
			Session::put('_cart', array_add($cart = Session::get('_cart'), $id, $request->data)); // Save order to cart
		}
		// Regenerate session id for next order.
		Session::regenerate();

		// Return success!
 		return json_encode(true);
	}

	public function cartDelete(Request $request)
	{
		// Check if cart session exists.
        if(Session::has('_cart')) {
			// Get the cart.
			$items = Session::get('_cart');
			// check if order exists in cart.
			if(isset($items[$request->cart_index])) {
				unset($items[$request->cart_index]); // Remove and save order.
				Session::put('_cart', $items);
				// Return success!
		 		return json_encode(true);
			}
		}
		// Something is wrong.
		return json_encode(false);
	}

	public function cartClear(Request $request)
	{
        // Session::forget('_cart');
		// return json_encode(true);
	}

	public function cartUpdate(Request $request)
	{
		// Check if cart session exists.
        if(Session::has('_cart')) {
			// Get the cart.
			$items = Session::get('_cart');
			if(isset($items[$request->index])) {
				$data = [];

				$styles = new Styles();
				$data['styles'] = $styles->getStyles();

				$style = isset($request->style) && isset($data['styles'][$request->style]) ? $request->style : 'printed';
				$data['style'] = $style;

				$sizes = new Sizes();
				$data['sizes'] = $sizes->getSizes();

				$colors = new Colors();
				$data['colors'] = $colors->getColors();

				$list_color = new ColorsList();
				$data['list_colors'] = $list_color->getColors();

				$list_clipart = new ClipartList();
				$data['list_cliparts'] = $list_clipart->getCliparts();

				$list_font = new FontList();
				$data['list_fonts'] = $list_font->getFonts();

				$moldingFee = new MoldingFee();
				$data['molding_fee'] = $moldingFee->getJSONPrice()[0];

				$price = new Prices();
				$data['prices'] = $price->getJSONPrice();
				$data['addons'] = $price->getJSONAddOn();

				$data['cart'] = $items[$request->index];
				$data['index'] = $request->index;

				return view('order_update', $data);
			}
		}

		return redirect('/cart')->with('cart_message', 'Cart does not exist.');
	}

	public function cartUpdateStart(Request $request)
	{
		// Check if cart session exists.
        if(Session::has('_cart')) {
			// Get the cart.
			$items = Session::get('_cart');
			// check if order exists in cart.
			if(isset($items[$request->index])) {
				$items[$request->index] = $request->data;
				Session::put('_cart', $items);
				// Return success!
		 		return json_encode(true);
			}
		}
		// Something is wrong.
		return json_encode(false);
	}

	public function cartSubmit(Request $request)
	{
		$cart_list = Session::get('_cart');

		foreach ($cart_list as $key => $list) {
			if(isset($list['clips'])) {
				if(isset($list['clips']['logo'])) {
					foreach ($list['clips']['logo'] as $logoName => $logo) {
						$temp_path = $logo['image'];

		                $temp_folder_date = date('Ymd');
		                $dest_path = 'uploads/order/images/' . $temp_folder_date . '/' . $key;

						if(!File::exists($dest_path)) {
							File::makeDirectory($dest_path, $mode = 0777, true, true);
						}

						$file_path = substr($temp_path, strpos($temp_path, 'uploads/temp/'));

		                // Process image transport.
						$ext = File::extension($file_path);
						$name = File::name($file_path);
						$filename = $name . '.' . $ext;

						File::copy($file_path, $dest_path.'/'.$filename);

						$cart_list[$key]['clips']['logo'][$logoName]['image'] = URL::asset('').$dest_path.'/'.$filename;
					}
				}
			}
		}

		// Set for success page.
		Session::flash('order_items', $cart_list);
		Session::flash('order_status', 'success');
		// Forget cart items.
		Session::forget('_cart');
		// Redirect to success page
		return json_encode(true);
	}

	public function checkout(Request $request)
	{
		if(Session::has('_cart')) {
			$data = [
				'items' => Session::get('_cart'),
				'data' => (Session::has('checkout_data')) ? Session::get('checkout_data') : [],
				'breakdown' => $this->generateBreakDown(Session::get('_cart'), "")
			];
			return view('checkout', $data);
		}
		return redirect('/cart')->with('cart_message', 'Cart does not exist.');
	}

	public function checkoutSubmit(Request $request)
	{
		// Initialize order data
		$data_order = [
			"DateCreated"		=> date('Y-m-d H:i:s'),
			"TempToken"			=> $request->_token,
			"TransNo"			=> "",
			"Status"			=> "0",
			"FirstName"			=> $request->bInfoFirstName,
			"LastName"			=> $request->bInfoLastName,
			"EmailAddress"		=> $request->bInfoEmail,
			"Total"				=> $this->getCartGrandTotal(),
			"PaymentMethod"		=> (strtoupper($request->PaymentType) == "CC") ? 'authnet' : 'paypal',
			"Paid"				=> "0",
			"PaidDate"			=> "",
			"AuthorizeTransID"	=> "",
			"PaypalEmail"		=> $request->PaypalEmail,
			"PaymentRemarks"	=> "",
			"ProductionCharge"	=> "",
			"DeliveryCharge"	=> "",
			"DaysProduction"	=> "",
			"DaysDelivery"		=> "",
			"DiscountCode"		=> $request->DiscountCode,
			"DiscountPercent"	=> $request->DiscountPercent,
			"Address"			=> $request->bInfoStreetAddress1,
			"Address2"			=> $request->bInfoStreetAddress2,
			"City"				=> $request->bInfoCity,
			"State"				=> $request->bInfoState,
			"ZipCode"			=> $request->bInfoZipCode,
			"Country"			=> $request->bInfoCountry,
			"Phone"				=> $request->bInfoContactNo,
			"ShipFirstName"		=> $request->sInfoFirstName,
			"ShipLastName"		=> $request->sInfoLastName,
			"ShipAddress"		=> $request->sInfoStreetAddress1,
			"ShipAddress2"		=> $request->sInfoStreetAddress2,
			"ShipCity"			=> $request->sInfoCity,
			"ShipState"			=> $request->sInfoState,
			"ShipZipCode"		=> $request->sInfoZipCode,
			"ShipCountry"		=> $request->sInfoCountry,
			"DataStream"		=> "",
			"ReplyString"		=> "",
			"RandomChr"			=> "",
			"IPAddress"			=> $request->ip()
		];

		// Insert new order
		$orders = new Orders();
		$order_id = $orders->insertOrder($data_order);

		if (!$order_id) {
			return redirect('/checkout')->withErrors(['message'=> 'Something went wrong! Kindly try again.'], 'checkout')->withInput();
		}

		$orderNum = $this->generateOrderNumber($order_id);

		if (!$orderNum) {
			return redirect('/checkout')->withErrors(['message'=> 'Something went wrong! Kindly try again.'], 'checkout')->withInput();
		}

		// Update with generated order number
		$orders->where('ID', $order_id)->update(["OrderNumber"=> $orderNum]);

		$arrCart = $this->organizeCart($request->_token, $order_id, $request->bInfoFirstName." ".$request->bInfoLastName, $request->bInfoContactNo, $request->bInfoEmail);

		foreach ($arrCart as $key => $value) {
			unset($arrCart[$key]['_Name']);
			unset($arrCart[$key]['_Size']);
		}

		$carts_model = new Carts();
		$carts_model->insert($arrCart);

		if (strtoupper($request->PaymentType) == "CC") {
			$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
			$merchantAuthentication->setName(App::make('config')->get('services.authorizenet.name'));
			$merchantAuthentication->setTransactionKey(App::make('config')->get('services.authorizenet.key'));
			$refId = 'ref' . time();
			
			// Create the payment data for a credit card
			$creditCard = new AnetAPI\CreditCardType();
			$creditCard->setCardNumber(trim(str_replace(' ', '', $request->CardNum)));  
			$creditCard->setExpirationDate(trim($request->CardExpDateYear)."-".trim($request->CardExpDateMonth));
    		$creditCard->setCardCode(trim($request->CardCVV));
			$paymentOne = new AnetAPI\PaymentType();
			$paymentOne->setCreditCard($creditCard);
			
		    $order = new AnetAPI\OrderType();
		    $order->setDescription("Promotional Wristband");
			
		    // Set the customer's Bill To address
		    $customerAddress = new AnetAPI\CustomerAddressType();
		    $customerAddress->setFirstName($request->bInfoFirstName);
		    $customerAddress->setLastName($request->bInfoLastName);
		    $customerAddress->setAddress($request->bInfoStreetAddress1);
		    $customerAddress->setCity($request->bInfoCity);
		    $customerAddress->setState($request->bInfoState);
		    $customerAddress->setZip($request->bInfoZipCode);
		    $customerAddress->setCountry($request->bInfoCountry);
    		$customerAddress->setPhoneNumber($request->bInfoContactNo);
			
			// Compute total
			$total = 0;
			$cart = Session::get('_cart');
			foreach ($cart as $cartVal) {
				$total += $cartVal['total'];
			}
			$discount = 0;
			if (!empty($request->DiscountCode)) {
	            if ($discountModel = Discounts::where('Code', strtoupper($request->DiscountCode))->get()->first()) {
					$discount = $total * (number_format(($discountModel->Percentage / 100), 2));
					$discount = number_format($discount, "2");
	            }
			}

			// Create a transaction
			$transactionRequestType = new AnetAPI\TransactionRequestType();
			$transactionRequestType->setTransactionType("authCaptureTransaction");   
			$transactionRequestType->setAmount($total - $discount);
    		$transactionRequestType->setBillTo($customerAddress);
    		$transactionRequestType->setOrder($order);
    		$transactionRequestType->setPayment($paymentOne);

			$trequest = new AnetAPI\CreateTransactionRequest();
			$trequest->setMerchantAuthentication($merchantAuthentication);
			$trequest->setRefId($refId);
			$trequest->setTransactionRequest($transactionRequestType);

			$controller = new AnetController\CreateTransactionController($trequest);
			if (App::make('config')->get('services.authorizenet.sandbox')) {
				$response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
			} else {
				$response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION);
			}
			
			// Default error message
			$errMsg = 'Something went wrong! Kindly try again.';
			if ($response != null) {
				$tresponse = $response->getTransactionResponse();
				
				if (($tresponse != null) && ($tresponse->getResponseCode()=="1") ) {
					$data_order['AuthorizeTransID'] = $tresponse->getTransId();
					$data_order['TransNo'] = $tresponse->getTransId();
					$data_order['Status'] = 1;
					$data_order['Paid'] = 1;
					$data_order['PaidDate'] = date('Y-m-d H:i:s');
					$shipping = $this->getCartShipping();
					$production = $this->getCartProduction();
					$data_order['DaysDelivery'] = $shipping['days'];
					$data_order['DeliveryCharge'] = $shipping['total'];
					$data_order['DaysProduction'] = $production['days'];
					$data_order['ProductionCharge'] = $production['total'];
					$data_order['PaymentRemarks'] = "Paid using Credit Card through Authorize.Net";

					$orders_model = new Orders();
					$orders_model->where('ID', $order_id)->update($data_order);
					$orders_model = new Carts();
					$orders_model->where('OrderID', $order_id)->update(["Status"=>1]);

					Session::forget('_cart');
					Session::forget('_paypal');
					Session::flash('_checkout_success', true);
					return redirect('/checkout/success');
				} else {
					// Get authnet transaction error message
					if($tresponse->getErrors()) {
						if ($tresponse->getErrors()[0] !== null) {
							if (!empty($tresponse->getErrors()[0]->getErrorText())) {
								$errMsg = $tresponse->getErrors()[0]->getErrorText();
							}
						}
					}
					return redirect('/checkout')->withErrors(['message'=> $errMsg], 'checkout')->withInput();
				}
			} else {
				// Get authnet response message
				if ($response->getMessages()->getMessage()) {
					if ($response->getMessages()->getMessage()[0] !== null) {
						if (!empty($response->getMessages()->getMessage()[0]->getText())) {
							$errMsg = $response->getMessages()->getMessage()[0]->getText();
						}
					}
				}
				return redirect('/checkout')->withErrors(['message'=> $errMsg], 'checkout')->withInput();
			}
			return redirect('/checkout')->withErrors(['message'=> $errMsg], 'checkout')->withInput();
		} else {
			// ### Payer
			$payer = new Payer();
			$payer->setPaymentMethod("paypal");

			// ### Itemized information
			$items = [];

			$ppcart = Session::get('_cart');

			$breakdown = $this->generateBreakDown($ppcart, $request->DiscountCode);

			foreach ($breakdown["items"] as $value) {
				$item = new Item();
				$item->setName($value["name"])
					 ->setCurrency($value["currency"])
					 ->setQuantity($value["quantity"])
					 ->setPrice($value["price"]);
				$items[] = $item;
			}

			$itemList = new ItemList();
			$itemList->setItems($items);

			// ### Additional payment details
			$details = new Details();
			$details->setShipping($breakdown["details"]["shipping"])
				    ->setTax($breakdown["details"]["tax"])
				    ->setSubtotal($breakdown["details"]["subtotal"]);

			// ### Amount
			$amount = new Amount();
			$amount->setCurrency($breakdown["amount"]["currency"])
				   ->setTotal($breakdown["amount"]["total"])
				   ->setDetails($details);

			// ### Transaction
			$transaction = new Transaction();
			$transaction->setAmount($amount)
					    ->setItemList($itemList)
					    ->setDescription("Promotional Wristbands.")
					    ->setInvoiceNumber(uniqid());

			// ### Redirect urls
			$baseUrl = URL::to('/');
			$redirectUrls = new RedirectUrls();
			$redirectUrls->setReturnUrl("$baseUrl/checkout/paypal?success=true")
			    		 ->setCancelUrl("$baseUrl/checkout/paypal?success=false");

			// ### Payment
			$payment = new Payment();
			$payment->setIntent("sale")
				    ->setPayer($payer)
				    ->setRedirectUrls($redirectUrls)
				    ->setTransactions(array($transaction));

			// ### Create Payment
			$apiContext = new ApiContext(
							new OAuthTokenCredential(
								App::make('config')->get('services.paypal.client_id'),
								App::make('config')->get('services.paypal.secret')
							)
			            );
			$apiContext->setConfig(App::make('config')->get('services.paypal.settings'));

			try {
			    $payment->create($apiContext);

				if ($payment->getState() == "created") {
					$paypal_data = [
						"order_id" => $order_id,
						"order_input" => Input::all(),
					];
					Session::put('_paypal', $paypal_data);
					return redirect($payment->getApprovalLink());
				} else {
					$errMsg = 'Something went wrong! Payment using PayPal is invalid. Kindly try again.';
					return redirect('/checkout')->withErrors(['message'=> $errMsg], 'checkout')->withInput();
				}
			} catch (Exception $ex) {
				// Default error message
				$errMsg = 'Something went wrong! Payment using PayPal is invalid. Kindly try again.';
				return redirect('/checkout')->withErrors(['message'=> $errMsg], 'checkout')->withInput();
			}

			return redirect('/checkout')->withErrors(['message'=> 'Something went wrong! Kindly try again.'], 'checkout')->withInput();
		}
	}

	public function checkoutPaypal(Request $request)
	{
		if (!Session::has('_cart')) {
			return redirect('/cart')->with('cart_message', 'Cart does not exist.');
		}

		if (!Session::has('_paypal')) {
			return redirect('/cart')->with('cart_message', 'Cart does not exist.');
		}

		if($request->success && $request->success == 'true') {

			// ### Create Payment
			$apiContext = new ApiContext(
							new OAuthTokenCredential(
								App::make('config')->get('services.paypal.client_id'),
								App::make('config')->get('services.paypal.secret')
							)
						);
			$apiContext->setConfig(App::make('config')->get('services.paypal.settings'));

		    $paymentId = $request->paymentId;
		    $payment = Payment::get($paymentId, $apiContext);

		    // ### Payment Execute
		    $execution = new PaymentExecution();
		    $execution->setPayerId($request->PayerID);

			$order_id = Session::get('_paypal.order_id');
			$paypalRequest = Session::get('_paypal.order_input');

			$ppcart = Session::get('_cart');

			$breakdown = $this->generateBreakDown($ppcart, $paypalRequest['DiscountCode']);

			// ### Additional payment details
			$details = new Details();
			$details->setShipping($breakdown["details"]["shipping"])
				    ->setTax($breakdown["details"]["tax"])
				    ->setSubtotal($breakdown["details"]["subtotal"]);

			// ### Amount
			$amount = new Amount();
			$amount->setCurrency($breakdown["amount"]["currency"])
				   ->setTotal($breakdown["amount"]["total"])
				   ->setDetails($details);
			
			// ### Transaction
			$transaction = new Transaction();
			$transaction->setAmount($amount);

			// Add the above transaction object inside our Execution object.
			$execution->addTransaction($transaction);

			try {

			    // Execute the payment
			    // (See bootstrap.php for more on `ApiContext`)
			    $result = $payment->execute($execution, $apiContext);

			    try {
			        $payment = Payment::get($paymentId, $apiContext);
					$shipping = $this->getCartShipping();
					$production = $this->getCartProduction();

					$data_order = [
						"TransNo" => $payment->getId(),
						"Status" => 1,
						"Paid" => 1,
						"PaidDate" => date('Y-m-d H:i:s'),
						"DaysDelivery" => $shipping['days'],
						"DeliveryCharge" => $shipping['total'],
						"DaysProduction" => $production['days'],
						"ProductionCharge" => $production['total'],
						"PaymentRemarks" => "Paid using Paypal"
					];

					$orders_model = new Orders();
					$orders_model->where('ID', $order_id)->update($data_order);
					$orders_model = new Carts();
					$orders_model->where('OrderID', $order_id)->update(["Status"=>1]);

					Session::forget('_cart');
					Session::forget('_paypal');
					Session::flash('_checkout_success', true);
					return redirect('/checkout/success');
			    } catch (Exception $ex) {
					Session::put('_old_input', Session::get('_paypal.order_input'));
					return redirect('/checkout')->withErrors(['message'=> 'Something went wrong with your PayPal checkout. Kindly try again.'], 'checkout');
			    }
			} catch (Exception $ex) {
				Session::put('_old_input', Session::get('_paypal.order_input'));
				return redirect('/checkout')->withErrors(['message'=> 'Something went wrong with your PayPal checkout. Kindly try again.'], 'checkout');
			}

		} else {
			Session::put('_old_input', Session::get('_paypal.order_input'));
			return redirect('/checkout')->withErrors(['message'=> 'PayPal checkout is cancelled. Kindly try again.'], 'checkout');
		}

		Session::put('_old_input', Session::get('_paypal.order_input'));
		return redirect('/checkout')->withErrors(['message'=> 'Approval for PayPal checkout is cancelled. Kindly try again.'], 'checkout');
	}

	public function checkoutSuccess()
	{
		if (Session::has('_checkout_success')) {
			if (Session::get('_checkout_success')) {
				return view('checkout-success', []);
			}
		}
		return redirect('/');
	}

	private function generateOrderNumber($order_id)
	{
		if(!is_numeric($order_id)) { return false; }

		$zeros = "";
		if(strlen($order_id) === 1) {
			$zeros = "0077";
		} else if(strlen($order_id) === 2) {
			$zeros = "007";
		} else if(strlen($order_id) === 3) {
			$zeros = "07";
		}

		return "PW0".$zeros.$order_id;
	}

	private function organizeCart($token, $order_id, $full_name, $phone_num, $email)
	{

		$data_cart_default = [
			"DateCreated"						=> date('Y-m-d H:i:s'),
			"Status"							=> "0",
			"OrderID"							=> $order_id,
			"TempToken"							=> $token,
			"BandStyle"							=> "",
			"BandType"							=> "",
			"BandSize"							=> "",
			"MessageStyle"						=> "",
			"Font"								=> "",
			"FrontMessage"						=> "",
			"BackMessage"						=> "",
			"ContinuousMessage"					=> "",
			"FrontMessageStartClipart"			=> "",
			"FrontMessageEndClipart"			=> "",
			"BackMessageStartClipart"			=> "",
			"BackMessageEndClipart"				=> "",
			"ContinuousMessageStartClipart"		=> "",
			"ContinuousEndClipart"				=> "",
			"ProductionTime"					=> "",
			"FreeQty"							=> "",
			"Delivery"							=> "",
			"Individual_Pack"					=> "",
			"Keychain"							=> "",
			"DigitalPrint"						=> "",
			"Comments"							=> "",
			"PriceProduction"					=> "",
			"PriceDelivery"						=> "",
			"PriceIndividual_Pack"				=> "",
			"PriceKeychain"						=> "",
			"PriceDigitalPrint"					=> "",
			"PriceBackMessage"					=> "",
			"PriceContinuousMessage"			=> "",
			"PriceLogo"							=> "",
			"PriceColorSplit"					=> "",
			"PriceMouldingFee"					=> "",
			"RandomChr"							=> "",
			"newCart"							=> "",
			"arColors"							=> "",
			"arAddons"							=> "",
			"arMoldingFee"						=> "",
			"arFrontMessage"					=> "",
			"arBackMessage"						=> "",
			"arContinuousMessage"				=> "",
			"arInsideMessage"					=> "",
			"arFrontMessageStartClipart"		=> "",
			"arFrontMessageEndClipart"			=> "",
			"arBackMessageStartClipart"			=> "",
			"arBackMessageEndClipart"			=> "",
			"arContinuousMessageStartClipart"	=> "",
			"arContinuousEndClipart"			=> "",
			"arInfo"							=> "",
			"arFree"							=> "",
			"arKeychains"						=> "",
			"arProduction"						=> "",
			"arShipping"						=> "",
			"Qty"								=> "",
			"UnitPrice"							=> "",
			"Total"								=> "",
			"FullName"							=> $full_name,
			"PhoneNo"							=> $phone_num,
			"DateQuote"							=> "",
			"EmailAddress"						=> $email
		];

		$data = [];
		$customCount = 0;

		// Organize cart data
		$cart_list = Session::get('_cart');

		foreach ($cart_list as $key => $list) {

			$data_cart_default_band = [
				"BandStyle"			=> strtolower($list['style']),
				"BandSize"			=> strtolower($list['size']),
				"Font"				=> strtolower($list['fonts']),
				"ProductionTime"	=> $list['time_production']['days'],
				"arProduction"		=> json_encode($list['time_production']),
				"PriceProduction"	=> $list['time_production']['price'],
				"Delivery"			=> $list['time_shipping']['days'],
				"arShipping"		=> json_encode($list['time_shipping']),
				"PriceDelivery"		=> $list['time_shipping']['price'],
				"RandomChr"			=> $key,
			];
			
			$data_cart = [];
			$data_cart_free = [];
			$data_cart_item = [];
			$has_molding_fee = true;

			foreach ($list['items']['data'] as $stylesKey => $items) {

				$data_cart_item_attr = [];
				$data_cart_item_addons = [];
				$item_qty = 0;

				foreach ($items['list'] as $variantsKey => $variants) {

					foreach ($variants as $key => $item) {

						if (is_array($item)) {

							$moldingFeePrice = ['price'=> 0];

							if($has_molding_fee) {
								$moldingFee = new MoldingFee();
								$moldingFee = $moldingFee->getJSONPrice()[0];
								$moldingFeePrice['price'] = (count($variants)-1) * $moldingFee;
								$has_molding_fee = false;
							}

							if (strpos(strtolower($item['title']), "custom") !== false) { $customCount++; }

							$comment = ["font_color"=> strtoupper($item['font']), "font_name"=> ucwords(strtolower($item['font_title'])), "size"=> strtolower($item['size'])];
							$arKeychains = ["data"=> [], "total"=> 0];
							$arWristbands = ["data"=> [], "total"=> 0];

							if (isset($list['free'])) {
								if (isset($list['free']['key-chain'])) {
									if (isset($list['free']['key-chain']['items'])) {
										if (isset($list['free']['key-chain']['items'][$stylesKey])) {
											if (isset($list['free']['key-chain']['items'][$stylesKey][$variantsKey])) {
												if (isset($list['free']['key-chain']['items'][$stylesKey][$variantsKey][$key])) {
													$arFreeKCKey = $list['free']['key-chain']['items'][$stylesKey][$variantsKey];
													$arFreeKCData = $list['free']['key-chain']['items'][$stylesKey][$variantsKey][$key];
													$nameFreeKCTitle = ucwords(strtolower(str_replace('-', ' ', $arFreeKCKey['color_title'])));
													$nameFreeKCTitle = str_replace(',', ', ', $nameFreeKCTitle);
													$nameFreeKCTitle = ucwords($nameFreeKCTitle);
													$nameFreeKCTitle = str_replace(', ', ',', $nameFreeKCTitle);
													$nameFreeKCSize = "Medium";
													switch ($arFreeKCData['size']) {
														case 'yt': $nameFreeKCSize = "Youth"; break;
														case 'md': $nameFreeKCSize = "Medium"; break;
														case 'ad': $nameFreeKCSize = "Adult"; break;
														case 'xs': $nameFreeKCSize = "ExtraSmall"; break;
														case 'xl': $nameFreeKCSize = "ExtraLarge"; break;
														default: $nameFreeKCSize = "Medium"; break;
													}
													if (strpos(strtolower($arFreeKCKey['title']), "custom") !== false) {
														$nameFreeKCName = $nameFreeKCSize."_".$arFreeKCKey['style']."_Custom"."_".$customCount;
													} else {
														$nameFreeKCName = $nameFreeKCSize."_".$arFreeKCKey['style']."_".str_replace(' ', '', $nameFreeKCTitle);
													}
													$arKeychains["total"] += $arFreeKCData['qty'];
													$arKeychains["data"] = [
														"Name" => $nameFreeKCName,
														"Qty" => $arFreeKCData['qty'],
														"FontColor" => ucwords(strtolower($arFreeKCData['font_title'])),
														"CustomColors" => json_encode(explode(',', $nameFreeKCTitle)),
													];
												}
											}
										}
									}
								}

								if (isset($list['free']['wristbands'])) {
									if (isset($list['free']['wristbands']['items'])) {
										if (isset($list['free']['wristbands']['items'][$stylesKey])) {
											if (isset($list['free']['wristbands']['items'][$stylesKey][$variantsKey])) {
												if (isset($list['free']['wristbands']['items'][$stylesKey][$variantsKey][$key])) {
													$arFreeWBKey = $list['free']['wristbands']['items'][$stylesKey][$variantsKey];
													$arFreeWBData = $list['free']['wristbands']['items'][$stylesKey][$variantsKey][$key];
													$nameFreeWBTitle = ucwords(strtolower(str_replace('-', ' ', $arFreeWBKey['color_title'])));
													$nameFreeWBTitle = str_replace(',', ', ', $nameFreeWBTitle);
													$nameFreeWBTitle = ucwords($nameFreeWBTitle);
													$nameFreeWBTitle = str_replace(', ', ',', $nameFreeWBTitle);
													$nameFreeWBSize = "Medium";
													switch ($arFreeWBData['size']) {
														case 'yt': $nameFreeWBSize = "Youth"; break;
														case 'md': $nameFreeWBSize = "Medium"; break;
														case 'ad': $nameFreeWBSize = "Adult"; break;
														case 'xs': $nameFreeWBSize = "ExtraSmall"; break;
														case 'xl': $nameFreeWBSize = "ExtraLarge"; break;
														default: $nameFreeWBSize = "Medium"; break;
													}
													if (strpos(strtolower($arFreeWBKey['title']), "custom") !== false) {
														$nameFreeWBName = $nameFreeWBSize."_".$arFreeWBKey['style']."_Custom"."_".$customCount;
													} else {
														$nameFreeWBName = $nameFreeWBSize."_".$arFreeWBKey['style']."_".str_replace(' ', '', $nameFreeWBTitle);
													}
													$arWristbands["total"] += $arFreeWBData['qty'];
													$arWristbands["data"] = [
														"Name" => $nameFreeWBName,
														"Qty" => $arFreeWBData['qty'],
														"FontColor" => ucwords(strtolower($arFreeWBData['font_title'])),
														"CustomColors" => json_encode(explode(',', $nameFreeWBTitle)),
													];
												}
											}
										}
									}
								}
							}

							// Message
							if (isset($list['texts'])) {
								if (isset($list['texts']['continue'])) {
									$data_cart_default_band['MessageStyle'] = "continuous";
									$data_cart_default_band['ContinuousMessage'] = $list['texts']['continue']['text'];
									// Reconstruct array
										$arrMessage = $list['texts']['continue'];
										$arrMessage['quantity'] = $item['qty'];
										$arrMessage['total'] = $arrMessage['price'] * $arrMessage['quantity'];
									$data_cart_default_band['arContinuousMessage'] = json_encode($arrMessage);
									$data_cart_default_band['PriceContinuousMessage'] = json_encode($arrMessage['total']);
								}
								if (isset($list['texts']['front'])) {
									$data_cart_default_band['MessageStyle'] = "front_back";
									$data_cart_default_band['FrontMessage'] = $list['texts']['front']['text'];
									// Reconstruct array
										$arrMessage = $list['texts']['front'];
										$arrMessage['quantity'] = $item['qty'];
										$arrMessage['total'] = $arrMessage['price'] * $arrMessage['quantity'];
									$data_cart_default_band['arFrontMessage'] = json_encode($arrMessage);
								}
								if (isset($list['texts']['back'])) {
									$data_cart_default_band['MessageStyle'] = "front_back";
									$data_cart_default_band['BackMessage'] = $list['texts']['back']['text'];
									// Reconstruct array
										$arrMessage = $list['texts']['back'];
										$arrMessage['quantity'] = $item['qty'];
										$arrMessage['total'] = $arrMessage['price'] * $arrMessage['quantity'];
									$data_cart_default_band['arBackMessage'] = json_encode($arrMessage);
									$data_cart_default_band['PriceBackMessage'] = json_encode($arrMessage['total']);
								}
								if (isset($list['texts']['inside'])) {
									// Reconstruct array
										$arrMessage = $list['texts']['inside'];
										$arrMessage['quantity'] = $item['qty'];
										$arrMessage['total'] = $arrMessage['price'] * $arrMessage['quantity'];
									$data_cart_default_band['arInsideMessage'] = json_encode($arrMessage);
								}
							}

							// Clipart
							if (isset($list['clips'])) {
								if (isset($list['clips']['logo'])) {
									if (isset($list['clips']['logo']['front-end'])) {
										$arrImage = $list['clips']['logo']['front-end'];
										$arrImage['quantity'] = $item['qty'];
										$arrImage['total'] = $arrImage['quantity'] * $arrImage['price'];
										$data_cart_default_band['FrontMessageEndClipart'] = $arrImage['image'];
										$data_cart_default_band['arFrontMessageEndClipart'] = json_encode($arrImage);
									}
									if (isset($list['clips']['logo']['front-start'])) {
										$arrImage = $list['clips']['logo']['front-start'];
										$arrImage['quantity'] = $item['qty'];
										$arrImage['total'] = $arrImage['quantity'] * $arrImage['price'];
										$data_cart_default_band['FrontMessageStartClipart'] = $arrImage['image'];
										$data_cart_default_band['arFrontMessageStartClipart'] = json_encode($arrImage);
									}
									if (isset($list['clips']['logo']['back-end'])) {
										$arrImage = $list['clips']['logo']['back-end'];
										$arrImage['quantity'] = $item['qty'];
										$arrImage['total'] = $arrImage['quantity'] * $arrImage['price'];
										$data_cart_default_band['BackMessageEndClipart'] = $arrImage['image'];
										$data_cart_default_band['arBackMessageEndClipart'] = json_encode($arrImage);
									}
									if (isset($list['clips']['logo']['back-start'])) {
										$arrImage = $list['clips']['logo']['back-start'];
										$arrImage['quantity'] = $item['qty'];
										$arrImage['total'] = $arrImage['quantity'] * $arrImage['price'];
										$data_cart_default_band['BackMessageStartClipart'] = $arrImage['image'];
										$data_cart_default_band['arBackMessageStartClipart'] = json_encode($arrImage);
									}
									if (isset($list['clips']['logo']['cont-end'])) {
										$arrImage = $list['clips']['logo']['cont-end'];
										$arrImage['quantity'] = $item['qty'];
										$arrImage['total'] = $arrImage['quantity'] * $arrImage['price'];
										$data_cart_default_band['ContinuousEndClipart'] = $arrImage['image'];
										$data_cart_default_band['arContinuousEndClipart'] = json_encode($arrImage);
									}
									if (isset($list['clips']['logo']['cont-start'])) {
										$arrImage = $list['clips']['logo']['cont-start'];
										$arrImage['quantity'] = $item['qty'];
										$arrImage['total'] = $arrImage['quantity'] * $arrImage['price'];
										$data_cart_default_band['ContinuousMessageStartClipart'] = $arrImage['image'];
										$data_cart_default_band['arContinuousMessageStartClipart'] = json_encode($arrImage);
									}
								}
							}

							// Addons
							if (isset($list['addon'])) {
								// 3mm Thick
								if (isset($list['addon']['3mm-thick'])) {
									$arrAddonBand = $list['addon']['3mm-thick'];
									// Update values for individual items
									$arrAddonBand['quantity'] = $item['qty'];
									$arrAddonBand['total'] = $arrAddonBand['quantity'] * $arrAddonBand['price'];
									// Set arAddons values
									$data_cart_item_addons['3mmThick'] = $arrAddonBand;
								}
								// Digital Print / Digital Proof
								if (isset($list['addon']['digital-proof'])) {
									$arrAddonBand = $list['addon']['digital-proof'];
									// Update values for individual items
									$arrAddonBand['quantity'] = $item['qty'];
									$arrAddonBand['total'] = $arrAddonBand['quantity'] * $arrAddonBand['price'];
									// Set values
									$data_cart_default_band['DigitalPrint'] = $arrAddonBand['quantity'];
									$data_cart_default_band['PriceDigitalPrint'] = $arrAddonBand['price'];
									// Set arAddons values
									$data_cart_item_addons['DigitalPrint'] = $arrAddonBand;
								}
								// Eco Friendly Addon
								if (isset($list['addon']['eco-friendly'])) {
									$arrAddonBand = $list['addon']['eco-friendly'];
									// Update values for individual items
									$arrAddonBand['quantity'] = $item['qty'];
									$arrAddonBand['total'] = $arrAddonBand['quantity'] * $arrAddonBand['price'];
									// Set arAddons values
									$data_cart_item_addons['Ecofriendly'] = $arrAddonBand;
								}
								// Individual Pack Addon
								if (isset($list['addon']['individual'])) {
									$arrAddonBand = $list['addon']['individual'];
									// Update values for individual items
									$arrAddonBand['quantity'] = $item['qty'];
									$arrAddonBand['total'] = $arrAddonBand['quantity'] * $arrAddonBand['price'];
									// Set values
									$data_cart_default_band['Individual_Pack'] = $arrAddonBand['quantity'];
									$data_cart_default_band['PriceIndividual_Pack'] = $arrAddonBand['price'];
									// Set arAddons values
									$data_cart_item_addons['Individual_Pack'] = $arrAddonBand;
								}
								// Glitters Addon
								if (isset($list['addon']['glitters'])) {
									$arrAddonBand = $list['addon']['glitters'];
									// Update values for individual items
									$arrAddonBand['quantity'] = $item['qty'];
									$arrAddonBand['total'] = $arrAddonBand['quantity'] * $arrAddonBand['price'];
									// Set arAddons values
									$data_cart_item_addons['Glitters'] = $arrAddonBand;
								}
								// Keychain Addon
								if (isset($list['addon']['key-chain'])) {
									$arrAddonKeychain = $list['addon']['key-chain'];
									$arrAddonKeychainQty = 0;
									$arrAddonKeychainPrice = $arrAddonKeychain['price'];
									if ($arrAddonKeychain['all'] == 'true') { // Update values for individual items
										$arrAddonKeychainQty = $item['qty'];
									} else {
										$arrAddonKeychainQty = $arrAddonKeychain['items'][$item['style']][$variantsKey]['size'][$item['size']]['qty'];
									}
									$arrAddonKeychainTotal = $arrAddonKeychainQty * $arrAddonKeychainPrice;
									// Set values
									$data_cart_default_band['Keychain'] = $arrAddonKeychainQty;
									$data_cart_default_band['PriceKeychain'] = $arrAddonKeychainPrice;
									$nameAddonKeychainTitle = ucwords(strtolower(str_replace('-', ' ', $item['color_title'])));
									$nameAddonKeychainTitle = str_replace(',', ', ', $nameAddonKeychainTitle);
									$nameAddonKeychainTitle = ucwords($nameAddonKeychainTitle);
									$nameAddonKeychainTitle = str_replace(', ', ',', $nameAddonKeychainTitle);
									$nameAddonKeychainSize = "Medium";
									switch ($item['size']) {
										case 'yt': $nameAddonKeychainSize = "Youth"; break;
										case 'md': $nameAddonKeychainSize = "Medium"; break;
										case 'ad': $nameAddonKeychainSize = "Adult"; break;
										case 'xs': $nameAddonKeychainSize = "ExtraSmall"; break;
										case 'xl': $nameAddonKeychainSize = "ExtraLarge"; break;
										default: $nameAddonKeychainSize = "Medium"; break;
									}
									if (strpos(strtolower($item['title']), "custom") !== false) {
										$arrAddonKeychainName = $nameAddonKeychainSize."_".$item['style']."_Custom"."_".$customCount;
									} else {
										$arrAddonKeychainName = $nameAddonKeychainSize."_".$item['style']."_".str_replace(' ', '', $nameAddonKeychainTitle);
									}
									// Set arAddons values
									unset($arrAddonKeychain['items']);
									$data_cart_item_addons['Keychain'] = [
										"total" => $arrAddonKeychainTotal,
										"price" => $arrAddonKeychainPrice,
										"Qty" => $arrAddonKeychainQty,
										"Name" => $arrAddonKeychainName,
										"FontColor" => ucwords(strtolower($item['font_title'])),
										"CustomColors" => json_encode(explode(',', $nameAddonKeychainTitle)),
									];
								}
							}

							$nameSize = "Medium";
							switch ($item['size']) {
								case 'yt': $nameSize = "Youth"; break;
								case 'md': $nameSize = "Medium"; break;
								case 'ad': $nameSize = "Adult"; break;
								case 'xs': $nameSize = "ExtraSmall"; break;
								case 'xl': $nameSize = "ExtraLarge"; break;
								default: $nameSize = "Medium"; break;
							}

							$arInfoName = strtolower(str_replace('-', ' ', $item['color_title']));
							$arInfoName = str_replace(',', ', ', $arInfoName);
							$arInfoName = ucwords($arInfoName);
							$arInfoName = str_replace(', ', ',', $arInfoName);

							if (strpos(strtolower($item['title']), "custom") !== false) {
								$name = $nameSize."_".$item['style']."_Custom"."_".$customCount;
							} else {
								$name = $nameSize."_".$item['style']."_".str_replace(' ', '', $arInfoName);
							}

							$arInfo = [
								"Name" => $name,
								"Qty" => $item['qty'],
								"FontColor" => ucwords(strtolower($item['font_title'])),
								"CustomColors" => json_encode(explode(',', $arInfoName)),
							];

							$data_cart_item_attr[] = [
								"_Name"				=> ucwords(strtolower($item['title'])),
								"_Size"				=> strtolower($item['size']),
								"BandType"			=> $item['style'],
								"arColors"			=> json_encode(explode(',', strtoupper($item['color']))), // JSON String ~ Color
								"Qty"				=> $item['qty'], // String ~ Quantity
								"Comments"			=> json_encode($comment), // JSON String ~ Comment
								"FreeQty"			=> $arWristbands['total'] + $arKeychains['total'], // Int ~ Free wristbands
								"arFree"			=> json_encode(["wristbands"=> $arWristbands, "keychains"=> $arKeychains]), // JSON String ~ Free wristbands & keychains
								"arInfo"			=> json_encode($arInfo), // JSON String ~ Free wristbands & keychains
								"arAddons"			=> json_encode($data_cart_item_addons), // JSON String ~ Addons
								"PriceMouldingFee"	=> $moldingFeePrice['price'],
								"arMoldingFee"		=> json_encode($moldingFeePrice), // JSON String ~ Molding Fee
							];
						}
					}
				}

				foreach ($data_cart_item_attr as $value) {
					// Prepare add-ons computation
					$itemAllPrice = 0;
					// Compute add-ons
					foreach (json_decode($value['arAddons'], true) as $addval) {
						$itemAllPrice += $addval['total'];
					}
					// Compute prices
					$itemTotalPrice = $value['Qty'] * $list['price'];
					$itemAddonsPrice = $value['Qty'] * $items['price_addon'];
					$itemMoldingFee = $value["PriceMouldingFee"];
					// Total computation
					$data_cart_item['UnitPrice'] = $itemTotalPrice + $itemAddonsPrice + $itemAllPrice;
					$data_cart_item['Total'] = $itemTotalPrice + $itemAddonsPrice + $itemAllPrice + $itemMoldingFee;
					$data_cart[] = array_merge($data_cart_item, $value);
				}
			}

			// Last thing to do. Merge everything...
			foreach ($data_cart as $cart_key => $cart_val) {
				$data[] = array_merge($data_cart_default, $data_cart_default_band, $cart_val);
			}

		}

		return $data;
	}

	private function getCartShipping()
	{
		$data = [
			"days" => 0,
			"total" => 0,
			"items" => [],
		];

		$cart_list = Session::get('_cart');

		foreach ($cart_list as $key => $list) {
			$data['days'] += $list['time_shipping']['days'];
			$data['total'] += $list['time_shipping']['price'];
			$data['items'][] = $list['time_shipping'];
		}

		return $data;
	}

	private function getCartProduction()
	{
		$data = [
			"days" => 0,
			"total" => 0,
			"items" => [],
		];

		$cart_list = Session::get('_cart');

		foreach ($cart_list as $key => $list) {
			$data['days'] += $list['time_production']['days'];
			$data['total'] += $list['time_production']['price'];
			$data['items'][] = $list['time_production'];
		}

		return $data;
	}

	private function getCartGrandTotal()
	{
		$gtotal = 0;

		$cart_list = Session::get('_cart');

		foreach ($cart_list as $key => $list) {
			$gtotal += $list['total'];
		}

		return $gtotal;
	}

	private function getWristbandsSizeName($size="")
	{
		$name = "";
		switch($size) {
			case '0-25inch':
				$name = "1/4 inch";
				break;
			case '0-50inch':
				$name = "1/2 inch";
				break;
			case '0-75inch':
				$name = "3/4 inch";
				break;
			case '1-00inch':
				$name = "1 inch";
				break;
			case '1-50inch':
				$name = "1 1/2 inch";
				break;
			case '2-00inch':
				$name = "2 inch";
				break;
			default:
				$name = "1/2 inch";
				break;
		}
		return $name;
	}

	private function getWristbandItemSizeName($size="")
	{
		$name = "";
		switch($size) {
			case 'yt':
				$name = " (Youth)";
				break;
			case 'md':
				$name = " (Medium)";
				break;
			case 'ad':
				$name = " (Adult)";
				break;
			case 'xs':
				$name = " (Extra Small)";
				break;
			case 'xl':
				$name = " (Extra Large)";
				break;
		}
		return $name;
	}

	public function generateBreakDown($cart=[], $discountCode="")
	{
		$count = 0;
		$items = [];
		$overall_discount = 0;
		$overall_prod = 0;
		$overall_ship = 0;
		$overall_items = 0;
		$overall_total = 0;
		$shipping_subtotal = 0;
		$cart = (isset($cart) ? $cart : (Session::has("_cart") ? Session::get("_cart") : []));

		foreach ($cart as $value) {

			$overall_prod += $value['time_production']['price'];
			$overall_ship += $value['time_shipping']['price'];

			$price = $value['total'] - ($value['time_production']['price'] + $value['time_shipping']['price']);

			$items[] = [
				"name" => "Order #" . ++$count,
				"currency" => "USD",
				"quantity" => 1,
				"price" => number_format($price, 2)
			];

			$overall_total += $price;

		}

		// For production price, if any
		$items[] = [
			"name" => "Production",
			"currency" => "USD",
			"quantity" => 1,
			"price" => number_format($overall_prod, 2)
		];

		$overall_items = $overall_total + $overall_prod;
		$overall_total = $overall_total + $overall_ship + $overall_prod;

		// FOR DISCOUNT
		$discount = 0;
		$discount_ship = 0;
		$discountPercent = 0;
		$discountTrueCode = "";

		if (!empty($discountCode)) {
			if ($discountModel = Discounts::where('Code', strtoupper($discountCode))->get()->first()) {

				$discount = $overall_items * ($discountModel->Percentage / 100);
				$discount = number_format($discount, "2");

				$discount_ship = $overall_ship * ($discountModel->Percentage / 100);
				$discount_ship = number_format($discount_ship, "2");

				$overall_discount = $discount + $discount_ship;

				$discountTrueCode = $discountCode;
				$discountPercent = $discountModel->Percentage;

			}
		}

		if ($overall_discount > 0) { // If has discount

			$items[] = [
				"name" => "Discount",
				"currency" => "USD",
				"quantity" => 1,
				"price" => "-" . number_format($overall_discount, 2),
				"total_discount" => number_format($overall_discount, 2)
			];

		}

		// FOR SHIPPING
		$shipping_total = $overall_ship;

		$details = [
			"shipping" => number_format($shipping_total, 2),
			"tax" => 0,
			"subtotal" => number_format(($overall_total - $overall_discount) - $shipping_total, 2), // Grand total - discount total
			"total" => number_format($overall_ship, 2)
		];

		// FOR AMOUNT
		$amount = [
			"currency" => "USD",
			"total" => number_format(($overall_total - $overall_discount), 2),
		];

		$overall_total = number_format($overall_total, 2);

		return [
			"total" => $overall_total,
			"items" => $items,
			"details" => $details,
			"amount" => $amount,
			"discountCode" => $discountTrueCode,
			"discountPercent" => $discountPercent
		];

	}

    public function getDiscountsVerify(Request $request)
    {
        if (!empty($request->code)) {
			return json_encode([ 'status' => true, 'breakdown' => $this->generateBreakDown(null, $request->code) ]);
        }
        return json_encode([ 'status' => false ]);
    }

}
