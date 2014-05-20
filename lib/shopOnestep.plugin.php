<?php

class shopOnestepPlugin extends shopPlugin {

    protected static $steps = array();

    public static function display() {

        self::checkout();

        $view = wa()->getView();
        //$view->assign('frontend_name', $this->getSettings('frontend_name'));
        $template_path = wa()->getAppPath('plugins/onestep/templates/onestep.html', 'shop');
        $html = $view->fetch($template_path);
        return $html;
    }

    public static function checkout() {
        $view = wa()->getView();
        $steps = wa()->getConfig()->getCheckoutSettings();
        /*
          $current_step = waRequest::param('step', waRequest::request('step'));

          if (!$current_step) {
          $current_step = key($steps);
          } */


        foreach ($steps as $current_step => $step) {
            //$title = _w('Checkout');
            if ($current_step == 'success') {

                $order_id = waRequest::get('order_id');
                if (!$order_id) {
                    $order_id = wa()->getStorage()->get('shop/order_id');
                    $payment_success = false;
                } else {
                    $payment_success = true;
                    $view->assign('payment_success', true);
                }
                if (!$order_id) {
                    wa()->getResponse()->redirect(wa()->getRouteUrl('shop/frontend'));
                }
                $order_model = new shopOrderModel();
                $order = $order_model->getById($order_id);
                if (!$payment_success) {
                    $order_params_model = new shopOrderParamsModel();
                    $order['params'] = $order_params_model->get($order_id);
                    $order_items_model = new shopOrderItemsModel();
                    $order['items'] = $order_items_model->getByField('order_id', $order_id, true);
                    $payment = '';
                    if (!empty($order['params']['payment_id'])) {
                        try {
                            /**
                             * @var waPayment $plugin
                             */
                            $plugin = shopPayment::getPlugin(null, $order['params']['payment_id']);
                            $payment = $plugin->payment(waRequest::post(), shopPayment::getOrderData($order, $plugin), true);
                        } catch (waException $ex) {
                            $payment = $ex->getMessage();
                        }
                    }
                    $order['id'] = shopHelper::encodeOrderId($order_id);
                    $this->getResponse()->addGoogleAnalytics($this->getGoogleAnalytics($order));
                } else {
                    $order['id'] = shopHelper::encodeOrderId($order_id);
                }
                $view->assign('order', $order);
                if (isset($payment)) {
                    $view->assign('payment', $payment);
                }
            } else {

                $cart = new shopCart();
                if (!$cart->count() && $current_step != 'error') {
                    $current_step = 'error';
                    $view->assign('error', _w('Your shopping cart is empty. Please add some products to cart, and then proceed to checkout.'));
                }

                if ($current_step != 'error') {
                    if (waRequest::method() == 'post') {
                        if (waRequest::post('wa_auth_login')) {
                            $login_action = new shopLoginAction();
                            $login_action->run();
                        } else {
                            foreach ($steps as $step_id => $step) {
                                if ($step_id == $current_step) {
                                    $step_instance = self::getStep($step_id);
                                    $step_instance->execute();
                                    
                                }
                            }


                            // last step
                            /*
                            if (waRequest::post('confirmation')) {
                                if (self::createOrder()) {
                                    $response = array(
                                        'redirect' => wa()->getRouteUrl('/frontend/checkout', array('step' => 'success'))
                                    );
                                    exit(json_encode($response));
                                    //wa()->getResponse()->redirect(wa()->getRouteUrl('/frontend/checkout', array('step' => 'success')));
                                }
                            }*/
                        }
                    } else {
                        $view->assign('error', '');
                    }
                    //$title .= ' - ' . $steps[$current_step]['name'];

                    $steps[$current_step]['content'] = self::getStep($current_step)->display();
                    $view->assign('checkout_steps', $steps);
                }
            }
        }


        //$this->getResponse()->setTitle($title);
        //$view->assign('checkout_current_step', $current_step);

        /**
         * @event frontend_checkout
         * @return array[string]string $return[%plugin_id%] html output
         */
        $event_params = array('step' => $current_step);
        $view->assign('frontend_checkout', wa()->event('frontend_checkout', $event_params));
        /*
          if (waRequest::isXMLHttpRequest()) {
          $this->setThemeTemplate('checkout.' . $current_step . '.html');
          } else {
          $this->setLayout(new shopFrontendLayout());
          $this->setThemeTemplate('checkout.html');
          }
         * 
         */
    }

    protected function createOrder() {
        $checkout_data = wa()->getStorage()->get('shop/checkout');

        $contact = wa()->getUser()->isAuth() ? wa()->getUser() : $checkout_data['contact'];
        $cart = new shopCart();
        $items = $cart->items(false);
        // remove id from item
        foreach ($items as &$item) {
            unset($item['id']);
            unset($item['parent_id']);
        }
        unset($item);

        $order = array(
            'contact' => $contact,
            'items' => $items,
            'total' => $cart->total(false),
            'params' => isset($checkout_data['params']) ? $checkout_data['params'] : array(),
        );

        $order['discount'] = shopDiscounts::apply($order);

        if (isset($checkout_data['shipping'])) {
            $order['params']['shipping_id'] = $checkout_data['shipping']['id'];
            $order['params']['shipping_rate_id'] = $checkout_data['shipping']['rate_id'];
            $shipping_step = new shopCheckoutShipping();
            $rate = $shipping_step->getRate($order['params']['shipping_id'], $order['params']['shipping_rate_id']);
            $order['params']['shipping_plugin'] = $rate['plugin'];
            $order['params']['shipping_name'] = $rate['name'];
            if (isset($rate['est_delivery'])) {
                $order['params']['shipping_est_delivery'] = $rate['est_delivery'];
            }
            if (!isset($order['shipping'])) {
                $order['shipping'] = $rate['rate'];
            }
            if (!empty($order['params']['shipping'])) {
                foreach ($order['params']['shipping'] as $k => $v) {
                    $order['params']['shipping_params_' . $k] = $v;
                }
                unset($order['params']['shipping']);
            }
        } else {
            $order['shipping'] = 0;
        }

        if (isset($checkout_data['payment'])) {
            $order['params']['payment_id'] = $checkout_data['payment'];
            $plugin_model = new shopPluginModel();
            $plugin_info = $plugin_model->getById($checkout_data['payment']);
            $order['params']['payment_name'] = $plugin_info['name'];
            $order['params']['payment_plugin'] = $plugin_info['plugin'];
            if (!empty($order['params']['payment'])) {
                foreach ($order['params']['payment'] as $k => $v) {
                    $order['params']['payment_params_' . $k] = $v;
                }
                unset($order['params']['payment']);
            }
        }

        if ($skock_id = waRequest::post('stock_id')) {
            $order['params']['stock_id'] = $skock_id;
        }

        $routing_url = wa()->getRouting()->getRootUrl();
        $order['params']['storefront'] = wa()->getConfig()->getDomain() . ($routing_url ? '/' . $routing_url : '');

        if ($ref = wa()->getStorage()->get('shop/referer')) {
            $order['params']['referer'] = $ref;
            $ref_parts = parse_url($ref);
            $order['params']['referer_host'] = $ref_parts['host'];
            // try get search keywords
            if (!empty($ref_parts['query'])) {
                $search_engines = array(
                    'text' => 'yandex\.|rambler\.',
                    'q' => 'bing\.com|mail\.|google\.',
                    's' => 'nigma\.ru',
                    'p' => 'yahoo\.com'
                );
                $q_var = false;
                foreach ($search_engines as $q => $pattern) {
                    if (preg_match('/(' . $pattern . ')/si', $ref_parts['host'])) {
                        $q_var = $q;
                        break;
                    }
                }
                // default query var name
                if (!$q_var) {
                    $q_var = 'q';
                }
                parse_str($ref_parts['query'], $query);
                if (!empty($query[$q_var])) {
                    $order['params']['keyword'] = $query[$q_var];
                }
            }
        }

        $order['params']['ip'] = waRequest::getIp();
        $order['params']['user_agent'] = waRequest::getUserAgent();

        foreach (array('shipping', 'billing') as $ext) {
            $address = $contact->getFirst('address.' . $ext);
            if ($address) {
                foreach ($address['data'] as $k => $v) {
                    $order['params'][$ext . '_address.' . $k] = $v;
                }
            }
        }

        if (isset($checkout_data['comment'])) {
            $order['comment'] = $checkout_data['comment'];
        }

        $workflow = new shopWorkflow();
        if ($order_id = $workflow->getActionById('create')->run($order)) {

            $step_number = shopCheckout::getStepNumber();
            $checkout_flow = new shopCheckoutFlowModel();
            $checkout_flow->add(array(
                'step' => $step_number
            ));

            $cart->clear();
            wa()->getStorage()->remove('shop/checkout');
            wa()->getStorage()->set('shop/order_id', $order_id);

            return true;
        }
    }

    protected static function getStep($step_id) {
        if (!isset(self::$steps[$step_id])) {
            $class_name = 'shopCheckout' . ucfirst($step_id);
            self::$steps[$step_id] = new $class_name();
        }
        return self::$steps[$step_id];
    }

}