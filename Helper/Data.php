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

use Magento\Framework\App\Helper\Context;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Webapi\Exception;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\ScopeInterface;

use ProfitPeak\Tracking\Helper\Config;

class Data extends AbstractHelper
{
    /**
     * @var Quote
     */
    protected $quote;

    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;

    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @var ModuleListInterface
     */
    protected $moduleList;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var string
     */
    const TRACKING_MODULE_NAME = 'ProfitPeak_Tracking';

    public function __construct(
        Context $context,
        Quote $quote,
        QuoteFactory $quoteFactory,
        DirectoryList $directoryList,
        ModuleListInterface $moduleList,
        Response $response,
        ScopeConfigInterface $scopeConfig,
    ) {
        $this->quote = $quote;
        $this->quoteFactory = $quoteFactory;
        $this->directoryList = $directoryList;
        $this->moduleList = $moduleList;
        $this->response = $response;
        $this->scopeConfig = $scopeConfig;

        parent::__construct($context);
    }

    public function getCustomerIP()
    {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return $ip;
    }

    public function getQuote($quoteId)
    {
        return $this->quoteFactory->create()->load($quoteId);
    }

    public function getVersion()
    {
        // Get the path to the module's composer.json file
        $modulePath = $this->directoryList->getRoot() . '/vendor/profitpeak/tracking/composer.json';

        if (!file_exists($modulePath)) {
            throw new Exception(__('Missing composer.json'), 0, Exception::HTTP_INTERNAL_ERROR);
        }

        $composerJson = file_get_contents($modulePath);
        $composerData = json_decode($composerJson, true);

        if (!isset($composerData['version'])) {
            throw new Exception(__('Missing profitpeak extension version in composer.json'), 0, Exception::HTTP_INTERNAL_ERROR);
        }

        return $composerData['version'];
    }

    public function checkModule()
    {
        $modules = [
            [
                'name' => 'ProfitPeak Tracking',
                'code' => 'profitpeak_tracking',
                'version' => $this::getVersion()
            ]
        ];

        return $modules;
    }

    /**
     * @param array $data
     * @param int $status
     * @return void
     */
    public function sendJsonResponse($data, $status = 200)
    {
        return $this->response
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setBody(json_encode($data))
            ->setStatusCode($status)
            ->sendResponse();
    }

    public function getAnalyticsId($storeId) {
        return $this->scopeConfig->getValue(Config::XML_PATH_ANALYTICS_ID, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
