<?php
namespace Komoju\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\ObjectManager;

/**
 * The PaymentMethodAvailable observer is a class that gets executed when the
 * payment_method_is_active event is fired (this is configured in the events.xml
 * file). It is responsible for determining whether the magento store is in an
 * acceptable state to display the payment method at checkout. If the store does
 * not meet the requirements then Komoju is not displayed as a possible option. This
 * works in conjunction with the is_active setting in the admin configuration, for
 * the Komoju payment method to be available it needs to be enabled and meet the
 * requirements in this class.
 */
class PaymentMethodAvailable implements ObserverInterface
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var array
     */
    private $allowableCurrencyCodes;

    /** @var string */
    private $methodCode;

    private $logger;

    /**
     * Class constructor. The methodCode and allowableCurrencyCodes are passed in
     * through dependency injection, and are configured in etc/di/xml.
     */
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        $methodCode,
        $allowableCurrencyCodes,
        \Psr\Log\LoggerInterface $logger = null
    ) {
        $this->storeManager = $storeManager;
        $this->methodCode = $methodCode;
        $this->allowableCurrencyCodes = $allowableCurrencyCodes;
        $this->logger = $logger ?: ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
    }

    /**
     * Is run when the payment_method_is_active event is fired. If the store's base
     * currency is in the list of allowable currencies then the is_available property
     * is set to true to enable Komoju as a payment option on checkout
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if ($observer->getEvent()->getMethodInstance()->getCode()==$this->methodCode) {
            $checkResult = $observer->getEvent()->getResult();

            $baseCurrencyCode = $this->storeManager->getStore()->getBaseCurrencyCode();
            if (in_array($baseCurrencyCode, $this->allowableCurrencyCodes)) {
                $checkResult->setData('is_available', true);
            } else {
                $this->logger->info(
                    __(
                        'Store currency code: %1 is not in the list of allowable currency codes for KOMOJU',
                        $baseCurrencyCode
                    )
                );
                $checkResult->setData('is_available', false);
            }
        }
    }
}
