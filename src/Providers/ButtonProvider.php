<?php
/**
 * Created by IntelliJ IDEA.
 * User: ckunze
 * Date: 8/3/17
 * Time: 18:27
 */

namespace Ceevo\Providers;

use Plenty\Plugin\Templates\Twig;
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


class ButtonProvider
{
    use Loggable;
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

    public function call(Twig $twig):string
    {
        $get_data = callAPI('GET', 'https://api.ceevo.com/acquiring/methods', []);
        $response = json_decode($get_data, true);
        $methods_array = [];
        foreach($response as $methods){
            array_push($methods_array,$methods['title']);

        }
        $this->getLogger(__METHOD__)->error('inside button provider', $this->config);
        $apiKey = $this->config->get('Ceevo.apiKey');
        $templateData = array(
            'methods' => $methods_array,
            'apiKey' => $apiKey
        );
        return $twig->render('Ceevo::Icon',$templateData);
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