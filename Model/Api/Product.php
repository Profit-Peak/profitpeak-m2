<?php
/**
 * Profit Peak
 *
 * @category  Profit Peak
 * @package   ProfitPeak_Tracking
 * @author    Profit Peak Team <admin@profitpeak.io>
 * @copyright Copyright Profit Peak (https://profitpeak.io/)
 */

namespace ProfitPeak\Tracking\Model\Api;

use ProfitPeak\Tracking\Api\ProductSyncInterface;
use ProfitPeak\Tracking\Helper\Data;
use ProfitPeak\Tracking\Helper\Config;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Webapi\Exception;
use ProfitPeak\Tracking\Logger\ProfitPeakLogger;

class Product implements ProductSyncInterface
{
    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var DataObjectProcessor
     */
    protected $dataObjectProcessor;

    /**
     * @var ProfitPeakLogger
     */
    protected $logger;

    public function __construct(
        Data $helper,
        ResourceConnection $resource,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ProductRepositoryInterface $productRepository,
        RequestInterface $request,
        DataObjectProcessor $dataObjectProcessor,
        ProfitPeakLogger $logger
    ) {
        $this->helper = $helper;
        $this->resource = $resource;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->productRepository = $productRepository;
        $this->request = $request;
        $this->dataObjectProcessor = $dataObjectProcessor;
        $this->logger = $logger;
    }


    public function list($store_id)
    {
        // Get query parameters from the URL
        $productId = $this->request->getParam('id', null);
        $limit = $this->request->getParam('limit', 200);
        $all = $this->request->getParam('all', '0') == '1';
        $page = $this->request->getParam('page', 1);

        if (!is_numeric($limit)) {
            return $this->helper->sendJsonResponse([
                'message' => 'Limit required to be numeric',
            ], Exception::HTTP_BAD_REQUEST);
        }

        if ($productId !== null && !is_numeric($productId)) {
            return $this->helper->sendJsonResponse([
                'message' => 'Id required to be numeric',
            ], Exception::HTTP_BAD_REQUEST);
        }

        if (!is_numeric($page)) {
            return $this->helper->sendJsonResponse([
                'message' => 'Page required to be numeric',
            ], Exception::HTTP_BAD_REQUEST);
        }

        $limit = (int) $limit;
        $limit = $limit > Config::MAX_LIMIT ? Config::MAX_LIMIT : $limit;

        $page = (int) $page;
        $offset = ($page - 1 ) * $limit;

        // Fetch orders based on store_id, orderId, startDate, and endDate
        $data = $this->executeGetData($store_id, $productId, $all, $limit, $offset);

        return $this->helper->sendJsonResponse($data);
    }

    public function getById($store_id, $product_id)
    {
        // Fetch a specific product by ID and store_id
        $data = $this->executeGetData($store_id, $product_id, $all = true);
        $data['data'] = $data['data'][0] ?? null;

        return $this->helper->sendJsonResponse($data);
    }

    public function executeGetData($store_id = null, $productId = null, $all = false, $limit = 200, $offset = 0)
    {
        $version = $this->helper->getVersion();
        $data = ['version' => $version, 'data' => []];

        try {
            $connection = $this->resource->getConnection();
            $productTable = $this->resource->getTableName('catalog_product_entity');
            $productSyncTable = $this->resource->getTableName('profit_peak_product_sync');
            $productWebsiteTable = $this->resource->getTableName('catalog_product_website');
            $storeTable = $this->resource->getTableName('store');

            $selectStore = $connection->select()
                ->from($storeTable, ['website_id'])
                ->where('store_id = ?', $store_id);
            $websiteId = $connection->fetchOne($selectStore);

            if (!$websiteId) {
                throw new \Exception("Website not found for store ID: $store_id");
            }

            // Build the select query to fetch product IDs
            $select = $connection
                ->select()
                ->from(['p' => $productTable], ['entity_id'])
                ->joinLeft(
                    ['ps' => $productSyncTable],
                    'p.entity_id = ps.product_id',
                    ['sent']
                )
                ->join(
                    ['pw' => $productWebsiteTable],
                    'p.entity_id = pw.product_id',
                    []
                )
                ->where('pw.website_id = ?', $websiteId)
                ->limit($limit, $offset)
                ->order('ps.updated_at ASC');

            if ($productId) {
                $select->where('p.entity_id = ?', $productId);
            }

            if (!$all) {
                $select->where('ps.sent IS NULL OR ps.sent = ?', 0);
            }

            $productIds = $connection->fetchCol($select);

            if (empty($productIds)) {
                return $data;
            }

            // Fetch products using the repository and search criteria
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('entity_id', $productIds, 'in')
                ->create();

            $unsyncedProducts = $this->productRepository->getList($searchCriteria)->getItems();

            // Process each product and format the response like the native API
            $productsArray = [];
            foreach ($unsyncedProducts as $product) {
                $productId = $product->getId();

                // extension attributes
                $extensionAttributes = $product->getExtensionAttributes();
                if ($extensionAttributes === null) {
                    $extensionAttributes = $this->productRepository->getExtensionAttributesFactory()->create();
                }

                if ($product->getTypeId() === 'bundle') {
                    // Check if dynamic pricing is enabled for the bundle product
                    $dynamicPrice = $product->getPriceType() == \Magento\Bundle\Model\Product\Price::PRICE_TYPE_DYNAMIC;
                    $extensionAttributes->setDynamicPrice($dynamicPrice);
                } else {
                    $extensionAttributes->setDynamicPrice(false);
                }

                $productData = $this->dataObjectProcessor->buildOutputDataArray(
                    $product,
                    ProductInterface::class
                );

                $productsArray[] = $productData;
            }

            $data['data'] = $productsArray;
        } catch (\Exception $e) {
            throw $e;
        }

        return $data;
    }

    /**
     * Set products as synced.
     */
    public function updateMany($store_id)
    {
        $data = ['success' => false];
        $body = $this->request->getContent();

        try {
            $postData = json_decode($body, true);

            if (!is_array($postData)) {
                $data['message'] = 'Body required to be an array';
                return $this->helper->sendJsonResponse($data, Exception::HTTP_BAD_REQUEST);
            }

            $connection = $this->resource->getConnection();
            $productSyncTable = $this->resource->getTableName('profit_peak_product_sync');

            foreach ($postData as $product) {
                $productId = isset($product['id']) ? $product['id'] : null;
                $sent = isset($product['sent']) ? (bool) $product['sent'] : true;

                if (!$productId) {
                    continue;
                }

                $connection->insertOnDuplicate($productSyncTable, [
                    'product_id' => $productId,
                    'store_id' => $store_id,
                    'sent' => $sent ? 1 : 0,
                ], [
                    'sent'
                ]);
            }

            // Set the response to success
            $data['success'] = true;

        } catch (\Exception $e) {
            $data['message'] = $e->getMessage();
            $this->logger->error("Error updating order: " . $e->getMessage() . "\nBody:\n" . json_encode(json_decode($body), JSON_PRETTY_PRINT));
        }

        return $this->helper->sendJsonResponse($data);
    }
}
