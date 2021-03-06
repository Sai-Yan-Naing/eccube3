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


namespace Eccube\Form\Type\Admin;

use Eccube\Form\DataTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShipmentItemType extends AbstractType
{
    protected $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $config = $this->app['config'];

        $builder
            ->add('id', 'hidden', array(
                'required' => false,
                'mapped' => false
            ))
            ->add('new', 'hidden', array(
                'required' => false,
                'mapped' => false,
                'data' => 1
            ))
            ->add('price', 'money', array(
                'currency' => 'JPY',
                'precision' => 0,
                'scale' => 0,
                'grouping' => true,
                'constraints' => array(
                    new Assert\NotBlank(),
                    new Assert\Length(array(
                        'max' => $config['int_len'],
                    )),
                ),
            ))
            ->add('quantity', 'text', array(
                'constraints' => array(
                    new Assert\NotBlank(),
                    new Assert\Length(array(
                        'max' => $config['int_len'],
                    )),
                ),
            ))
            ->add('product_name', 'hidden')
            ->add('product_code', 'hidden')
            ->add('class_name1', 'hidden')
            ->add('class_name2', 'hidden')
            ->add('class_category_name1', 'hidden')
            ->add('class_category_name2', 'hidden')
        ;

        $builder
            ->add($builder->create('Product', 'hidden')
                ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                    $this->app['orm.em'],
                    '\Eccube\Entity\Product'
                )))
            ->add($builder->create('ProductClass', 'hidden')
                ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                    $this->app['orm.em'],
                    '\Eccube\Entity\ProductClass'
                )));

        $app = $this->app;
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($app) {
            // ?????????????????????POST????????????????????????????????????.
            if ('modal' === $app['request']->get('modal')) {
                $data = $event->getData();
                // ????????????????????????????????????.
                if (isset($data['new'])) {
                    /** @var \Eccube\Entity\ProductClass $ProductClass */
                    $ProductClass = $app['eccube.repository.product_class']
                        ->find($data['ProductClass']);
                    /** @var \Eccube\Entity\Product $Product */
                    $Product = $ProductClass->getProduct();

                    $data['product_name'] = $Product->getName();
                    $data['product_code'] = $ProductClass->getCode();
                    $data['class_name1'] = $ProductClass->hasClassCategory1() ?
                        $ProductClass->getClassCategory1()->getClassName() :
                        null;
                    $data['class_name2'] = $ProductClass->hasClassCategory2() ?
                        $ProductClass->getClassCategory2()->getClassName() :
                        null;
                    $data['class_category_name1'] = $ProductClass->hasClassCategory1() ?
                        $ProductClass->getClassCategory1()->getName() :
                        null;
                    $data['class_category_name2'] = $ProductClass->hasClassCategory2() ?
                        $ProductClass->getClassCategory2()->getName() :
                        null;
                    $data['price'] = $ProductClass->getPrice02();
                    $data['quantity'] = empty($data['quantity']) ? 1 : $data['quantity'];
                    $event->setData($data);
                }
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Eccube\Entity\ShipmentItem',
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'shipment_item';
    }
}
