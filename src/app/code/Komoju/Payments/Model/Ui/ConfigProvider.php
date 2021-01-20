<?php

namespace Komoju\Payments\Model\Ui;

require_once dirname(__FILE__) . '/../../komoju-php/lib/komoju.php';

use Magento\Framework\App\ObjectManager;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

use Komoju\Payments\Gateway\Config\Config;

/**
 * The ConfigProvider class is responsible for passing config variables to the
 * checkout page, to be used by the JS that gets run when the customer decides to
 * purchase with Komoju.
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * This code is the constant used to ensure that the module is only
     * operating on itself, and not accidentally changing values in any other
     * plugin. It's passed around via the di.xml files.
     */
    const CODE = 'komoju_payments';

    // when this array updates make sure to update the comment at system.xml:14
    const ALLOWABLE_CURRENCY_CODES = ['JPY'];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var SessionManagerInterface
     */
    private $session;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     *
     * @param Config $config
     * @param SessionManagerInterface $session
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Config $config,
        SessionManagerInterface $session,
        ScopeConfigInterface $scopeConfig,
        \Psr\Log\LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->session = $session;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger ?: ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
    }

    /**
     * Retrieve assoc array of checkout configuration. Because it stores the
     * variables in the HTML, nothing that is considered sensitive should be
     * returned from this method, otherwise it will be available publicly. The
     * values can be found on the checkout page at:
     * window.checkoutConfig.payment['komoju_payments']
     * @return array
     */
    public function getConfig()
    {
        $storeId = $this->session->getStoreId();
        $isActive = $this->config->isActive($storeId);

        return [
            'payment' => [
                self::CODE => [
                    'isActive' => $isActive,
                    'title' => $this->config->getTitle($storeId),
                    'available_payment_methods' => $this->createPaymentMethodOptions(),
                    'merchant_id'  => $this->config->getMerchantId(),
                    'redirect_url' => $this->config->getRedirectUrl(),
                ]
            ]
        ];
    }

    /**
     * Returns an array of available payment methods based on what the store
     * owners have decided to allow for their store. The options list is being on
     * the server instead of in the JS to ensure we can properly translate the
     * options. If the store is set up to display text in Japanese we want to
     * make sure that the payments are also translated, which is not possible if
     * the options are generated in the JS code.
     * @return array
     */
    private function createPaymentMethodOptions()
    {
        try {
            $paymentMethodOptions = [];
            $secretKey = $this->config->getSecretKey();
            $komojuApi = new \KomojuApi($secretKey);
            $locale = $this->config->getKomojuLocale();

            $methods = $komojuApi->paymentMethods();
            foreach ($methods as $method) {
              $paymentMethodOptions[$method->type_slug] = $method->{"name_{$locale}"};
            }
            return $paymentMethodOptions;
        } catch (\KomojuExceptionBadServer | \KomojuExceptionBadJson $exception) {
            $message = 'Error retrieving payment methods from Komoju: ' . $exception->getMessage();
            $this->logger->info($message);
            return [];
        }

    }
}
