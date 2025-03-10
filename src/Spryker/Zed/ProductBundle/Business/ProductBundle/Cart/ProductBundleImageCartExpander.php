<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\ProductBundle\Business\ProductBundle\Cart;

use ArrayObject;
use Generated\Shared\Transfer\CartChangeTransfer;
use Generated\Shared\Transfer\ItemTransfer;
use Generated\Shared\Transfer\LocaleTransfer;
use Spryker\Zed\ProductBundle\Dependency\Facade\ProductBundleToLocaleFacadeInterface;
use Spryker\Zed\ProductBundle\Dependency\Facade\ProductBundleToProductImageFacadeInterface;

class ProductBundleImageCartExpander implements ProductBundleCartExpanderInterface
{
    /**
     * @var string
     */
    public const DEFAULT_IMAGE_SET_NAME = 'default';

    /**
     * @var \Spryker\Zed\ProductBundle\Dependency\Facade\ProductBundleToProductImageFacadeInterface
     */
    protected $productImageFacade;

    /**
     * @var \Spryker\Zed\ProductBundle\Dependency\Facade\ProductBundleToLocaleFacadeInterface
     */
    protected $localeFacade;

    /**
     * @param \Spryker\Zed\ProductBundle\Dependency\Facade\ProductBundleToProductImageFacadeInterface $productImageFacade
     * @param \Spryker\Zed\ProductBundle\Dependency\Facade\ProductBundleToLocaleFacadeInterface $localeFacade
     */
    public function __construct(
        ProductBundleToProductImageFacadeInterface $productImageFacade,
        ProductBundleToLocaleFacadeInterface $localeFacade
    ) {
        $this->productImageFacade = $productImageFacade;
        $this->localeFacade = $localeFacade;
    }

    /**
     * @param \Generated\Shared\Transfer\CartChangeTransfer $cartChangeTransfer
     *
     * @return \Generated\Shared\Transfer\CartChangeTransfer
     */
    public function expandBundleItems(CartChangeTransfer $cartChangeTransfer)
    {
        $currentLocaleTransfer = $this->localeFacade->getCurrentLocale();
        foreach ($cartChangeTransfer->getQuote()->getBundleItems() as $itemTransfer) {
            $this->expandItemsWithImages($itemTransfer, $currentLocaleTransfer);
        }

        return $cartChangeTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\ItemTransfer $itemTransfer
     * @param \Generated\Shared\Transfer\LocaleTransfer $localeTransfer
     *
     * @return void
     */
    protected function expandItemsWithImages(ItemTransfer $itemTransfer, LocaleTransfer $localeTransfer)
    {
        $imageSets = $this->productImageFacade->getCombinedConcreteImageSets(
            $itemTransfer->getId(),
            $itemTransfer->getIdProductAbstract(),
            $localeTransfer->getIdLocale(),
        );

        if (!$imageSets) {
            return;
        }

        $itemTransfer->setImages($this->getProductImages($imageSets));
    }

    /**
     * @param array<\Generated\Shared\Transfer\ProductImageSetTransfer> $imageSets
     *
     * @return \ArrayObject<int, \Generated\Shared\Transfer\ProductImageTransfer>
     */
    protected function getProductImages(array $imageSets)
    {
        foreach ($imageSets as $imageSet) {
            if ($imageSet->getName() === static::DEFAULT_IMAGE_SET_NAME) {
                return $imageSet->getProductImages();
            }

            return $imageSet->getProductImages();
        }

        return new ArrayObject();
    }
}
