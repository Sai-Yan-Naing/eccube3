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


namespace Eccube\Controller\Admin\Product;

use Eccube\Application;
use Eccube\Common\Constant;
use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\CsvType;
use Eccube\Entity\ProductTag;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Service\CsvExportService;
use Eccube\Util\FormUtil;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

class ProductController extends AbstractController
{
    public function index(Application $app, Request $request, $page_no = null)
    {

        $session = $app['session'];

        $builder = $app['form.factory']
            ->createBuilder('admin_search_product');

        $event = new EventArgs(
            array(
                'builder' => $builder,
            ),
            $request
        );
        $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_INDEX_INITIALIZE, $event);

        $searchForm = $builder->getForm();

        $pagination = array();

        $disps = $app['eccube.repository.master.disp']->findAll();
        $pageMaxis = $app['eccube.repository.master.page_max']->findAll();

        // ???????????????????????????????????????1.SESSION 2.??????????????????
        $page_count = $session->get('eccube.admin.product.search.page_count', $app['config']['default_page_count']);
        // ????????????

        $page_count_param = $request->get('page_count');
        // ???????????????URL?????????????????????????????????
        if ($page_count_param && is_numeric($page_count_param)) {
            foreach ($pageMaxis as $pageMax) {
                if ($page_count_param == $pageMax->getName()) {
                    $page_count = $pageMax->getName();
                    // ????????????????????????????????????SESSION???????????????
                    $session->set('eccube.admin.product.search.page_count', $page_count);
                    break;
                }
            }
        }

        $page_status = null;
        $active = false;

        if ('POST' === $request->getMethod()) {

            $searchForm->handleRequest($request);

            if ($searchForm->isValid()) {
                $searchData = $searchForm->getData();

                // paginator
                $qb = $app['eccube.repository.product']->getQueryBuilderBySearchDataForAdmin($searchData);
                $page_no = 1;

                $event = new EventArgs(
                    array(
                        'qb' => $qb,
                        'searchData' => $searchData,
                    ),
                    $request
                );
                $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_INDEX_SEARCH, $event);
                $searchData = $event->getArgument('searchData');

                $pagination = $app['paginator']()->paginate(
                    $qb,
                    $page_no,
                    $page_count,
                    array('wrap-queries' => true)
                );

                // session????????????????????????
                $viewData = FormUtil::getViewData($searchForm);
                $session->set('eccube.admin.product.search', $viewData);
                $session->set('eccube.admin.product.search.page_no', $page_no);
            }
        } else {
            if (is_null($page_no) && $request->get('resume') != Constant::ENABLED) {
                // session?????????
                $session->remove('eccube.admin.product.search');
                $session->remove('eccube.admin.product.search.page_no');
                $session->remove('eccube.admin.product.search.page_count');
            } else {
                // paging???????????????
                if (is_null($page_no)) {
                    $page_no = intval($session->get('eccube.admin.product.search.page_no'));
                } else {
                    $session->set('eccube.admin.product.search.page_no', $page_no);
                }
                $viewData = $session->get('eccube.admin.product.search');
                if (!is_null($viewData)) {
                    // ?????????????????????
                    // 1:??????, 2:?????????, 3:????????????
                    $linkStatus = $request->get('status');
                    if (!empty($linkStatus)) {
                        // ???????????????????????????????????????:3??????
                        if ($linkStatus != $app['config']['admin_product_stock_status']) {
                            $viewData['link_status'] = $linkStatus;
                            $viewData['stock_status'] = null;
                            $viewData['status'] = null;
                        } else {
                            // ???????????????????????????????????????:3
                            $viewData['link_status'] = null;
                            $viewData['stock_status'] = Constant::DISABLED;
                            $viewData['status'] = null;
                        }
                        // ?????????????????????????????????????????????????????????????????????A???????????????????????????
                        $page_status = $linkStatus;
                    } else {
                        // ??????????????????
                        $viewData['link_status'] = null;
                        $viewData['stock_status'] = null;
                        if (!$viewData['status']) {
                            $viewData['status'] = array();
                        }
                    }

                    // ????????????
                    $page_count = $request->get('page_count', $page_count);
                    $searchData = FormUtil::submitAndGetData($searchForm, $viewData);
                    if ($viewData['link_status']) {
                        $searchData['link_status'] = $app['eccube.repository.master.disp']->find($viewData['link_status']);
                    }
                    // ????????????????????????[????????????]???????????????????????????????????????????????????????????????
                    if (isset($viewData['stock_status'])) {
                        $searchData['stock_status'] = $viewData['stock_status'];
                    }

                    $session->set('eccube.admin.product.search', $viewData);

                    $qb = $app['eccube.repository.product']->getQueryBuilderBySearchDataForAdmin($searchData);

                    $event = new EventArgs(
                        array(
                            'qb' => $qb,
                            'searchData' => $searchData,
                        ),
                        $request
                    );
                    $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_INDEX_SEARCH, $event);
                    $searchData = $event->getArgument('searchData');


                    $pagination = $app['paginator']()->paginate(
                        $qb,
                        $page_no,
                        $page_count,
                        array('wrap-queries' => true)
                    );
                }
            }
        }

        return $app->render('Product/index.twig', array(
            'searchForm' => $searchForm->createView(),
            'pagination' => $pagination,
            'disps' => $disps,
            'pageMaxis' => $pageMaxis,
            'page_no' => $page_no,
            'page_status' => $page_status,
            'page_count' => $page_count,
            'active' => $active,
        ));
    }

    public function addImage(Application $app, Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw new BadRequestHttpException('??????????????????????????????');
        }

        $images = $request->files->get('admin_product');

        $files = array();
        if (count($images) > 0) {
            foreach ($images as $img) {
                foreach ($img as $image) {
                    //????????????????????????????????????
                    $mimeType = $image->getMimeType();
                    if (0 !== strpos($mimeType, 'image')) {
                        throw new UnsupportedMediaTypeHttpException('?????????????????????????????????');
                    }

                    $extension = $image->getClientOriginalExtension();
                    $filename = date('mdHis').uniqid('_').'.'.$extension;
                    $image->move($app['config']['image_temp_realdir'], $filename);
                    $files[] = $filename;
                }
            }
        }

        $event = new EventArgs(
            array(
                'images' => $images,
                'files' => $files,
            ),
            $request
        );
        $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_ADD_IMAGE_COMPLETE, $event);
        $files = $event->getArgument('files');

        return $app->json(array('files' => $files), 200);
    }

    public function edit(Application $app, Request $request, $id = null)
    {
        $has_class = false;
        if (is_null($id)) {
            $Product = new \Eccube\Entity\Product();
            $ProductClass = new \Eccube\Entity\ProductClass();
            $Disp = $app['eccube.repository.master.disp']->find(\Eccube\Entity\Master\Disp::DISPLAY_HIDE);
            $Product
                ->setDelFlg(Constant::DISABLED)
                ->addProductClass($ProductClass)
                ->setStatus($Disp);
            $ProductClass
                ->setDelFlg(Constant::DISABLED)
                ->setStockUnlimited(true)
                ->setProduct($Product);
            $ProductStock = new \Eccube\Entity\ProductStock();
            $ProductClass->setProductStock($ProductStock);
            $ProductStock->setProductClass($ProductClass);
        } else {
            $Product = $app['eccube.repository.product']->find($id);
            if (!$Product) {
                throw new NotFoundHttpException();
            }
            // ?????????????????????
            $has_class = $Product->hasProductClass();
            if (!$has_class) {
                $ProductClasses = $Product->getProductClasses();
                $ProductClass = $ProductClasses[0];
                $BaseInfo = $app['eccube.repository.base_info']->get();
                if ($BaseInfo->getOptionProductTaxRule() == Constant::ENABLED && $ProductClass->getTaxRule() && !$ProductClass->getTaxRule()->getDelFlg()) {
                    $ProductClass->setTaxRate($ProductClass->getTaxRule()->getTaxRate());
                }
                $ProductStock = $ProductClasses[0]->getProductStock();
            }
        }

        $builder = $app['form.factory']
            ->createBuilder('admin_product', $Product);

        // ???????????????????????????????????????????????????Form????????????
        if ($has_class) {
            $builder->remove('class');
        }

        $event = new EventArgs(
            array(
                'builder' => $builder,
                'Product' => $Product,
            ),
            $request
        );
        $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_EDIT_INITIALIZE, $event);

        $form = $builder->getForm();

        if (!$has_class) {
            $ProductClass->setStockUnlimited((boolean)$ProductClass->getStockUnlimited());
            $form['class']->setData($ProductClass);
        }

        // ?????????????????????
        $images = array();
        $ProductImages = $Product->getProductImage();
        foreach ($ProductImages as $ProductImage) {
            $images[] = $ProductImage->getFileName();
        }
        $form['images']->setData($images);

        $categories = array();
        $ProductCategories = $Product->getProductCategories();
        foreach ($ProductCategories as $ProductCategory) {
            /* @var $ProductCategory \Eccube\Entity\ProductCategory */
            $categories[] = $ProductCategory->getCategory();
        }
        $form['Category']->setData($categories);

        $Tags = array();
        $ProductTags = $Product->getProductTag();
        foreach ($ProductTags as $ProductTag) {
            $Tags[] = $ProductTag->getTag();
        }
        $form['Tag']->setData($Tags);

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                log_info('??????????????????', array($id));
                $Product = $form->getData();

                if (!$has_class) {
                    $ProductClass = $form['class']->getData();

                    // ???????????????
                    $BaseInfo = $app['eccube.repository.base_info']->get();
                    if ($BaseInfo->getOptionProductTaxRule() == Constant::ENABLED) {
                        if ($ProductClass->getTaxRate() !== null) {
                            if ($ProductClass->getTaxRule()) {
                                if ($ProductClass->getTaxRule()->getDelFlg() == Constant::ENABLED) {
                                    $ProductClass->getTaxRule()->setDelFlg(Constant::DISABLED);
                                }

                                $ProductClass->getTaxRule()->setTaxRate($ProductClass->getTaxRate());
                            } else {
                                $taxrule = $app['eccube.repository.tax_rule']->newTaxRule();
                                $taxrule->setTaxRate($ProductClass->getTaxRate());
                                $taxrule->setApplyDate(new \DateTime());
                                $taxrule->setProduct($Product);
                                $taxrule->setProductClass($ProductClass);
                                $ProductClass->setTaxRule($taxrule);
                            }
                        } else {
                            if ($ProductClass->getTaxRule()) {
                                $ProductClass->getTaxRule()->setDelFlg(Constant::ENABLED);
                            }
                        }
                    }
                    $app['orm.em']->persist($ProductClass);

                    // ?????????????????????
                    if (!$ProductClass->getStockUnlimited()) {
                        $ProductStock->setStock($ProductClass->getStock());
                    } else {
                        // ?????????????????????null?????????
                        $ProductStock->setStock(null);
                    }
                    $app['orm.em']->persist($ProductStock);
                }

                // ?????????????????????
                // ???????????????
                /* @var $Product \Eccube\Entity\Product */
                foreach ($Product->getProductCategories() as $ProductCategory) {
                    $Product->removeProductCategory($ProductCategory);
                    $app['orm.em']->remove($ProductCategory);
                }
                $app['orm.em']->persist($Product);
                $app['orm.em']->flush();

                $count = 1;
                $Categories = $form->get('Category')->getData();
                $categoriesIdList = array();
                foreach ($Categories as $Category) {
                    foreach ($Category->getPath() as $ParentCategory) {
                        if (!isset($categoriesIdList[$ParentCategory->getId()])) {
                            $ProductCategory = $this->createProductCategory($Product, $ParentCategory, $count);
                            $app['orm.em']->persist($ProductCategory);
                            $count++;
                            /* @var $Product \Eccube\Entity\Product */
                            $Product->addProductCategory($ProductCategory);
                            $categoriesIdList[$ParentCategory->getId()] = true;
                        }
                    }
                    if (!isset($categoriesIdList[$Category->getId()])) {
                        $ProductCategory = $this->createProductCategory($Product, $Category, $count);
                        $app['orm.em']->persist($ProductCategory);
                        $count++;
                        /* @var $Product \Eccube\Entity\Product */
                        $Product->addProductCategory($ProductCategory);
                        $categoriesIdList[$Category->getId()] = true;
                    }
                }

                // ???????????????
                $add_images = $form->get('add_images')->getData();
                foreach ($add_images as $add_image) {
                    $ProductImage = new \Eccube\Entity\ProductImage();
                    $ProductImage
                        ->setFileName($add_image)
                        ->setProduct($Product)
                        ->setRank(1);
                    $Product->addProductImage($ProductImage);
                    $app['orm.em']->persist($ProductImage);

                    // ??????
                    $file = new File($app['config']['image_temp_realdir'].'/'.$add_image);
                    $file->move($app['config']['image_save_realdir']);
                }

                // ???????????????
                $delete_images = $form->get('delete_images')->getData();
                foreach ($delete_images as $delete_image) {
                    $ProductImage = $app['eccube.repository.product_image']
                        ->findOneBy(array('file_name' => $delete_image));

                    // ?????????????????????????????????????????????Entity?????????????????????
                    if ($ProductImage instanceof \Eccube\Entity\ProductImage) {
                        $Product->removeProductImage($ProductImage);
                        $app['orm.em']->remove($ProductImage);

                    }
                    $app['orm.em']->persist($Product);

                    // ??????
                    if (!empty($delete_image)) {
                        $fs = new Filesystem();
                        $fs->remove($app['config']['image_save_realdir'].'/'.$delete_image);
                    }
                }
                $app['orm.em']->persist($Product);
                $app['orm.em']->flush();


                $ranks = $request->get('rank_images');
                if ($ranks) {
                    foreach ($ranks as $rank) {
                        list($filename, $rank_val) = explode('//', $rank);
                        $ProductImage = $app['eccube.repository.product_image']
                            ->findOneBy(array(
                                'file_name' => $filename,
                                'Product' => $Product,
                            ));
                        $ProductImage->setRank($rank_val);
                        $app['orm.em']->persist($ProductImage);
                    }
                }
                $app['orm.em']->flush();

                // ?????????????????????
                // ??????????????????????????????
                $ProductTags = $Product->getProductTag();
                foreach ($ProductTags as $ProductTag) {
                    $Product->removeProductTag($ProductTag);
                    $app['orm.em']->remove($ProductTag);
                }

                // ?????????????????????
                $Tags = $form->get('Tag')->getData();
                foreach ($Tags as $Tag) {
                    $ProductTag = new ProductTag();
                    $ProductTag
                        ->setProduct($Product)
                        ->setTag($Tag);
                    $Product->addProductTag($ProductTag);
                    $app['orm.em']->persist($ProductTag);
                }

                $Product->setUpdateDate(new \DateTime());
                $app['orm.em']->flush();

                log_info('??????????????????', array($id));

                $event = new EventArgs(
                    array(
                        'form' => $form,
                        'Product' => $Product,
                    ),
                    $request
                );
                $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_EDIT_COMPLETE, $event);

                $app->addSuccess('admin.register.complete', 'admin');

                return $app->redirect($app->url('admin_product_product_edit', array('id' => $Product->getId())));
            } else {
                log_info('?????????????????????????????????', array($id));
                $app->addError('admin.register.failed', 'admin');
            }
        }

        // ?????????????????????
        $builder = $app['form.factory']
            ->createBuilder('admin_search_product');

        $event = new EventArgs(
            array(
                'builder' => $builder,
                'Product' => $Product,
            ),
            $request
        );
        $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_EDIT_SEARCH, $event);

        $searchForm = $builder->getForm();

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);
        }

        return $app->render('Product/product.twig', array(
            'Product' => $Product,
            'form' => $form->createView(),
            'searchForm' => $searchForm->createView(),
            'has_class' => $has_class,
            'id' => $id,
        ));
    }

    public function delete(Application $app, Request $request, $id = null)
    {
        $this->isTokenValid($app);
        $session = $request->getSession();
        $page_no = intval($session->get('eccube.admin.product.search.page_no'));
        $page_no = $page_no ? $page_no : Constant::ENABLED;

        if (!is_null($id)) {
            /* @var $Product \Eccube\Entity\Product */
            $Product = $app['eccube.repository.product']->find($id);
            if (!$Product) {
                $app->deleteMessage();

                return $app->redirect($app->url('admin_product_page', array('page_no' => $page_no)).'?resume='.Constant::ENABLED);
            }

            if ($Product instanceof \Eccube\Entity\Product) {
                log_info('??????????????????', array($id));

                $Product->setDelFlg(Constant::ENABLED);

                $ProductClasses = $Product->getProductClasses();
                $deleteImages = array();
                foreach ($ProductClasses as $ProductClass) {
                    $ProductClass->setDelFlg(Constant::ENABLED);
                    $Product->removeProductClass($ProductClass);

                    $ProductClasses = $Product->getProductClasses();
                    foreach ($ProductClasses as $ProductClass) {
                        $ProductClass->setDelFlg(Constant::ENABLED);
                        $Product->removeProductClass($ProductClass);

                        $ProductStock = $ProductClass->getProductStock();
                        $app['orm.em']->remove($ProductStock);
                    }

                    $ProductImages = $Product->getProductImage();
                    foreach ($ProductImages as $ProductImage) {
                        $Product->removeProductImage($ProductImage);
                        $deleteImages[] = $ProductImage->getFileName();
                        $app['orm.em']->remove($ProductImage);
                    }

                    $ProductCategories = $Product->getProductCategories();
                    foreach ($ProductCategories as $ProductCategory) {
                        $Product->removeProductCategory($ProductCategory);
                        $app['orm.em']->remove($ProductCategory);
                    }

                }

                $app['orm.em']->persist($Product);

                $app['orm.em']->flush();

                $event = new EventArgs(
                    array(
                        'Product' => $Product,
                        'ProductClass' => $ProductClasses,
                        'deleteImages' => $deleteImages,
                    ),
                    $request
                );
                $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_DELETE_COMPLETE, $event);
                $deleteImages = $event->getArgument('deleteImages');

                // ???????????????????????????(commit?????????????????????)
                foreach ($deleteImages as $deleteImage) {
                    try {
                        if (!empty($deleteImage)) {
                            $fs = new Filesystem();
                            $fs->remove($app['config']['image_save_realdir'].'/'.$deleteImage);
                        }
                    } catch (\Exception $e) {
                        // ???????????????????????????????????????
                    }
                }

                log_info('??????????????????', array($id));

                $app->addSuccess('admin.delete.complete', 'admin');
            } else {
                log_info('?????????????????????', array($id));
                $app->addError('admin.delete.failed', 'admin');
            }
        } else {
            log_info('?????????????????????', array($id));
            $app->addError('admin.delete.failed', 'admin');
        }

        return $app->redirect($app->url('admin_product_page', array('page_no' => $page_no)).'?resume='.Constant::ENABLED);
    }

    public function copy(Application $app, Request $request, $id = null)
    {
        $this->isTokenValid($app);

        if (!is_null($id)) {
            $Product = $app['eccube.repository.product']->find($id);
            if ($Product instanceof \Eccube\Entity\Product) {
                $CopyProduct = clone $Product;
                $CopyProduct->copy();
                $Disp = $app['eccube.repository.master.disp']->find(\Eccube\Entity\Master\Disp::DISPLAY_HIDE);
                $CopyProduct->setStatus($Disp);

                $CopyProductCategories = $CopyProduct->getProductCategories();
                foreach ($CopyProductCategories as $Category) {
                    $app['orm.em']->persist($Category);
                }

                // ??????????????????????????????, ??????????????????????????????????????????????????????.
                if ($CopyProduct->hasProductClass()) {
                    $softDeleteFilter = $app['orm.em']->getFilters()->getFilter('soft_delete');
                    $softDeleteFilter->setExcludes(array(
                        'Eccube\Entity\ProductClass'
                    ));
                    $dummyClass = $app['eccube.repository.product_class']->findOneBy(array(
                        'del_flg' => \Eccube\Common\Constant::ENABLED,
                        'ClassCategory1' => null,
                        'ClassCategory2' => null,
                        'Product' => $Product,
                    ));
                    $dummyClass = clone $dummyClass;
                    $dummyClass->setProduct($CopyProduct);
                    $CopyProduct->addProductClass($dummyClass);
                    $softDeleteFilter->setExcludes(array());
                }

                $CopyProductClasses = $CopyProduct->getProductClasses();
                foreach ($CopyProductClasses as $Class) {
                    $Stock = $Class->getProductStock();
                    $CopyStock = clone $Stock;
                    $CopyStock->setProductClass($Class);
                    $app['orm.em']->persist($CopyStock);

                    $app['orm.em']->persist($Class);
                }
                $Images = $CopyProduct->getProductImage();
                foreach ($Images as $Image) {

                    // ?????????????????????????????????
                    $extension = pathinfo($Image->getFileName(), PATHINFO_EXTENSION);
                    $filename = date('mdHis').uniqid('_').'.'.$extension;
                    try {
                        $fs = new Filesystem();
                        $fs->copy($app['config']['image_save_realdir'].'/'.$Image->getFileName(), $app['config']['image_save_realdir'].'/'.$filename);
                    } catch (\Exception $e) {
                        // ???????????????????????????????????????
                    }
                    $Image->setFileName($filename);

                    $app['orm.em']->persist($Image);
                }
                $Tags = $CopyProduct->getProductTag();
                foreach ($Tags as $Tag) {
                    $app['orm.em']->persist($Tag);
                }

                $app['orm.em']->persist($CopyProduct);

                $app['orm.em']->flush();

                $event = new EventArgs(
                    array(
                        'Product' => $Product,
                        'CopyProduct' => $CopyProduct,
                        'CopyProductCategories' => $CopyProductCategories,
                        'CopyProductClasses' => $CopyProductClasses,
                        'images' => $Images,
                        'Tags' => $Tags,
                    ),
                    $request
                );
                $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_COPY_COMPLETE, $event);

                $app->addSuccess('admin.product.copy.complete', 'admin');

                return $app->redirect($app->url('admin_product_product_edit', array('id' => $CopyProduct->getId())));
            } else {
                $app->addError('admin.product.copy.failed', 'admin');
            }
        } else {
            $app->addError('admin.product.copy.failed', 'admin');
        }

        return $app->redirect($app->url('admin_product'));
    }

    public function display(Application $app, Request $request, $id = null)
    {
        $event = new EventArgs(
            array(),
            $request
        );
        $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_DISPLAY_COMPLETE, $event);

        if (!is_null($id)) {
            return $app->redirect($app->url('product_detail', array('id' => $id, 'admin' => '1')));
        }

        return $app->redirect($app->url('admin_product'));
    }

    /**
     * ??????CSV?????????.
     *
     * @param Application $app
     * @param Request $request
     * @return StreamedResponse
     */
    public function export(Application $app, Request $request)
    {
        // ????????????????????????????????????.
        set_time_limit(0);

        // sql logger??????????????????.
        $em = $app['orm.em'];
        $em->getConfiguration()->setSQLLogger(null);

        $response = new StreamedResponse();
        $response->setCallback(function () use ($app, $request) {

            // CSV????????????????????????.
            $app['eccube.service.csv.export']->initCsvType(CsvType::CSV_TYPE_PRODUCT);

            // ?????????????????????.
            $app['eccube.service.csv.export']->exportHeader();

            // ??????????????????????????????????????????????????????.
            $qb = $app['eccube.service.csv.export']
                ->getProductQueryBuilder($request);

            // Get stock status
            $isOutOfStock = 0;
            $session = $request->getSession();
            if ($session->has('eccube.admin.product.search')) {
                $searchData = $session->get('eccube.admin.product.search', array());
                if (isset($searchData['stock_status']) && $searchData['stock_status'] === 0) {
                    $isOutOfStock = 1;
                }
            }

            // join???????????????iterate?????????????????????, select??????distinct??????.
            // http://qiita.com/suin/items/2b1e98105fa3ef89beb7
            // distinct???mysql???pgsql????????????????????????.
            // http://uedatakeshi.blogspot.jp/2010/04/distinct-oeder-by-postgresmysql.html
            $qb->resetDQLPart('select')
                ->resetDQLPart('orderBy')
                ->orderBy('p.update_date', 'DESC');

            if ($isOutOfStock) {
                $qb->select('p, pc')
                    ->distinct();
            } else {
                $qb->select('p')
                    ->distinct();
            }
            // ?????????????????????.
            $app['eccube.service.csv.export']->setExportQueryBuilder($qb);

            $app['eccube.service.csv.export']->exportData(function ($entity, CsvExportService $csvService) use ($app, $request) {
                $Csvs = $csvService->getCsvs();

                /** @var $Product \Eccube\Entity\Product */
                $Product = $entity;

                /** @var $ProductClassess \Eccube\Entity\ProductClass[] */
                $ProductClassess = $Product->getProductClasses();

                foreach ($ProductClassess as $ProductClass) {
                    $ExportCsvRow = new \Eccube\Entity\ExportCsvRow();

                    // CSV?????????????????????????????????????????????.
                    foreach ($Csvs as $Csv) {
                        // ????????????????????????.
                        $ExportCsvRow->setData($csvService->getData($Csv, $Product));
                        if ($ExportCsvRow->isDataNull()) {
                            // ???????????????????????????.
                            $ExportCsvRow->setData($csvService->getData($Csv, $ProductClass));
                        }

                        $event = new EventArgs(
                            array(
                                'csvService' => $csvService,
                                'Csv' => $Csv,
                                'ProductClass' => $ProductClass,
                                'ExportCsvRow' => $ExportCsvRow,
                            ),
                            $request
                        );
                        $app['eccube.event.dispatcher']->dispatch(EccubeEvents::ADMIN_PRODUCT_CSV_EXPORT, $event);

                        $ExportCsvRow->pushData();
                    }

                    // $row[] = number_format(memory_get_usage(true));
                    // ??????.
                    $csvService->fputcsv($ExportCsvRow->getRow());
                }
            });
        });

        $now = new \DateTime();
        $filename = 'product_'.$now->format('YmdHis').'.csv';
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename='.$filename);
        $response->send();

        log_info('??????CSV?????????????????????', array($filename));

        return $response;
    }

    /**
     * ProductCategory??????
     * @param \Eccube\Entity\Product $Product
     * @param \Eccube\Entity\Category $Category
     * @return \Eccube\Entity\ProductCategory
     */
    private function createProductCategory($Product, $Category, $count)
    {
        $ProductCategory = new \Eccube\Entity\ProductCategory();
        $ProductCategory->setProduct($Product);
        $ProductCategory->setProductId($Product->getId());
        $ProductCategory->setCategory($Category);
        $ProductCategory->setCategoryId($Category->getId());
        $ProductCategory->setRank($count);

        return $ProductCategory;
    }
}
