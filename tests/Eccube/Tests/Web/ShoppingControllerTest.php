<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */


namespace Eccube\Tests\Web;

use Symfony\Component\DomCrawler\Crawler;

class ShoppingControllerTest extends AbstractShoppingControllerTestCase
{

    public function testRoutingShoppingLogin()
    {
        $client = $this->client;
        $crawler = $client->request('GET', '/shopping/login');
        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('cart')));
    }

    public function testShoppingIndexWithCartUnlock()
    {
        $this->app['eccube.service.cart']->unlock();

        $client = $this->createClient();
        $crawler = $client->request('GET', $this->app->path('shopping'));

        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('cart')));
    }

    public function testComplete()
    {
        $this->app['session']->set('eccube.front.shopping.order.id', 111);

        $client = $this->createClient();
        $crawler = $client->request('GET', $this->app->path('shopping_complete'));

        $this->assertTrue($client->getResponse()->isSuccessful());
        $this->assertNull($this->app['session']->get('eccube.front.shopping.order.id'));
    }

    public function testShoppingError()
    {
        $client = $this->createClient();
        $crawler = $client->request('GET', $this->app->path('shopping_error'));
        $this->assertTrue($client->getResponse()->isSuccessful());
    }

    /**
     * ?????????????????????????????????????????????
     */
    public function testCompleteWithLogin()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;
        // ???????????????
        $this->scenarioCartIn($client);

        // ????????????
        $crawler = $this->scenarioConfirm($client);
        $this->expected = '???????????????????????????';
        $this->actual = $crawler->filter('h1.page-heading')->text();
        $this->verify();

        // ????????????
        $crawler = $this->scenarioComplete($client, $this->app->path('shopping_confirm'));
        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('shopping_complete')));

        $BaseInfo = $this->app['eccube.repository.base_info']->get();
        $Messages = $this->getMailCatcherMessages();
        $Message = $this->getMailCatcherMessage($Messages[0]->id);

        $this->expected = '[' . $BaseInfo->getShopName() . '] ???????????????????????????????????????';
        $this->actual = $Message->subject;
        $this->verify();

        // ????????????????????????????????????
        $Order = $this->app['eccube.repository.order']->findOneBy(
            array(
                'Customer' => $Customer
            )
        );

        $OrderNew = $this->app['eccube.repository.order_status']->find($this->app['config']['order_new']);
        $this->expected = $OrderNew;
        $this->actual = $Order->getOrderStatus();
        $this->verify();

        $this->expected = $Customer->getName01();
        $this->actual = $Order->getName01();
        $this->verify();
    }

    /**
     * ???????????????????????????????????????(?????????)
     */
    public function testDeliveryWithNotInput()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;
        // ???????????????
        $this->scenarioCartIn($client);

        // ????????????
        $crawler = $this->scenarioConfirm($client);

        // ????????????????????????
        $crawler = $client->request(
            'POST',
            $this->app->path('shopping_delivery')
        );

        $this->assertTrue($client->getResponse()->isSuccessful());
    }

    /**
     * ???????????????????????????????????????
     */
    public function testDeliveryWithPost()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;

        // ???????????????
        $this->scenarioCartIn($client);

        // ????????????
        $crawler = $this->scenarioConfirm($client);

        // ????????????????????????
        $crawler = $client->request(
            'POST',
            $this->app->path('shopping_delivery'),
            array(
                'shopping' => array(
                    'shippings' => array(
                        0 => array(
                            'delivery' => 1,
                            'deliveryTime' => 1
                        ),
                    ),
                    'payment' => 1,
                    'message' => $faker->text(),
                    '_token' => 'dummy'
                )
            )
        );

        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('shopping')));
    }

    /**
     * ???????????????????????????????????????(???????????????)
     */
    public function testDeliveryWithError()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;
        // ???????????????
        $this->scenarioCartIn($client);

        // ????????????
        $crawler = $this->scenarioConfirm($client);

        // ??????????????????
        $crawler = $client->request(
            'POST',
            $this->app->path('shopping_delivery'),
            array(
                'shopping' => array(
                    'shippings' => array(
                        0 => array(
                            'delivery' => 5, // delivery=5 ???????????????
                            'deliveryTime' => 1
                        ),
                    ),
                    'payment' => 1,
                    'message' => $faker->text(),
                    '_token' => 'dummy'
                )
            )
        );

        $this->assertTrue($client->getResponse()->isSuccessful());
        $this->expected = '????????????????????????????????????';
        $this->actual = $crawler->filter('p.errormsg')->text();
        $this->verify();
    }

    /**
     * ??????????????????????????????????????????
     */
    public function testPaymentWithPost()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;

        // ???????????????
        $this->scenarioCartIn($client);

        // ????????????
        $crawler = $this->scenarioConfirm($client);

        // ?????????????????????
        $crawler = $client->request(
            'POST',
            $this->app->path('shopping_payment'),
            array(
                'shopping' => array(
                    'shippings' => array(
                        0 => array(
                            'delivery' => 1,
                            'deliveryTime' => 1
                        ),
                    ),
                    'payment' => 1,
                    'message' => $faker->text(),
                    '_token' => 'dummy'
                )
            )
        );

        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('shopping')));
    }

    /**
     * ???????????????????????????????????????????????????????????????????????????????????????????????????
     */
    public function testOrtderConfirmLayout()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;

        // ???????????????
        $this->scenarioCartIn($client);

        // ????????????
        $crawler = $this->scenarioConfirm($client);

        // ?????????????????????
        $crawler = $client->request(
            'POST',
            $this->app->path('shopping_payment'),
            array(
                'shopping' => array(
                    'shippings' => array(
                        0 => array(
                            'delivery' => 1,
                            'deliveryTime' => 1
                        ),
                    ),
                    'payment' => 0,
                    'message' => $faker->text(),
                    '_token' => 'dummy'
                )
            )
        );
        // ??????????????????????????????????????????
        $this->expected = 'header';
        $this->actual = $crawler->filter('header')->attr('id');
        $this->verify();

        // ??????????????????????????????????????????
        $this->expected = 'footer';
        $this->actual = $crawler->filter('footer')->attr('id');
        $this->verify();

        // ??????????????????????????????????????????????????????
        $this->expected = '????????????????????????????????????';
        $this->actual = $crawler->filter('P.errormsg')->text();
        $this->verify();
    }

    /**
     * ??????????????????????????????????????????(?????????)
     */
    public function testPaymentWithError()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;

        // ???????????????
        $this->scenarioCartIn($client);
        // ????????????
        $crawler = $this->scenarioConfirm($client);
        // ?????????????????????
        $crawler = $client->request(
            'POST',
            $this->app->path('shopping_payment'),
            array(
                'shopping' => array(
                    'shippings' => array(
                        0 => array(
                            'delivery' => 1,
                            'deliveryTime' => 1
                        ),
                    ),
                    'payment' => 100, // payment=100 ???????????????
                    'message' => $faker->text(),
                    '_token' => 'dummy'
                )
            )
        );

        $this->assertTrue($client->getResponse()->isSuccessful());
        $this->expected = '????????????????????????????????????';
        $this->actual = $crawler->filter('p.errormsg')->text();
        $this->verify();
    }

    /**
     * ??????????????????
     */
    public function testPaymentEmpty()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;

        // ???????????????
        $this->scenarioCartIn($client);

        // ??????????????????MIN???MAX???????????????
        $PaymentColl = $this->app['eccube.repository.payment']->findAll();
        foreach($PaymentColl as $Payment){
                $Payment->setRuleMin(0);
                $Payment->setRuleMax(0);
        }
        // ????????????
        $crawler = $this->scenarioConfirm($client);

        $BaseInfo = $this->app['eccube.repository.base_info']->get();
        $email02 = $BaseInfo->getEmail02();
        $this->assertTrue($client->getResponse()->isSuccessful());
        $this->expected = '?????????????????????????????????????????????????????????????????????' . $email02 . '?????????????????????????????????';
        $this->actual = $crawler->filter('p.errormsg')->text();
        $this->verify();
    }

    /**
     * ??????????????????????????????????????????
     */
    public function testShippingChangeWithPost()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;
        // ???????????????
        $this->scenarioCartIn($client);
        // ????????????
        $crawler = $this->scenarioConfirm($client);

        // ????????????????????????
        $shipping_url = $crawler->filter('a.btn-shipping')->attr('href');
        $crawler = $this->scenarioComplete($client, $shipping_url);

        // /shipping/shipping_change/<id> ?????? /shipping/shipping/<id> ?????????????????????
        $shipping_url = str_replace('shipping_change', 'shipping', $shipping_url);
        $this->assertTrue($client->getResponse()->isRedirect($shipping_url));
    }

    /**
     * ???????????????????????????????????????????????????????????????
     */
    public function testShippingShipping()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;
        // ???????????????
        $this->scenarioCartIn($client);
        // ????????????
        $crawler = $this->scenarioConfirm($client);
        // ????????????????????????
        $shipping_url = $crawler->filter('a.btn-shipping')->attr('href');
        $crawler = $this->scenarioComplete($client, $shipping_url);

        $shipping_url = str_replace('shipping_change', 'shipping', $shipping_url);

        // ??????????????????
        $crawler = $client->request(
            'GET',
            $shipping_url
        );

        $this->assertTrue($client->getResponse()->isSuccessful());

        $this->expected = '?????????????????????';
        $this->actual = $crawler->filter('h1.page-heading')->text();
        $this->verify();
    }

    /**
     * ??????????????????????????????????????????????????????????????????????????????
     *
     * @link https://github.com/EC-CUBE/ec-cube/issues/1305
     */
    public function testShippingShippingPost()
    {
        $faker = $this->getFaker();
        $Customer = $this->logIn();
        $client = $this->client;

        // ???????????????
        $this->scenarioCartIn($client);
        // ????????????
        $crawler = $this->scenarioConfirm($client);
        // ?????????????????????
        $shipping_url = $crawler->filter('a.btn-shipping')->attr('href');
        $crawler = $this->scenarioComplete($client, $shipping_url);

        // ??????????????????
        $shipping_url = str_replace('shipping_change', 'shipping', $shipping_url);

        $crawler = $client->request(
            'GET',
            $shipping_url
        );

        $this->assertTrue($client->getResponse()->isSuccessful());

        $this->expected = '?????????????????????';
        $this->actual = $crawler->filter('h1.page-heading')->text();
        $this->verify();

        $shipping_edit_url = $crawler->filter('a.btn-default')->attr('href');

        // ????????????????????????
        $crawler = $client->request(
            'GET',
            $shipping_edit_url
        );
        $this->assertTrue($client->getResponse()->isSuccessful());

        // ???????????????????????????????????? POST ??????
        $formData = $this->createShippingFormData();
        $formData['tel'] = array(
            'tel01' => 222,
            'tel02' => 222,
            'tel03' => 222,
        );
        $formData['fax'] = array(
            'fax01' => 111,
            'fax02' => 111,
            'fax03' => 111,
        );

        $crawler = $client->request(
            'POST',
            $shipping_edit_url,
            array('shopping_shipping' => $formData)
        );

        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('shopping')));

        // ???????????????
        $this->scenarioComplete($client, $this->app->path('shopping_confirm'));

        $BaseInfo = $this->app['eccube.repository.base_info']->get();
        $Messages = $this->getMailCatcherMessages();
        $Message = $this->getMailCatcherMessage($Messages[0]->id);

        // https://github.com/EC-CUBE/ec-cube/issues/1305
        $this->assertRegexp('/111-111-111/', $this->parseMailCatcherSource($Message), '???????????? FAX ????????????????????????');
        $this->assertRegexp('/222-222-222/', $this->parseMailCatcherSource($Message), '???????????? ??????????????????????????????');
    }

    /**
     * @link https://github.com/EC-CUBE/ec-cube/issues/1280
     */
    public function testShippingEditTitle()
    {
        $this->logIn();
        $client = $this->client;
        $this->scenarioCartIn($client);

        /** @var $crawler Crawler*/
        $crawler = $this->scenarioConfirm($client);
        $this->expected = '???????????????????????????';
        $this->actual = $crawler->filter('h1.page-heading')->text();
        $this->verify();

        $shippingCrawler = $crawler->filter('#shipping_confirm_box--0');
        $url = $shippingCrawler->selectLink('??????')->link()->getUri();
        $url = str_replace('shipping_change', 'shipping_edit', $url);

        // Get shipping edit
        $crawler = $client->request('GET', $url);

        // Title
        $this->assertContains('?????????????????????', $crawler->html());
    }
}
