<?php
/**
 * Created by IntelliJ IDEA.
 * User: ckunze
 * Date: 8/3/17
 * Time: 18:27
 */

namespace CashOnDelivery\Providers;

use Plenty\Plugin\Templates\Twig;


class ButtonProvider
{
    public function call(Twig $twig):string
    {
        return $twig->render('Ceevo::Icon');
    }

}