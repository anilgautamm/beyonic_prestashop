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
class Ps_BeyonicWebhookModuleFrontController extends ModuleFrontController {

    /**
     * @see FrontController::initContent()
     */
    public function initContent() {

        $responce = json_decode(file_get_contents("php://input"));
        mail('arun.techmarbles@gmail.com', 'beyonic', 'beyonic ipn wprking');
        if (!empty($responce)) {

            $data = $responce->data;
            $hook = $responce->hook;
            $event = $hook->event;

            if ($event == 'collection.received') {
                require '../../lib/Beyonic.php';
                $this->authorize_beyonic_gw();
                $collection_request = Beyonic_Collection_Request::get($data->collection_request);
                $order_id = intval($collection_request->metadata->order_id);
                $status = $data->status;
                $transaction_id = $data->collection_request;
                if ($status == "successful") {
                    $order_status = 2;
                } else {
                    $order_status = 8;
                }

                $objOrder = new Order($order_id);
                $history = new OrderHistory();
                $history->id_order = (int) $objOrder->id;
                $history->changeIdOrderState($order_status, (int) ($objOrder->id));
            }
        }
    }

    private function authorize_beyonic_gw() {
        $apikey = Configuration::get('BEYONIC_API_KEY');
        $version = 'v1';
        Beyonic::setApiVersion($version);
        Beyonic::setApiKey($apikey);
    }

}
