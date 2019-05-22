<?php
namespace Ceevo\Services;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Basket\Models\BasketItem;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Payment\Method\Models\PaymentMethod;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Item\Variation\Contracts\VariationRepositoryContract;
use Plenty\Modules\Order\RelationReference\Models\OrderRelationReference;

class PaymentService
{
    use Loggable;
    /**
     *
     * @var WalleeSdkService
     */
    private $sdkService;
    /**
     *
     * @var ConfigRepository
     */
    private $config;
    /**
     *
     * @var ItemRepositoryContract
     */
    private $itemRepository;
    /**
     *
     * @var VariationRepositoryContract
     */
    private $variationRepository;
    /**
     *
     * @var FrontendSessionStorageFactoryContract
     */
    private $session;
    /**
     *
     * @var AddressRepositoryContract
     */
    private $addressRepository;
    /**
     *
     * @var CountryRepositoryContract
     */
    private $countryRepository;
    /**
     *
     * @var WebstoreHelper
     */
    private $webstoreHelper;
    /**
     *
     * @var OrderHelper
     */
    private $orderHelper;
    /**
     *
     * @var OrderRepositoryContract
     */
    private $orderRepository;
    /**
     * Constructor.
     *
     * @param ConfigRepository $config
     * @param ItemRepositoryContract $itemRepository
     * @param VariationRepositoryContract $variationRepository
     * @param FrontendSessionStorageFactoryContract $session
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param OrderRepositoryContract $orderRepository
     */
    public function __construct( ConfigRepository $config, ItemRepositoryContract $itemRepository, VariationRepositoryContract $variationRepository, FrontendSessionStorageFactoryContract $session, AddressRepositoryContract $addressRepository, CountryRepositoryContract $countryRepository, WebstoreHelper $webstoreHelper,  OrderRepositoryContract $orderRepository)
    {
        $this->config = $config;
        $this->itemRepository = $itemRepository;
        $this->variationRepository = $variationRepository;
        $this->session = $session;
        $this->addressRepository = $addressRepository;
        $this->countryRepository = $countryRepository;
        $this->webstoreHelper = $webstoreHelper;
        $this->orderRepository = $orderRepository;
    }
    /**
     * Returns the payment method's content.
     *
     * @param Basket $basket
     * @param array $basketForTemplate
     * @param PaymentMethod $paymentMethod
     * @return string[]
     */
    public function getPaymentContent(Basket $basket, array $basketForTemplate, PaymentMethod $paymentMethod): array
    {
        $this->createWebhook();
        $parameters = [
            'transactionId' => $this->session->getPlugin()->getValue('walleeTransactionId'),
            'basket' => $basket,
            'basketForTemplate' => $basketForTemplate,
            'paymentMethod' => $paymentMethod,
            'basketItems' => $this->getBasketItems($basket),
            'billingAddress' => $this->getAddress($this->getBasketBillingAddress($basket)),
            'shippingAddress' => $this->getAddress($this->getBasketShippingAddress($basket)),
            'language' => $this->session->getLocaleSettings()->language,
            'successUrl' => $this->getSuccessUrl(),
            'failedUrl' => $this->getFailedUrl(),
            'checkoutUrl' => $this->getCheckoutUrl()
        ];
        $this->getLogger(__METHOD__)->debug('wallee::TransactionParameters', $parameters);
        $transaction = $this->sdkService->call('createTransactionFromBasket', $parameters);
        if (is_array($transaction) && isset($transaction['error'])) {
            return [
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => $transaction['error_msg']
            ];
        }
        $this->session->getPlugin()->setValue('walleeTransactionId', $transaction['id']);
        $hasPossiblePaymentMethods = $this->sdkService->call('hasPossiblePaymentMethods', [
            'transactionId' => $transaction['id']
        ]);
        if (! $hasPossiblePaymentMethods) {
            return [
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => 'The selected payment method is not available.'
            ];
        }
        return [
            'type' => GetPaymentMethodContent::RETURN_TYPE_CONTINUE
        ];
    }
    private function createWebhook()
    {
        /** @var \Plenty\Modules\Helper\Services\WebstoreHelper $webstoreHelper */
        $webstoreHelper = pluginApp(\Plenty\Modules\Helper\Services\WebstoreHelper::class);
        /** @var \Plenty\Modules\System\Models\WebstoreConfiguration $webstoreConfig */
        $webstoreConfig = $webstoreHelper->getCurrentWebstoreConfiguration();
        $this->sdkService->call('createWebhook', [
            'storeId' => $webstoreConfig->webstoreId,
            'notificationUrl' => $webstoreConfig->domainSsl . '/wallee/update-transaction' . ($this->config->get('plenty.system.info.urlTrailingSlash', 0) == 2 ? '/' : '')
        ]);
    }
    /**
     * Creates the payment in plentymarkets.
     *
     * @param Order $order
     * @param PaymentMethod $paymentMethod
     * @return string[]
     */
    public function executePayment(Order $order, PaymentMethod $paymentMethod): array
    {

        $this->getLogger(__METHOD__)->error('inside payment service', $order);
        $this->getLogger(__METHOD__)->error('inside payment service', $order->orderItems);
        $this->getLogger(__METHOD__)->error('inside payment service',$order->billingAddress);
        $this->getLogger(__METHOD__)->error('inside payment service', $this->config->get('Ceevo.clientId'));
        $this->getLogger(__METHOD__)->error('inside payment service', $order->amounts[0]->currency);
        //$this->getLogger(__METHOD__)->error('inside payment service',$this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'));
        $customer = $this->createCustomer($order);
        // $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');
        // $parameters = [
        //     'transactionId' => $transactionId,
        //     'order' => $order,
        //     'paymentMethod' => $paymentMethod,
        //     'billingAddress' => $this->getAddress($order->billingAddress),
        //     'shippingAddress' => $this->getAddress($order->deliveryAddress),
        //     'language' => $this->session->getLocaleSettings()->language,
        //     'customerId' => $this->orderHelper->getOrderRelationId($order, OrderRelationReference::REFERENCE_TYPE_CONTACT),
        //     'successUrl' => $this->getSuccessUrl(),
        //     'failedUrl' => $this->getFailedUrl(),
        //     'checkoutUrl' => $this->getCheckoutUrl()
        // ];
        // $this->getLogger(__METHOD__)->debug('wallee::TransactionParameters', $parameters);
        // $this->session->getPlugin()->unsetKey('walleeTransactionId');
        // $transaction = $this->sdkService->call('createTransactionFromOrder', $parameters);
        // if (is_array($transaction) && $transaction['error']) {
        //     return [
        //         'transactionId' => $transactionId,
        //         'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
        //         'content' => $transaction['error_msg']
        //     ];
        // }
        // $payment = $this->paymentHelper->createPlentyPayment($transaction);
        // $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, $order->id);
        // $hasPossiblePaymentMethods = $this->sdkService->call('hasPossiblePaymentMethods', [
        //     'transactionId' => $transaction['id']
        // ]);
        // if (! $hasPossiblePaymentMethods) {
        //     return [
        //         'transactionId' => $transaction['id'],
        //         'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
        //         'content' => 'The selected payment method is not available.'
        //     ];
        // }
        // $paymentPageUrl = $this->sdkService->call('buildPaymentPageUrl', [
        //     'id' => $transaction['id']
        // ]);
        // if ($customer['message'] == "Approved") {
        //     return [
        //         'transactionId' => $customer['paymentId'],
        //         'type' => "success",
        //     ];
        // }
        return [
            'type' => "error",
        ];
    }
    /**
     *
     * @param Basket $basket
     * @return Address
     */
    private function getBasketBillingAddress(Basket $basket): Address
    {
        $addressId = $basket->customerInvoiceAddressId;
        return $this->addressRepository->findAddressById($addressId);
    }
    /**
     *
     * @param Basket $basket
     * @return Address
     */
    private function getBasketShippingAddress(Basket $basket)
    {
        $addressId = $basket->customerShippingAddressId;
        if ($addressId != null && $addressId != - 99) {
            return $this->addressRepository->findAddressById($addressId);
        } else {
            return $this->getBasketBillingAddress($basket);
        }
    }
    /**
     *
     * @param Address $address
     * @return array
     */
    private function getAddress(Address $address): array
    {
        $birthday = $address->birthday;
        if (empty($birthday) || ! preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', $birthday)) {
            $birthday = null;
        }
        return [
            'city' => $address->town,
            'gender' => $address->gender,
            'country' => $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'),
            'dateOfBirth' => $birthday,
            'emailAddress' => $address->email,
            'familyName' => $address->lastName,
            'givenName' => $address->firstName,
            'organisationName' => $address->companyName,
            'phoneNumber' => $address->phone,
            'postCode' => $address->postalCode,
            'street' => $address->street . ' ' . $address->houseNumber
        ];
    }
    /**
     *
     * @param Basket $basket
     * @return array
     */
    private function getBasketItems(Basket $basket): array
    {
        $items = [];
        /** @var BasketItem $basketItem */
        foreach ($basket->basketItems as $basketItem) {
            $item = $basketItem->getAttributes();
            $item['name'] = $this->getBasketItemName($basketItem);
            $items[] = $item;
        }
        return $items;
    }
    /**
     *
     * @param BasketItem $basketItem
     * @return string
     */
    private function getBasketItemName(BasketItem $basketItem): string
    {
        /** @var \Plenty\Modules\Item\Item\Models\Item $item */
        $item = $this->itemRepository->show($basketItem->itemId);
        /** @var \Plenty\Modules\Item\Item\Models\ItemText $itemText */
        $itemText = $item->texts;
        if (! empty($itemText) && ! empty($itemText->first()->name1)) {
            return $itemText->first()->name1;
        } else {
            return "Product";
        }
    }
    /**
     *
     * @return string
     */
    private function getSuccessUrl(): string
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/confirmation';
    }
    /**
     *
     * @return string
     */
    private function getFailedUrl(): string
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/wallee/fail-transaction';
    }
    /**
     *
     * @return string
     */
    private function getCheckoutUrl(): string
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/checkout';
    }
    /**
     *
     * @param number $transactionId
     * @param Order $order
     */
    public function refund($transactionId, Order $refundOrder, Order $order)
    {
        $this->getLogger(__METHOD__)->debug('wallee:RefundOrder', [
            'transactionId' => $transactionId,
            'refundOrder' => $refundOrder,
            'order' => $order
        ]);
        try {
            $refund = $this->sdkService->call('createRefund', [
                'transactionId' => $transactionId,
                'refundOrder' => $refundOrder,
                'order' => $order
            ]);
            if (is_array($refund) && $refund['error']) {
                throw new \Exception($refund['error_msg']);
            }
           // $payment = $this->paymentHelper->createRefundPlentyPayment($refund);
            //$this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, $refundOrder->id);
            $this->orderRepository->updateOrder([
                'statusId' => $this->getRefundSuccessfulStatus()
            ], $refundOrder->id);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('The refund failed.', $e);
            $this->orderRepository->updateOrder([
                'statusId' => $this->getRefundFailedStatus()
            ], $refundOrder->id);
        }
    }
    private function getRefundSuccessfulStatus()
    {
        $status = $this->config->get('wallee.refund_successful_status');
        if (empty($status)) {
            return '11.2';
        } else {
            return $status;
        }
    }
    private function getRefundFailedStatus()
    {
        $status = $this->config->get('wallee.refund_failed_status');
        if (empty($status)) {
            return '11.3';
        } else {
            return $status;
        }
    }

    function createCustomer($order){

        $billingAddress = $order->billingAddress;
        $data = array("billing" => array("city" => $billingAddress->town, "country" => $this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2'),"state" => $billingAddress->town,"street" => $billingAddress->address1,"zip"=> $billingAddress->postalCode),
                      "email" => $billingAddress->options[0]->value,"firstName" => $billingAddress->name2,"lastName" => $billingAddress->name3,"mobile" => "","phone" => "","sex" => "M");  
        $this->getLogger(__METHOD__)->error('insidecreate customer', $data);
        $data_string = json_encode($data);
        $get_data = $this->callAPI('POST', 'https://api.ceevo.com/acquiring/customer', $data);
        $response = json_decode($get_data, true);
    
       // $this->registerAccountToken($resonse,$order);
        $chargeResponse = $this->chargeApi($order);
        return $chargeResponse;
    
    }
    
    function registerAccountToken($customer_registered_id,$order){
        // $token_array = array("accountToken" => $order->info['customerToken'],"default" => true);
        // $token_string = json_encode($token_array);
        // $get_data = $this->callAPI('POST', 'https://api.ceevo.com/acquiring/customer/'.$customer_registered_id, $token_string);
        // $response = json_decode($get_data, true);
    
    }
    
    
    function chargeApi($order){
        
        $billingAddress = $order->billingAddress;
        $api = "https://auth.ceevo.com/auth/realms/ceevo-realm/protocol/openid-connect/token"; 
        $param['grant_type'] = "client_credentials"; 
        $param['client_id'] = $this->config->get('Ceevo.clientId'); 
        $param['client_secret'] = $this->config->get('Ceevo.clientSecret'); 
        $flag = $this->config->get('Ceevo.secureFlag');
        
        $mode = $this->config->get('Ceevo.paymentMode');
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL,$api); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1); 
        //curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($param));
        $res = curl_exec($ch); 
        
        // $currencies = new ISOCurrencies();
        // $moneyParser = new DecimalMoneyParser($currencies);
        // $money = $moneyParser->parse((string)$order->info['total'], $order->info['currency']);
        // $converted_money = $money->getAmount(); // outputs 100000
        $orderItems = $order->orderItems;
        $items_array = [];
        foreach($orderItems as $items){
          
          $item_json = array("item" => $items->orderItemName,"itemValue" => $items->amounts[0]->priceGross);
          array_push($items_array, json_encode($item_json));
        }
        $itemString = implode(',',$items_array);
    
        // echo $res;
        $jres = json_decode($res, true);
        $access_token = $jres['access_token'];
        
        $authorization = "Authorization: Bearer $access_token";
        
        $charge_api = "https://api.ceevo.com/acquiring/charge"; 
        
        $cparam = '{
            "cartItems": ['.$itemString.'],
            "amount": '.$order->amounts[0]->grossTotal.',
            "3dsecure": "'.$flag.'",
            "mode" : "'.$mode.'",
            "methodCode":  "",
            "currency": "'.$order->amounts[0]->currency.'",
            "accountToken": "",
            "sessionId":"",
            "userEmail": "'.$order->billingAddress->options[0]->value.'",
            "shippingAddress": {
                "city": "'.$billingAddress->town.'",
                "country": "'.$this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2').'",
                "state": "'.$billingAddress->town.'",
                "street": "'.$billingAddress->address1.'",
                "zip": "'.$billingAddress->postalCode.'"
            }
        }';
        
        $this->getLogger(__METHOD__)->error('inside charge api', $cparam);
        //print_r($cparam);
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL,$charge_api); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $cparam);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json; charset=utf-8',
                'Content-Length: ' . strlen($cparam),
                $authorization
            )
        );
        $cres = curl_exec($ch); 
        $charge_response = json_decode($cres, true);
        $this->getLogger(__METHOD__)->error('inside charge api respoinse', $charge_response);
        return $charge_response;
    }

    function callAPI($method, $url, $data){
        $curl = curl_init();
     
        switch ($method){
           case "POST":
              curl_setopt($curl, CURLOPT_POST, 1);
              if ($data)
                 curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
              break;
           case "PUT":
              curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
              if ($data)
                 curl_setopt($curl, CURLOPT_POSTFIELDS, $data);			 					
              break;
           default:
              if ($data)
                 $url = sprintf("%s?%s", $url, http_build_query($data));
        }
     
        // OPTIONS:
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
           
           'Content-Type: application/json',
        ));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
     
        // EXECUTE:
        $result = curl_exec($curl);
        if(!$result){die("Connection Failure");}
        curl_close($curl);
        return $result;
    }
        
    
    
}