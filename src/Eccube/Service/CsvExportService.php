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


namespace Eccube\Service;

use Eccube\Common\Constant;
use Eccube\Util\EntityUtil;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Common\Collections\ArrayCollection;

class CsvExportService
{
    /**
     * @var
     */
    protected $fp;

    /**
     * @var
     */
    protected $closed = false;

    /**
     * @var \Closure
     */
    protected $convertEncodingCallBack;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $em;

    /**
     * @var \Doctrine\ORM\QueryBuilder;
     */
    protected $qb;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var \Eccube\Entity\Master\CsvType
     */
    protected $CsvType;

    /**
     * @var \Eccube\Entity\Csv[]
     */
    protected $Csvs;

    /**
     * @var \Eccube\Repository\CsvRepository
     */
    protected $csvRepository;

    /**
     * @var \Eccube\Repository\Master\CsvTypeRepository
     */
    protected $csvTypeRepository;

    /**
     * @var \Eccube\Repository\OrderRepository
     */
    protected $orderRepository;

    /**
     * @var \Eccube\Repository\CustomerRepository
     */
    protected $customerRepository;

    /**
     * @var \Eccube\Repository\ProductRepository
     */
    protected $productRepository;

    /**
     * @param $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @param \Eccube\Repository\CsvRepository $csvRepository
     */
    public function setCsvRepository(\Eccube\Repository\CsvRepository $csvRepository)
    {
        $this->csvRepository = $csvRepository;
    }

    /**
     * @param \Eccube\Repository\Master\CsvTypeRepository $csvTypeRepository
     */
    public function setCsvTypeRepository(\Eccube\Repository\Master\CsvTypeRepository $csvTypeRepository)
    {
        $this->csvTypeRepository = $csvTypeRepository;
    }

    /**
     * @param \Eccube\Repository\OrderRepository $orderRepository
     */
    public function setOrderRepository(\Eccube\Repository\OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param \Eccube\Repository\CustomerRepository $customerRepository
     */
    public function setCustomerRepository(\Eccube\Repository\CustomerRepository $customerRepository)
    {
        $this->customerRepository = $customerRepository;
    }

    /**
     * @param \Eccube\Repository\ProductRepository $productRepository
     */
    public function setProductRepository(\Eccube\Repository\ProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    /**
     * @param \Doctrine\ORM\EntityManager $em
     */
    public function setEntityManager(\Doctrine\ORM\EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * @return \Doctrine\ORM\EntityManager
     */
    public function getEntityManager()
    {
        return $this->em;
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $qb
     */
    public function setExportQueryBuilder(\Doctrine\ORM\QueryBuilder $qb)
    {
        $this->qb = $qb;
    }

    /**
     * Csv????????????Service?????????????????????.
     *
     * @param $CsvType|integer
     */
    public function initCsvType($CsvType)
    {
        if ($CsvType instanceof \Eccube\Entity\Master\CsvType) {
            $this->CsvType = $CsvType;
        } else {
            $this->CsvType = $this->csvTypeRepository->find($CsvType);
        }

        $criteria = array(
            'CsvType' => $CsvType,
            'enable_flg' => Constant::ENABLED
        );
        $orderBy = array(
            'rank' => 'ASC'
        );
        $this->Csvs = $this->csvRepository->findBy($criteria, $orderBy);
    }

    /**
     * @return \Eccube\Entity\Csv[]
     */
    public function getCsvs()
    {
        return $this->Csvs;
    }

    /**
     * ???????????????????????????.
     * ????????????????????????????????????, ?????????initCsvType($CsvType)???????????????????????????????????????.
     */
    public function exportHeader()
    {
        if (is_null($this->CsvType) || is_null($this->Csvs)) {
            throw new \LogicException('init csv type incomplete.');
        }

        $row = array();
        foreach ($this->Csvs as $Csv) {
            $row[] = $Csv->getDispName();
        }

        $this->fopen();
        $this->fputcsv($row);
        $this->fclose();
    }

    /**
     * ???????????????????????????????????????????????????????????????.
     * ????????????????????????????????????, ?????????setExportQueryBuilder($qb)????????????????????????????????????????????????????????????????????????.
     *
     * @param \Closure $closure
     */
    public function exportData(\Closure $closure)
    {
        if (is_null($this->qb) || is_null($this->em)) {
            throw new \LogicException('query builder not set.');
        }

        $this->fopen();

        $query = $this->qb->getQuery();
        foreach ($query->getResult() as $iteratableResult) {
            $closure($iteratableResult, $this);
            $this->em->detach($iteratableResult);
            $query->free();
            flush();
        }

        $this->fclose();
    }

    /**
     * CSV????????????????????????, ??????????????????????????????.
     *
     * @param \Eccube\Entity\Csv $Csv
     * @param $entity
     * @return mixed|null|string|void
     */
    public function getData(\Eccube\Entity\Csv $Csv, $entity)
    {
        // ????????????????????????????????????????????????????????????.
        $csvEntityName = str_replace('\\\\', '\\', $Csv->getEntityName());
        $entityName = str_replace('\\\\', '\\', get_class($entity));
        if ($csvEntityName !== $entityName) {
            $entityName = str_replace('DoctrineProxy\\__CG__\\', '', $entityName);
            if ($csvEntityName !== $entityName) {
                return null;
            }
        }

        // ???????????????????????????????????????????????????????????????????????????.
        if (!$entity->offsetExists($Csv->getFieldName())) {
            return null;
        }

        // ??????????????????.
        $data = $entity->offsetGet($Csv->getFieldName());

        // one to one ????????????, dtb_csv.referece_field_name????????????, ?????????????????????????????????.
        if ($data instanceof \Eccube\Entity\AbstractEntity) {
            if (EntityUtil::isNotEmpty($data)) {
                return $data->offsetGet($Csv->getReferenceFieldName());
            }
        } elseif ($data instanceof \Doctrine\Common\Collections\Collection) {
            // one to many????????????, ?????????????????????????????????.
            $array = array();
            foreach ($data as $elem) {
                if (EntityUtil::isNotEmpty($elem)) {
                    $array[] = $elem->offsetGet($Csv->getReferenceFieldName());
                }
            }
            return implode($this->config['csv_export_multidata_separator'], $array);

        } elseif ($data instanceof \DateTime) {
            // datetime????????????????????????????????????.
            return $data->format($this->config['csv_export_date_format']);

        } else {
            // ????????????????????????????????????.
            return $data;
        }

        return null;
    }

    /**
     * ?????????????????????????????????????????????????????????????????????????????????.
     *
     * @return \Closure
     */
    public function getConvertEncodhingCallback()
    {
        $config = $this->config;

        return function ($value) use ($config) {
            return mb_convert_encoding(
                (string) $value, $config['csv_export_encoding'], 'UTF-8'
            );
        };
    }

    /**
     *
     */
    public function fopen()
    {
        if (is_null($this->fp) || $this->closed) {
            $this->fp = fopen('php://output', 'w');
        }
    }

    /**
     * @param $row
     * @param null $callback
     */
    public function fputcsv($row)
    {
        if (is_null($this->convertEncodingCallBack)) {
            $this->convertEncodingCallBack = $this->getConvertEncodhingCallback();
        }

        fputcsv($this->fp, array_map($this->convertEncodingCallBack, $row), $this->config['csv_export_separator']);
    }

    /**
     *
     */
    public function fclose()
    {
        if (!$this->closed) {
            fclose($this->fp);
            $this->closed = true;
        }
    }

    /**
     * ?????????????????????????????????????????????.
     *
     * @param Request $request
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getOrderQueryBuilder(Request $request)
    {
        $session = $request->getSession();
        $viewData = $session->get('eccube.admin.order.search', array());

        $app = \Eccube\Application::getInstance();
        $searchForm = $app['form.factory']
            ->create('admin_search_order', null, array('csrf_protection' => true));

        $searchData = \Eccube\Util\FormUtil::submitAndGetData($searchForm, $viewData);

        // ?????????????????????????????????????????????.
        $qb = $this->orderRepository
            ->getQueryBuilderBySearchDataForAdmin($searchData);

        return $qb;
    }

    /**
     * ?????????????????????????????????????????????.
     *
     * @param Request $request
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getCustomerQueryBuilder(Request $request)
    {
        $session = $request->getSession();
        $viewData = $session->get('eccube.admin.customer.search', array());

        $app = \Eccube\Application::getInstance();
        $searchForm = $app['form.factory']
            ->create('admin_search_customer', null, array('csrf_protection' => true));

        $searchData = \Eccube\Util\FormUtil::submitAndGetData($searchForm, $viewData);

        // ?????????????????????????????????????????????.
        $qb = $this->customerRepository
            ->getQueryBuilderBySearchData($searchData);

        return $qb;
    }

    /**
     * ?????????????????????????????????????????????.
     *
     * @param Request $request
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getProductQueryBuilder(Request $request)
    {
        $session = $request->getSession();
                $viewData = $session->get('eccube.admin.product.search', array());
        $app = \Eccube\Application::getInstance();
        $searchForm = $app['form.factory']
            ->create('admin_search_product', null, array('csrf_protection' => true));
        $searchData = \Eccube\Util\FormUtil::submitAndGetData($searchForm, $viewData);
        if(isset($viewData['link_status']) && strlen($viewData['link_status'])){
            $searchData['link_status'] = $app['eccube.repository.master.disp']->find($viewData['link_status']);
        }
        if(isset($viewData['stock_status'])){
            $searchData['stock_status'] = $viewData['stock_status'];
        }

        // ?????????????????????????????????????????????.
        $qb = $this->productRepository
            ->getQueryBuilderBySearchDataForAdmin($searchData);

        return $qb;
    }

    /**
     * ????????????????????????????????????????????? Doctrine ???????????????????????????????????????.
     *
     * XXX self::setExportQueryBuilder() ???????????????????????? EntityManager ???????????????????????????????????????????????????
     *
     * @param array $searchData ??????????????????????????????????????????????????????
     * @param EntityManager $em
     */
    protected function findDeserializeObjects(array &$searchData)
    {
        $em = $this->getEntityManager();
        foreach ($searchData as &$Conditions) {
            if ($Conditions instanceof ArrayCollection) {
                $Conditions = new ArrayCollection(
                    array_map(
                        function ($Entity) use ($em) {
                            return $em->getRepository(get_class($Entity))->find($Entity->getId());
                        }, $Conditions->toArray()
                    )
                );
            } elseif ($Conditions instanceof \Eccube\Entity\AbstractEntity) {
                $Conditions = $em->getRepository(get_class($Conditions))->find($Conditions->getId());
            }
        }
    }
}
