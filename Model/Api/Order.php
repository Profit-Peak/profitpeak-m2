<?php
/**
 * Profit Peak
 *
 * @category  Profit Peak
 * @package   ProfitPeak_Tracking
 * @author
 * @copyright
 */

namespace ProfitPeak\Tracking\Model\Api;

use ProfitPeak\Tracking\Api\OrderSyncInterface;
use ProfitPeak\Tracking\Helper\Data;
use ProfitPeak\Tracking\Helper\Config;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderItemExtensionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Webapi\Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use ProfitPeak\Tracking\Logger\ProfitPeakLogger;

class Order implements OrderSyncInterface
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
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var DataObjectProcessor
     */
    protected $dataObjectProcessor;

    /**
     * @var OrderExtensionFactory
     */
    protected $extensionFactory;

    /**
     * @var OrderItemExtensionFactory
     */
    protected $itemExtensionFactory;

    /**
     * @var CreditmemoRepositoryInterface
     */
    protected $creditmemoRepository;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var ProfitPeakLogger
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    public function __construct(
        Data $helper,
        ResourceConnection $resource,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository,
        RequestInterface $request,
        DataObjectProcessor $dataObjectProcessor,
        CreditmemoRepositoryInterface $creditmemoRepository,
        ProductRepositoryInterface $productRepository,
        OrderExtensionFactory $extensionFactory,
        OrderItemExtensionFactory $itemExtensionFactory,
        ProfitPeakLogger $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->helper = $helper;
        $this->resource = $resource;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderRepository = $orderRepository;
        $this->request = $request;
        $this->dataObjectProcessor = $dataObjectProcessor;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->productRepository = $productRepository;
        $this->extensionFactory = $extensionFactory;
        $this->itemExtensionFactory = $itemExtensionFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    public function list($store_id)
    {
        $orderId = $this->request->getParam('id', null);
        $startDate = $this->request->getParam('start_date', null);
        $endDate = $this->request->getParam('end_date', null);
        $limit = $this->request->getParam('limit', 200);
        $page = $this->request->getParam('page', 1);
        $all = $this->request->getParam('all', '0') == '1';

        if (!is_numeric($limit)) {
            return $this->helper->sendJsonResponse([
                'message' => 'Limit required to be numeric',
            ], Exception::HTTP_BAD_REQUEST);
        }

        if ($orderId !== null && !is_numeric($orderId)) {
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
        $page = ($page - 1 ) * $limit;

        $data = $this->executeGetData($store_id, $orderId, $all, $startDate, $endDate, $limit,  $offset);

        return $this->helper->sendJsonResponse($data);
    }

    public function getById($store_id, $order_id)
    {
        $data = $this->executeGetData($store_id, $order_id, $all = true);
        $data['data'] = $data['data'][0] ?? null;

        return $this->helper->sendJsonResponse($data);
    }

    public function executeGetData($store_id = null, $orderId = null, $all = false, $startDate = null, $endDate = null, $limit = 200, $offset = 0)
    {
        $version = $this->helper->getVersion();
        $data = ['version' => $version, 'data' => []];

        $costAttribute = $this->scopeConfig->getValue(
            'profitpeak_tracking/sync/cost_attribute',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store_id ?? 1
        ) ?? 'cost';

        $connection = $this->resource->getConnection();
        $orderTable = $this->resource->getTableName('sales_order');
        $orderSyncTable = $this->resource->getTableName('profit_peak_order_sync');

        $select = $connection->select()
            ->from(['so' => $orderTable], ['entity_id', 'increment_id'])
            ->joinLeft(
                ['os' => $orderSyncTable],
                'so.entity_id = os.order_id',
                ['sent', 'user_agent', 'area_code']
            )
            ->where('so.store_id = ?', $store_id)
            ->limit($limit, $page)
            ->order('os.updated_at ASC');

        if ($orderId) {
            $select->where('so.entity_id = ?', $orderId);
        }

        if (!$all) {
            $select->where('os.sent IS NULL OR os.sent = ?', 0);
        }

        // Apply date range filter if provided
        if ($startDate) {
            $select->where('so.created_at >= ?', $startDate);
        }

        if ($endDate) {
            $select->where('so.created_at < ?', $endDate);
        }

        $orderRows = $connection->fetchAll($select);

        if (empty($orderRows)) {
            return $data;
        }

        $ordersArray = [];
        foreach ($orderRows as $orderRow) {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('entity_id', $orderRow['entity_id'], 'eq')
                ->create();
            $orders = $this->orderRepository->getList($searchCriteria)->getItems();
            foreach ($orders as $order) {
                $extension = $order->getExtensionAttributes();

                if ($extension === null) {
                    $extension = $this->extensionFactory->create();
                }

                if (!empty($orderRow['user_agent'])) {
                    $extension->setUserAgent($orderRow['user_agent']);
                }

                if (!empty($orderRow['area_code'])) {
                    $extension->setAreaCode($orderRow['area_code']);
                }

                $items = $order->getItems();

                foreach ($items as $item) {
                    $productType = $item->getProductType();
                    $itemExtension = $item->getExtensionAttributes();
                    $product = $this->productRepository->getById($item->getProductId());

                    if ($itemExtension === null) {
                        $itemExtension = $this->itemExtensionFactory->create();
                    }

                    $itemExtension->setCost($product->getData($costAttribute));

                    if ($productType === 'grouped') {
                        $productOptions = $item->getProductOptions();

                        $productCode = $productOptions['super_product_config']['product_code'] ?? null;
                        $productType = $productCode !== null ? $productOptions['super_product_config'][$productCode] : null;
                        $productId = $productOptions['super_product_config']['product_id'] ?? null;

                        if ($productType === 'grouped' && !empty($productId)) {
                            $groupedProduct = $this->productRepository->getById($productId);

                            if (!empty($groupedProduct)) {
                                $itemExtension->setGroupedProduct($groupedProduct);
                            }
                        }
                    } else if ($productType === 'bundle') {
                        $itemExtension->setDynamicPrice($product->getPriceType() == \Magento\Bundle\Model\Product\Price::PRICE_TYPE_DYNAMIC);
                    } else {
                        continue;
                    }
                }

                $creditMemos = $this->creditmemoRepository->getList(
                    $this->searchCriteriaBuilder
                        ->addFilter('order_id', $order->getEntityId(), 'eq')
                        ->create()
                )->getItems();

                foreach ($creditMemos as $creditMemo) {
                    foreach ($creditMemo->getItems() as $creditMemoItem) {
                        $productId = $creditMemoItem->getProductId();

                        if (!$productId) {
                            continue; // Skip items without a product ID
                        }

                        // Fetch the product data
                        $product = $this->productRepository->getById($productId);

                        $orderItemExtension = $creditMemoItem->getExtensionAttributes();
                        if ($orderItemExtension === null) {
                            $orderItemExtension = $this->itemExtensionFactory->create();
                        }
                        $orderItem = $orderItemExtension->getOrderItem();
                        $orderItemExtension->setCost($product->getData($costAttribute));

                        $extenstionOrderItemExtension = $orderItem->getExtensionAttributes();
                        if ($extenstionOrderItemExtension === null) {
                            $extenstionOrderItemExtension = $this->itemExtensionFactory->create();
                        }
                        $extenstionOrderItemExtension->setCost($product->getData($costAttribute));

                    }
                }

                $extension->setCreditMemos($creditMemos);

                $order->setExtensionAttributes($extension);

                // Build the order data array
                $orderData = $this->dataObjectProcessor->buildOutputDataArray(
                    $order,
                    OrderInterface::class
                );

                $ordersArray[] = $orderData;
            }
        }

        $data['data'] = $ordersArray;


        return $data;
    }

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
            $orderSyncTable = $this->resource->getTableName('profit_peak_order_sync');

            foreach ($postData as $order) {
                $orderId = $order['id'] ?? null;
                $orderSent = isset($order['sent']) ? (bool) $order['sent'] : true;

                if (!$orderId) {
                    continue;
                }

                $connection->insertOnDuplicate($orderSyncTable, [
                    'order_id' => $orderId,
                    'store_id' => $store_id,
                    'sent' => $orderSent ? 1 : 0,
                ], [
                    'sent'
                ]);
            }

            $data['success'] = true;

        } catch (\Exception $e) {
            $data['message'] = $e->getMessage();
            $this->logger->error("Error updating order: " . $e->getMessage() . "\nBody:\n" . json_encode(json_decode($body), JSON_PRETTY_PRINT));
        }

        return $this->helper->sendJsonResponse($data);
    }
}
