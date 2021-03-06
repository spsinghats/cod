<?php

namespace Ceevo\Providers;

use Ceevo\Methods\CeevoPaymentMethod;
use Ceevo\Helper\CeevoHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Order\Shipping\Contracts\ParcelServicePresetRepositoryContract;
use Plenty\Modules\Order\Shipping\ParcelService\Models\ParcelServicePreset;
use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Order\Shipping\Events\AfterShippingCostCalculated;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Plugin\Translation\Translator;
use Ceevo\Services\PaymentService;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;

class CeevoServiceProvider extends ServiceProvider
{
    /**
     * @param CeevoHelper $paymentHelper
     * @param PaymentMethodContainer $payContainer
     * @param Dispatcher $eventDispatcher
     */

    use Loggable;

    public function boot(
        CeevoHelper $paymentHelper,
        PaymentMethodContainer $payContainer,
        Dispatcher $eventDispatcher,
        PaymentService $paymentService,
        OrderRepositoryContract $orderRepository,
        PaymentMethodRepositoryContract $paymentMethodService
        ) 
    {
         // Register the Invoice payment method in the payment method container
        $payContainer->register('plenty::COD', CeevoPaymentMethod::class,
            [ AfterBasketChanged::class, AfterBasketItemAdd::class, AfterBasketCreate::class, AfterShippingCostCalculated::class ]
        );
        
    
        // Listen for the event that gets the payment method content
        $eventDispatcher->listen(GetPaymentMethodContent::class,
            function(GetPaymentMethodContent $event) use( $paymentHelper) {
                if($event->getMop() == $paymentHelper->getMop()) {
                    $this->getLogger(__METHOD__)->error('inside payment method content event.', $paymentHelper);
                    $event->setType('errorCode');
                    $translator = pluginApp(Translator::class);
                    $event->setValue( $translator->trans('Ceevo::error.errorInvalidParcelService'));

                    /** @var Checkout $checkoutService */
                    $checkoutService = pluginApp(Checkout::class);
                    if($shippingProfileId = $checkoutService->getShippingProfileId()) {
                        $parcelServicePresetRepoContract = pluginApp(ParcelServicePresetRepositoryContract::class);

                        $parcelPreset = $parcelServicePresetRepoContract ->getPresetById($shippingProfileId);
                        if ($parcelPreset instanceof ParcelServicePreset) {
                            if ((bool)$parcelPreset->isCod) {
                              
                                $event->setValue('<h1>ceevo payment plugin<h1><input type="text" name="token_hidden_input">');
                                $event->setType('htmlContent');
                                $this->getLogger(__METHOD__)->error('payment method content event.', $event);
                            }
                        }
                    }
                }
            });

            // Listen for the event that executes the payment
            $eventDispatcher->listen(ExecutePayment::class,
                function(ExecutePayment $event) use( $paymentHelper, $paymentService, $paymentMethodService, $orderRepository)
                {
                    $this->getLogger(__METHOD__)->error('The ceevo processing.', $paymentHelper);
                    $result = $paymentService->executePayment($orderRepository->findOrderById($event->getOrderId()), $paymentMethodService->findByPaymentMethodId($event->getMop()));
                    $this->getLogger(__METHOD__)->error('The ceevo processing.', $result);
                    if($event->getMop() == $paymentHelper->getMop())
                    {
                        $event->setValue(isset($result['content']) ? $result['content'] : null);
                        $event->setType(isset($result['type']) ? $result['type'] : '');
                    }
                });
    }
}
