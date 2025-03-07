<?php
/**
* Profit Peak
*
* @category  Profit Peak
* @package   ProfitPeak_Tracking
* @author    Profit Peak Team <admin@profitpeak.io>
* @copyright Copyright Profit Peak (https://profitpeak.io/)
*/

namespace ProfitPeak\Tracking\Helper;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Helper\Product;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Variants extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * @var CollectionFactory
     */
    protected $_productCollectionFactory;

    /**
     * @var Product
     */
    protected $_productHelper;

    /**
     * @var ProductRepositoryInterface
     */
    protected $_productRepository;

    /**
     * @var ScopeConfigInterface
     */
    protected $_scopeConfig;

    public function __construct(
        CollectionFactory $productCollectionFactory,
        Product $productHelper,
        ProductRepositoryInterface $productRepository,
        ScopeConfigInterface $scopeConfig

    ) {
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->_productHelper = $productHelper;
        $this->_productRepository = $productRepository;
        $this->_scopeConfig = $scopeConfig;
    }


    protected function buildConfigVariants($product)
    {
        $priceAttribute = $this->_scopeConfig->getValue(
            'profitpeak_tracking/sync/price_attribute',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store_id ?? 1
        ) ?? 'price';

        $costAttribute = $this->_scopeConfig->getValue(
            'profitpeak_tracking/sync/cost_attribute',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store_id ?? 1
        ) ?? 'cost';

        $variants = [];
        if($product && $product->getId()) {
            $childIds = $product->getTypeInstance()->getChildrenIds($product->getId());

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            if ($childIds) {
                if (isset($childIds[0])) {
                    $productCollection = $this->_productCollectionFactory->create();
                    $productCollection->addAttributeToSelect('id')
                        ->addAttributeToSelect('name')
                        ->addAttributeToSelect('sku')
                        ->addAttributeToSelect('price')
                        ->addAttributeToSelect('special_price')
                        ->addAttributeToSelect('status')
                        ->addAttributeToSelect('visibility')
                        ->addAttributeToSelect('type_id')
                        ->addAttributeToSelect('created_at')
                        ->addAttributeToSelect('updated_at')
                        ->addAttributeToSelect('manufacturer')
                        ->addAttributeToSelect('weight')
                        ->addAttributeToSelect('cost');

                    if($priceAttribute !== 'price') {
                        $productCollection->addAttributeToSelect($priceAttribute);
                    }

                    if($costAttribute !== 'cost') {
                        $productCollection->addAttributeToSelect($costAttribute);
                    }

                    $productCollection->addIdFilter($childIds[0]);
                    $variants = $productCollection->getItems();
                }
            }
        }
        return $variants;
    }

    protected function buildBundleVariants($product)
    {
        $variants = [];
        if($product && $product->getId()) {
            $optionsCollection = $product->getTypeInstance(true)->getSelectionsCollection(
                $product->getTypeInstance(true)->getOptionsIds($product),
                $product
            );

            foreach ($optionsCollection as $options) {
                if ($options->getTypeId() === 'simple') {
                    $variants[] = $options;
                }
            }
        }
        return $variants;
    }

    protected function buildGroupedVariants($product)
    {
        $variants = [];
        if($product && $product->getId()) {
            $options = $product->getTypeInstance(true)->getAssociatedProducts($product);
            foreach ($options as $option) {
                if ($option->getTypeId() === 'simple') {
                    $variants[] = $option;
                }
            }
        }
        return $variants;
    }

    public function getVariants(ProductInterface $product)
    {
        $variants = [];

        if ($product->getTypeId() == 'configurable') {
            $variants = $this->buildConfigVariants($product);
        } elseif ($product->getTypeId() == 'bundle') {
            $variants = $this->buildBundleVariants($product);
        } elseif ($product->getTypeId() == 'grouped') {
            $variants = $this->buildGroupedVariants($product);
        }

        return $variants;
    }
}
