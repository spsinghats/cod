<?php
/**
 * Created by IntelliJ IDEA.
 * User: ckunze
 * Date: 8/3/17
 * Time: 18:27
 */

namespace Ceevo\Providers;

use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;


class ButtonProvider
{
      use Loggable;
      public function call(Twig $twig,$arg):string
      {
            
        $get_data = self::callAPI('GET', 'https://api.ceevo.com/acquiring/methods', []);
        $response = json_decode($get_data, true);
        $methods_array = [];
        foreach($response as $methods){
            array_push($methods_array,$methods['title']);

        }
        $order = $arg[0];
        $payments = pluginApp(PaymentRepositoryContract::class)->getPaymentsByOrderId($order['id']);

        $templateData = array(
            'methods' => $methods_array,
            'apiKey' => pluginApp(ConfigRepository::class)->get('Ceevo.apiKey'),
            'id' => array_keys($arg)[0],
            'price' => empty($arg)
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