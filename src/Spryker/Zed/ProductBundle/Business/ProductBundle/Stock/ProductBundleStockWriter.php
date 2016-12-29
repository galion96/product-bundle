<?php
/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\ProductBundle\Business\ProductBundle\Stock;

use Generated\Shared\Transfer\ProductConcreteTransfer;
use Generated\Shared\Transfer\StockProductTransfer;
use Orm\Zed\Stock\Persistence\SpyStockProduct;
use Propel\Runtime\Collection\ObjectCollection;
use Spryker\Zed\ProductBundle\Business\ProductBundle\Availability\ProductBundleAvailabilityHandlerInterface;
use Spryker\Zed\ProductBundle\Dependency\QueryContainer\ProductBundleToStockQueryContainerInterface;
use Spryker\Zed\ProductBundle\Persistence\ProductBundleQueryContainerInterface;

class ProductBundleStockWriter implements ProductBundleStockWriterInterface
{

    /**
     * @var \Spryker\Zed\ProductBundle\Persistence\ProductBundleQueryContainerInterface
     */
    protected $productBundleQueryContainer;

    /**
     * @var \Spryker\Zed\ProductBundle\Dependency\QueryContainer\ProductBundleToStockQueryContainerInterface
     */
    protected $stockQueryContainer;

    /**
     * @var \Spryker\Zed\ProductBundle\Business\ProductBundle\Availability\ProductBundleAvailabilityHandler
     */
    protected $productBundleAvailabilityHandler;

    /**
     * @param \Spryker\Zed\ProductBundle\Persistence\ProductBundleQueryContainerInterface $productBundleQueryContainer
     * @param \Spryker\Zed\ProductBundle\Dependency\QueryContainer\ProductBundleToStockQueryContainerInterface $stockQueryContainer
     * @param \Spryker\Zed\ProductBundle\Business\ProductBundle\Availability\ProductBundleAvailabilityHandlerInterface $productBundleAvailabilityHandler
     */
    public function __construct(
        ProductBundleQueryContainerInterface $productBundleQueryContainer,
        ProductBundleToStockQueryContainerInterface $stockQueryContainer,
        ProductBundleAvailabilityHandlerInterface $productBundleAvailabilityHandler
    ) {
        $this->productBundleQueryContainer = $productBundleQueryContainer;
        $this->stockQueryContainer = $stockQueryContainer;
        $this->productBundleAvailabilityHandler = $productBundleAvailabilityHandler;
    }

    /**
     * @param \Generated\Shared\Transfer\ProductConcreteTransfer $productConcreteTransfer
     *
     * @return \Generated\Shared\Transfer\ProductConcreteTransfer
     */
    public function updateStock(ProductConcreteTransfer $productConcreteTransfer)
    {
        $productConcreteTransfer->requireSku()
            ->requireIdProductConcrete();

        $bundleProductEntity = $this->findProductBundleBySku($productConcreteTransfer->getSku());

        if ($bundleProductEntity === null) {
            return $productConcreteTransfer;
        }

        $bundleItems = $this->findBundledItemsByIdBundleProduct($productConcreteTransfer->getIdProductConcrete());

        $bundleTotalStockPerWarehause = $this->calculateBundleStockPerWarehouse($bundleItems);

        $this->updateBundleStock($productConcreteTransfer, $bundleTotalStockPerWarehause);

        $this->productBundleAvailabilityHandler->updateBundleAvailability($productConcreteTransfer->getSku());

        return $productConcreteTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\ProductConcreteTransfer $productConcreteTransfer
     * @param array $bundleTotalStockPerWarehause
     *
     * @return void
     */
    protected function updateBundleStock(
        ProductConcreteTransfer $productConcreteTransfer,
        array $bundleTotalStockPerWarehause
    ) {

        foreach ($bundleTotalStockPerWarehause as $idStock => $bundleStock) {

            $stockEntity = $this->findOrCreateProductStockEntity($productConcreteTransfer, $idStock);

            $stockEntity->setQuantity($bundleStock);
            $stockEntity->save();

            $stockTransfer = $this->mapStockTransfer($productConcreteTransfer, $stockEntity);

            $productConcreteTransfer->addStock($stockTransfer);

        }
    }

    /**
     * @param \Propel\Runtime\Collection\ObjectCollection|\Orm\Zed\ProductBundle\Persistence\Base\SpyProductBundle[] $bundleItems
     *
     * @return array
     */
    protected function calculateBundleStockPerWarehouse(ObjectCollection $bundleItems)
    {
        $bundledItemStock = [];
        $bundledItemQuantity = [];
        foreach ($bundleItems as $bundleItemEntity) {
            $bundledProductEntity = $bundleItemEntity->getSpyProductRelatedByFkBundledProduct();

            $bundledItemQuantity[$bundledProductEntity->getIdProduct()] = $bundleItemEntity->getQuantity();

            $productStocks = $this->findProductStocks($bundledProductEntity->getIdProduct());

            foreach ($productStocks as $productStockEntity) {
                if (!isset($bundledItemStock[$productStockEntity->getFkStock()])) {
                    $bundledItemStock[$productStockEntity->getFkStock()] = [];
                }

                if (!isset($bundledItemStock[$productStockEntity->getFkStock()][$productStockEntity->getFkProduct()])) {
                    $bundledItemStock[$productStockEntity->getFkStock()][$productStockEntity->getFkProduct()] = [];
                }

                $bundledItemStock[$productStockEntity->getFkStock()][$productStockEntity->getFkProduct()] = $productStockEntity->getQuantity();
            }
        }

        return $this->groupBundleStockByWarehouse($bundledItemStock, $bundledItemQuantity);
    }

    /**
     * @param array $bundledItemStock
     * @param array $bundledItemQuantity
     *
     * @return array
     */
    protected function groupBundleStockByWarehouse(array $bundledItemStock, array $bundledItemQuantity)
    {
        $bundleTotalStockPerWarehause = [];
        foreach ($bundledItemStock as $idStock => $warehouseStock) {
            $bundleStock = 0;
            foreach ($warehouseStock as $idProduct => $productStockQuantity) {

                $quantity = $bundledItemQuantity[$idProduct];

                $itemStock = (int)floor($productStockQuantity / $quantity);

                if ($bundleStock > $itemStock || $bundleStock == 0) {
                    $bundleStock = $itemStock;
                }
            }

            $bundleTotalStockPerWarehause[$idStock] = $bundleStock;
        }
        return $bundleTotalStockPerWarehause;
    }

    /**
     * @param string $sku
     *
     * @return \Orm\Zed\ProductBundle\Persistence\SpyProductBundle
     */
    protected function findProductBundleBySku($sku)
    {
        return $this->productBundleQueryContainer
            ->queryBundleProductBySku($sku)
            ->findOne();
    }

    /**
     * @param int $idProductConcrete
     *
     * @return \Orm\Zed\ProductBundle\Persistence\SpyProductBundle[]|\Propel\Runtime\Collection\ObjectCollection
     */
    protected function findBundledItemsByIdBundleProduct($idProductConcrete)
    {
        return $this->productBundleQueryContainer
            ->queryBundleProduct($idProductConcrete)
            ->find();
    }

    /**
     * @param \Generated\Shared\Transfer\ProductConcreteTransfer $productConcreteTransfer
     * @param int $idStock
     *
     * @return \Orm\Zed\Stock\Persistence\SpyStockProduct
     */
    protected function findOrCreateProductStockEntity(ProductConcreteTransfer $productConcreteTransfer, $idStock)
    {
        return $this->stockQueryContainer
            ->queryStockByProducts($productConcreteTransfer->getIdProductConcrete())
            ->filterByFkStock($idStock)
            ->findOneOrCreate();
    }

    /**
     * @param \Generated\Shared\Transfer\ProductConcreteTransfer $productConcreteTransfer
     * @param SpyStockProduct $stockProductEntity
     *
     * @return \Generated\Shared\Transfer\StockProductTransfer
     */
    protected function mapStockTransfer(ProductConcreteTransfer $productConcreteTransfer, SpyStockProduct $stockProductEntity)
    {
        $stockTransfer = new StockProductTransfer();
        $stockTransfer->setSku($productConcreteTransfer->getSku());
        $stockTransfer->setStockType($stockProductEntity->getStock()->getName());
        $stockTransfer->fromArray($stockProductEntity->toArray(), true);

        return $stockTransfer;
    }

    /**
     * @param int $idProduct
     *
     * @return \Orm\Zed\Stock\Persistence\SpyStockProduct[]|\Propel\Runtime\Collection\ObjectCollection
     */
    protected function findProductStocks($idProduct)
    {
        return $this->stockQueryContainer
            ->queryStockByProducts($idProduct)
            ->find();
    }

}
