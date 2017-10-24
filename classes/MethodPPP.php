<?php
/**
 * 2007-2017 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2017 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Api\Patch;
use PayPal\Api\PatchRequest;
use PayPal\Api\PaymentExecution;
use PayPal\Api\Refund;
use PayPal\Api\RefundRequest;
use PayPal\Api\Sale;

require(_PS_MODULE_DIR_.'paypal/sdk/paypalREST/vendor/autoload.php');

class MethodPPP extends AbstractMethodPaypal
{
    public $name = 'paypal';

    public function setConfig($params)
    {
        $paypal = Module::getInstanceByName($this->name);
        if (Tools::isSubmit('paypal_config')) {
            Configuration::updateValue('PAYPAL_API_ADVANTAGES', $params['paypal_show_advantage']);
            Configuration::updateValue('PAYPAL_PPP_CONFIG_TITLE', $params['ppp_config_title']);
            Configuration::updateValue('PAYPAL_PPP_CONFIG_BRAND', $params['ppp_config_brand']);

            if (isset($_FILES['ppp_config_logo']['tmp_name']) && $_FILES['ppp_config_logo']['tmp_name'] != '') {
                if (!($tmpName = tempnam(_PS_TMP_IMG_DIR_, 'PS')) ||
                !move_uploaded_file($_FILES['ppp_config_logo']['tmp_name'], $tmpName)) {
                    $paypal->errors .= $paypal->displayError($paypal->l('An error occurred while copying the image.'));
                }
                if (!ImageManager::resize($tmpName, _PS_MODULE_DIR_.'paypal/views/img/ppp_logo.png')) {
                    $paypal->errors .= $paypal->displayError($paypal->l('An error occurred while copying the image.'));
                }
                Configuration::updateValue('PAYPAL_PPP_CONFIG_LOGO', _PS_MODULE_DIR_.'paypal/views/img/ppp_logo.png');
            }
            if ((Configuration::get('PAYPAL_SANDBOX') && Configuration::get('PAYPAL_SANDBOX_CLIENTID') && Configuration::get('PAYPAL_SANDBOX_SECRET'))
                || (!Configuration::get('PAYPAL_SANDBOX') && Configuration::get('PAYPAL_LIVE_CLIENTID') && Configuration::get('PAYPAL_LIVE_SECRET'))) {
                $experience_web = $this->createWebExperience();
                if ($experience_web) {
                    Configuration::updateValue('PAYPAL_PLUS_EXPERIENCE', $experience_web->id);
                } else {
                    $paypal->errors .= $paypal->displayError($paypal->l('An error occurred while creating your web experience. Check your credentials.'));
                }
            }
        }

        if (Tools::isSubmit('save_credentials')) {
            $sandbox = Tools::getValue('sandbox');
            $live = Tools::getValue('live');
            if ($sandbox['client_id'] && $sandbox['secret'] && (!$live['client_id'] || !$live['secret'])) {
                Configuration::updateValue('PAYPAL_SANDBOX', 1);
            }
            Configuration::updateValue('PAYPAL_SANDBOX_CLIENTID', $sandbox['client_id']);
            Configuration::updateValue('PAYPAL_SANDBOX_SECRET', $sandbox['secret']);
            Configuration::updateValue('PAYPAL_LIVE_CLIENTID', $live['client_id']);
            Configuration::updateValue('PAYPAL_LIVE_SECRET', $live['secret']);
            Configuration::updateValue('PAYPAL_METHOD', 'PPP');
            Configuration::updateValue('PAYPAL_PLUS_ENABLED', 1);

            if ((Configuration::get('PAYPAL_SANDBOX') && $sandbox['client_id'] && $sandbox['secret'])
            || (!Configuration::get('PAYPAL_SANDBOX') && $live['client_id'] && $live['secret'])) {
                $experience_web = $this->createWebExperience();
                if ($experience_web) {
                    Configuration::updateValue('PAYPAL_PLUS_EXPERIENCE', $experience_web->id);
                } else {
                    $paypal->errors .= $paypal->displayError($paypal->l('An error occurred while creating your web experience. Check your credentials.'));
                }
            }
        }
    }

    public function createWebExperience()
    {

        $brand_name = Configuration::get('PAYPAL_PPP_CONFIG_BRAND')?Configuration::get('PAYPAL_PPP_CONFIG_BRAND'):Configuration::get('PS_SHOP_NAME');
        $brand_logo = file_exists(_PS_MODULE_DIR_.'paypal/views/img/ppp_logo.png')?Context::getContext()->link->getBaseLink(Context::getContext()->shop->id, true).'modules/paypal/views/img/ppp_logo.png':Context::getContext()->link->getBaseLink().'img/'.Configuration::get('PS_LOGO');

        $flowConfig = new \PayPal\Api\FlowConfig();
        // Type of PayPal page to be displayed when a user lands on the PayPal site for checkout. Allowed values: Billing or Login. When set to Billing, the Non-PayPal account landing page is used. When set to Login, the PayPal account login landing page is used.
        $flowConfig->setLandingPageType("Billing");
        // The URL on the merchant site for transferring to after a bank transfer payment.
        $flowConfig->setBankTxnPendingUrl(Context::getContext()->link->getModuleLink($this->name, 'pppValidation', array(), true));
        // When set to "commit", the buyer is shown an amount, and the button text will read "Pay Now" on the checkout page.
        $flowConfig->setUserAction("commit");
        // Defines the HTTP method to use to redirect the user to a return URL. A valid value is `GET` or `POST`.
        $flowConfig->setReturnUriHttpMethod("GET");
        // Parameters for style and presentation.
        $presentation = new \PayPal\Api\Presentation();
        // A URL to logo image. Allowed vaues: .gif, .jpg, or .png.
        $presentation->setLogoImage($brand_logo)
        //	A label that overrides the business name in the PayPal account on the PayPal pages.
            ->setBrandName($brand_name)
        //  Locale of pages displayed by PayPal payment experience.
            ->setLocaleCode(Country::getIsoById(Configuration::get('PS_COUNTRY_DEFAULT')))
        // A label to use as hypertext for the return to merchant link.
            ->setReturnUrlLabel("Return")
        // A label to use as the title for the note to seller field. Used only when `allow_note` is `1`.
            ->setNoteToSellerLabel("Thanks!");
        // Parameters for input fields customization.
        $inputFields = new \PayPal\Api\InputFields();
        // Enables the buyer to enter a note to the merchant on the PayPal page during checkout.
        $inputFields->setAllowNote(false)
            // Determines whether or not PayPal displays shipping address fields on the experience pages. Allowed values: 0, 1, or 2. When set to 0, PayPal displays the shipping address on the PayPal pages. When set to 1, PayPal does not display shipping address fields whatsoever. When set to 2, if you do not pass the shipping address, PayPal obtains it from the buyer’s account profile. For digital goods, this field is required, and you must set it to 1.
            ->setNoShipping(1)
            // Determines whether or not the PayPal pages should display the shipping address and not the shipping address on file with PayPal for this buyer. Displaying the PayPal street address on file does not allow the buyer to edit that address. Allowed values: 0 or 1. When set to 0, the PayPal pages should not display the shipping address. When set to 1, the PayPal pages should display the shipping address.
            ->setAddressOverride(0);
        // #### Payment Web experience profile resource
        $webProfile = new \PayPal\Api\WebProfile();
        // Name of the web experience profile. Required. Must be unique
        $webProfile->setName(Tools::substr(Configuration::get('PS_SHOP_NAME'), 0, 30) . uniqid())
            // Parameters for flow configuration.
            ->setFlowConfig($flowConfig)
            // Parameters for style and presentation.
            ->setPresentation($presentation)
            // Parameters for input field customization.
            ->setInputFields($inputFields)
            // Indicates whether the profile persists for three hours or permanently. Set to `false` to persist the profile permanently. Set to `true` to persist the profile for three hours.
            ->setTemporary(false);
        // For Sample Purposes Only.
        try {
            // Use this call to create a profile.
            $createProfileResponse = $webProfile->create($this->_getCredentialsInfo());
        } catch (\PayPal\Exception\PayPalConnectionException $ex) {
            return false;
        }

        return $createProfileResponse;
    }

    public function _getCredentialsInfo()
    {
        switch (Configuration::get('PAYPAL_SANDBOX')) {
            case 0:
                $apiContext = new ApiContext(
                    new OAuthTokenCredential(
                        Configuration::get('PAYPAL_LIVE_CLIENTID'),
                        Configuration::get('PAYPAL_LIVE_SECRET')
                    )
                );
                break;
            case 1:
                $apiContext = new ApiContext(
                    new OAuthTokenCredential(
                        Configuration::get('PAYPAL_SANDBOX_CLIENTID'),
                        Configuration::get('PAYPAL_SANDBOX_SECRET')
                    )
                );
                break;
        }
        $apiContext->setConfig(
            array(
                'mode' => Configuration::get('PAYPAL_SANDBOX') ? 'sandbox' : 'live',
                'log.LogEnabled' => false,
                'cache.enabled' => false,
            )
        );
        return $apiContext;
    }

    public function getConfig(Paypal $module)
    {
        $params = array('inputs' => array(
            array(
                'type' => 'text',
                'label' => $module->l('Title'),
                'name' => 'ppp_config_title',
                'placeholder' => $module->l('Leave it empty to use default PayPal payment method title'),
            ),
            array(
                'type' => 'text',
                'label' => $module->l('Brand name'),
                'name' => 'ppp_config_brand',
                'placeholder' => $module->l('Leave it empty to use your Shop name'),
            ),
            array(
                'type' => 'file',
                'label' => $module->l('Shop logo field'),
                'name' => 'ppp_config_logo',
                'hint' => $module->l('Leave it empty to use your default shop logo'),
                'thumb' => file_exists(_PS_MODULE_DIR_.'paypal/views/img/ppp_logo.png')?Context::getContext()->link->getBaseLink().'modules/paypal/views/img/ppp_logo.png':''
            ),
            array(
                'type' => 'switch',
                'label' => $module->l('Show PayPal benefits to your customers'),
                'name' => 'paypal_show_advantage',
                'desc' => $module->l(''),
                'is_bool' => true,
                'hint' => $module->l('You can increase your conversion rate by presenting PayPal benefits to your customers on payment methods selection page.'),
                'values' => array(
                    array(
                        'id' => 'paypal_show_advantage_on',
                        'value' => 1,
                        'label' => $module->l('Enabled'),
                    ),
                    array(
                        'id' => 'paypal_show_advantage_off',
                        'value' => 0,
                        'label' => $module->l('Disabled'),
                    )
                ),
            )
        ));

        $params['fields_value'] = array(
            'ppp_config_title' => Configuration::get('PAYPAL_PPP_CONFIG_TITLE'),
            'ppp_config_brand' => Configuration::get('PAYPAL_PPP_CONFIG_BRAND'),
            'ppp_config_logo' => Configuration::get('PAYPAL_PPP_CONFIG_LOGO'),
            'paypal_show_advantage' => Configuration::get('PAYPAL_API_ADVANTAGES'),
        );

        $context = Context::getContext();
        $context->smarty->assign(array(
            'ppp_active' => Configuration::get('PAYPAL_PLUS_ENABLED'),
            'PAYPAL_SANDBOX_CLIENTID' => Configuration::get('PAYPAL_SANDBOX_CLIENTID'),
            'PAYPAL_SANDBOX_SECRET' => Configuration::get('PAYPAL_SANDBOX_SECRET'),
            'PAYPAL_LIVE_CLIENTID' => Configuration::get('PAYPAL_LIVE_CLIENTID'),
            'PAYPAL_LIVE_SECRET' => Configuration::get('PAYPAL_LIVE_SECRET'),
        ));

        return $params;
    }

    public function init($params)
    {
        $payer = new Payer();
        $payer->setPaymentMethod("paypal");
        // ### Itemized information
        // (Optional) Lets you specify item wise information
        $itemTotalValue = 0;
        $taxTotalValue = 0;
        $items = array();
        $itemList = new ItemList();
        $amount = new Amount();

        $this->_getPaymentDetails($items, $itemTotalValue, $taxTotalValue, $itemList, $amount);

        // ### Transaction
        // A transaction defines the contract of a
        // payment - what is the payment for and who
        // is fulfilling it.


        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($itemList)
            ->setDescription("Payment description")
            ->setInvoiceNumber(uniqid());

        // ### Redirect urls
        // Set the urls that the buyer must be redirected to after
        // payment approval/ cancellation.

        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl(Context::getContext()->link->getModuleLink($this->name, 'pppValidation', array(), true))
            ->setCancelUrl(Context::getContext()->link->getPageLink('order', true).'&step=1');

        // ### Payment
        // A Payment Resource; create one using
        // the above types and intent set to 'sale'

        $payment = new Payment();
        $payment->setIntent("sale")
            ->setPayer($payer)
            ->setRedirectUrls($redirectUrls)
            ->setTransactions(array($transaction))
            ->setExperienceProfileId(Configuration::get('PAYPAL_PLUS_EXPERIENCE'));

        // ### Create Payment
        // Create a payment by calling the 'create' method
        // passing it a valid apiContext.
        // The return object contains the state and the
        // url to which the buyer must be redirected to
        // for payment approval

        $payment->create($this->_getCredentialsInfo());

        // ### Get redirect url
        // The API response provides the url that you must redirect
        // the buyer to. Retrieve the url from the $payment->getApprovalLink() method
        return array('approval_url' => $payment->getApprovalLink(), 'payment_id' => $payment->id);
    }

    private function _getPaymentDetails(&$items, &$total_products, &$tax, &$itemList, &$amount)
    {
        $tax = $total_products = 0;
        $this->_getProductsList($items, $total_products, $tax);
        $this->_getDiscountsList($items, $total_products);
        $this->_getGiftWrapping($items, $total_products);
        $this->_getPaymentValues($items, $total_products, $tax, $itemList, $amount);
    }

    private function _getProductsList(&$items, &$itemTotalValue, &$taxTotalValue)
    {
        $products = Context::getContext()->cart->getProducts();
        $items = array();
        foreach ($products as $product) {
            $product['product_tax'] = $product['price_wt'] - $product['price'];
            $item = new Item();
            $item->setName(substr($product['name'],0, 126))
                ->setCurrency(Context::getContext()->currency->iso_code)
                ->setDescription($product['attributes'])
                ->setQuantity($product['quantity'])
                ->setSku($product['id_product']) // Similar to `item_number` in Classic API
                ->setPrice(number_format($product['price'], 2, ".", ''));

            $items[] = $item;
            $itemTotalValue += number_format($product['price'], 2, ".", '') * $product['quantity'];
            $taxTotalValue += number_format($product['product_tax'], 2, ".", '') * $product['quantity'];
        }
    }

    private function _getDiscountsList(&$items, &$itemTotalValue)
    {
        $discounts = Context::getContext()->cart->getCartRules();
        if (count($discounts) > 0) {
            foreach ($discounts as $discount) {
                if (isset($discount['description']) && !empty($discount['description'])) {
                    $discount['description'] = Tools::substr(strip_tags($discount['description']), 0, 50).'...';
                }
                $discount['value_real'] = -1 * number_format($discount['value_real'], 2, ".", '');
                $item = new Item();
                $item->setName($discount['name'])
                    ->setCurrency(Context::getContext()->currency->iso_code)
                    ->setQuantity(1)
                    ->setSku($discount['code']) // Similar to `item_number` in Classic API
                    ->setPrice($discount['value_real']);
                $items[] = $item;
                $itemTotalValue += number_format($discount['value_real'], 2, ".", '');
            }
        }
    }

    private function _getGiftWrapping(&$items, &$itemTotalValue)
    {
        $wrapping_price = Context::getContext()->cart->gift ? Context::getContext()->cart->getGiftWrappingPrice() : 0;
        if ($wrapping_price > 0) {
            $wrapping_price = number_format($wrapping_price, 2, ".", '');
            $item = new Item();
            $item->setName('Gift wrapping')
                ->setCurrency(Context::getContext()->currency->iso_code)
                ->setQuantity(1)
                ->setSku('wrapping') // Similar to `item_number` in Classic API
                ->setPrice($wrapping_price);
            $items[] = $item;
            $itemTotalValue += $wrapping_price;
        }
    }

    private function _getPaymentValues(&$items, &$itemTotalValue, &$taxTotalValue, &$itemList, &$amount)
    {
        $itemList->setItems($items);
        $context = Context::getContext();
        $currency = $context->currency->iso_code;
        $cart = $context->cart;
        $shipping_cost_wt = $cart->getTotalShippingCost();
        $shipping = round($shipping_cost_wt, 2);
        $total = $cart->getOrderTotal(true, Cart::BOTH);
        $summary = $cart->getSummaryDetails();
        $subtotal = Tools::ps_round($summary['total_products'], 2);
        $total_tax = round($taxTotalValue, 2);
        // total shipping amount
        $shippingTotal = number_format($shipping, 2, ".", '');

        if ($subtotal != $itemTotalValue) {
            $subtotal = $itemTotalValue;
        }
        //total
        $total_cart = $shippingTotal + $itemTotalValue + $taxTotalValue;

        if ($total != $total_cart) {
            $total = $total_cart;
        }

        // ### Additional payment details
        // Use this optional field to set additional
        // payment information such as tax, shipping
        // charges etc.
        $details = new Details();
        $details->setShipping($shippingTotal)
            ->setTax(number_format($total_tax, 2, ".", ''))
            ->setSubtotal(number_format($subtotal, 2, ".", ''));
        // ### Amount
        // Lets you specify a payment amount.
        // You can also specify additional details
        // such as shipping, tax.
        $amount->setCurrency($currency)
            ->setTotal(number_format($total, 2, ".", ''))
            ->setDetails($details);
    }

    public function doPatch()
    {
        // Retrieve the payment object by calling the tatic `get` method
        // on the Payment class by passing a valid Payment ID
        $payment = Payment::get(Context::getContext()->cookie->paypal_plus_payment, $this->_getCredentialsInfo());

        $cart = new Cart(Context::getContext()->cart->id);
        $address_delivery = new Address($cart->id_address_delivery);

        $state = '';
        if ($address_delivery->id_state) {
            $state = new State((int) $address_delivery->id_state);
        }
        $state_name = $state ? $state->iso_code : '';
        $patchAdd = new Patch();
        $patchAdd->setOp('add')
            ->setPath('/transactions/0/item_list/shipping_address')
            ->setValue(json_decode('{
                    "recipient_name": "'.$address_delivery->firstname.' '.$address_delivery->lastname.'",
                    "line1": "'.$address_delivery->address1.'",
                    "city": "'.$address_delivery->city.'",
                    "state": "'.$state_name.'",
                    "postal_code": "'.$address_delivery->postcode.'",
                    "country_code": "'.Country::getIsoById($address_delivery->id_country).'"
                }'));

        $patchRequest = new PatchRequest();
        $patchRequest->setPatches(array($patchAdd));
        return $payment->update($patchRequest, $this->_getCredentialsInfo());
    }

    public function validation()
    {
        $context = Context::getContext();
        // Get the payment Object by passing paymentId
        // payment id was previously stored in session in
        // CreatePaymentUsingPayPal.php
        $paymentId = Tools::getValue('paymentId');
        $payment = Payment::get($paymentId, $this->_getCredentialsInfo());
        // ### Payment Execute
        // PaymentExecution object includes information necessary
        // to execute a PayPal account payment.
        // The payer_id is added to the request query parameters
        // when the user is redirected from paypal back to your site
        $execution = new PaymentExecution();
        $execution->setPayerId(Tools::getValue('PayerID'));
        // ### Optional Changes to Amount
        // If you wish to update the amount that you wish to charge the customer,
        // based on the shipping address or any other reason, you could
        // do that by passing the transaction object with just `amount` field in it.
        $itemTotalValue = 0;
        $taxTotalValue = 0;
        $items = array();
        $itemList = new ItemList();
        $amount = new Amount();

        $this->_getPaymentDetails($items, $itemTotalValue, $taxTotalValue, $itemList, $amount);

        $transaction = new Transaction();
        $transaction->setAmount($amount);
        // Add the above transaction object inside our Execution object.
        $execution->addTransaction($transaction);
        // Execute the payment
        $exec_payment = $payment->execute($execution, $this->_getCredentialsInfo());

        $cart = $context->cart;
        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }
        $currency = $context->currency;
        $total = (float)$exec_payment->transactions[0]->amount->total;
        $paypal = Module::getInstanceByName('paypal');
        $order_state = Configuration::get('PS_OS_PAYMENT');
        $transactionDetail = $this->getDetailsTransaction($exec_payment);
        $paypal->validateOrder($cart->id, $order_state, $total, 'PayPal', null, $transactionDetail, (int)$currency->id, false, $customer->secure_key);
        return true;

    }

    public function getDetailsTransaction($transaction)
    {
        $payment_info = $transaction->transactions[0];

        return array(
            'method' => 'PPP',
            'currency' => $payment_info->amount->currency,
            'transaction_id' => pSQL($payment_info->related_resources[0]->sale->id),
            'payment_status' => $transaction->state,
            'payment_method' => $transaction->payer->payment_method,
            'id_payment' => pSQL($transaction->id),
            'client_token' => "",
            'capture' => false,
            'payment_tool' => isset($transaction->payment_instruction)?$transaction->payment_instruction->instruction_type:'',
        );
    }

    public function confirmCapture()
    {

    }
    public function check()
    {

    }

    public function refund()
    {
        $paypal_order = PaypalOrder::loadByOrderId(Tools::getValue('id_order'));

        $sale = Sale::get($paypal_order->id_transaction, $this->_getCredentialsInfo());

        // Includes both the refunded amount (to Payer)
        // and refunded fee (to Payee). Use the $amt->details
        // field to mention fees refund details.
        $amt = new Amount();
        $amt->setCurrency($sale->getAmount()->getCurrency())
            ->setTotal($sale->getAmount()->getTotal());
        $refundRequest = new RefundRequest();
        $refundRequest->setAmount($amt);

        $response = $sale->refundSale($refundRequest, $this->_getCredentialsInfo());

        $result =  array(
            'success' => true,
            'refund_id' => $response->id,
            'status' => $response->state,
            'total_amount' => $response->total_refunded_amount->value,
            'currency' => $response->total_refunded_amount->currency,
            'saleId' => $response->sale_id,
        );

        return $result;
    }

    public function void($params)
    {

    }

    public function getInstructionInfo($id_payment)
    {
        $sale = Payment::get($id_payment, $this->_getCredentialsInfo());
        return $sale->payment_instruction;
    }
}