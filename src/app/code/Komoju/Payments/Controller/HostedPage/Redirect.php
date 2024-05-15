<?php

namespace Komoju\Payments\Controller\HostedPage;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Komoju\Payments\Model\ExternalPayment;
use Komoju\Payments\Model\ExternalPaymentFactory;
use Magento\Directory\Model\CountryFactory;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

use Komoju\Payments\Api\KomojuApi;
use Magento\Framework\Controller\Result\RedirectFactory;
use Komoju\Payments\Exception\KomojuExceptionBadServer;
use Komoju\Payments\Exception\InvalidJsonException;
use Komoju\Payments\Gateway\Config\Config;

/**
 * The Redirect endpoint is responsible for creating the Hosted Page URL
 * and redirecting the customers to it to capture payment. This endpoint is
 * called from the checkout page js, after the order details have been captured and
 * saved by Magento.
 */
class Redirect extends Action
{
    protected RedirectFactory $_resultRedirectFactory;
    protected Session $_checkoutSession;
    private LoggerInterface $logger;
    private Config $config;

    private $order = false;
    private $externalPayment;
    private $komojuApi;
    private $countryFactory;
    private $storeManager;

    public function __construct(
        Context $context,
        RedirectFactory $resultRedirectFactory,
        ExternalPaymentFactory $externalPaymentFactory,
        Session $checkoutSession,
        Config $config,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger = null,
        KomojuApi $komojuApi,
        CountryFactory $countryFactory
    ) {
        $this->logger = $logger ?: ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
        $this->externalPayment = $externalPaymentFactory->create();
        $this->_resultRedirectFactory = $resultRedirectFactory;
        $this->_checkoutSession = $checkoutSession;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->komojuApi = $komojuApi;
        $this->countryFactory = $countryFactory;

        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->_resultRedirectFactory->create();

        try {
            $hostedPageUrl = $this->createHostedPageUrl();
            $this->markOrderAsPendingPayment();
            $this->logger->info('redirecting user to hosted page at: ' . $hostedPageUrl);
            $resultRedirect->setUrl($hostedPageUrl);
        } catch (KomojuExceptionBadServer | InvalidJsonException $exception) {
            // Restore the items to the cart and redirect to the checkout payment page again
            // if there's an error communicating with Komoju
            $logMessage = 'Error redirecting to Komoju session: ' . $exception->getMessage();
            $this->logger->info($logMessage);
            $this->processFailedOrder();
            $checkoutUrl = $this->_url->getUrl('checkout', ['_fragment' => 'payment']);
            $resultRedirect->setUrl($checkoutUrl);
        }

        return $resultRedirect;
    }

    /**
     * Calls the KomojuApi to create a session with the relevant payment details
     * and returns the Komoju session's URL
     * @return string the Komoju session URL
     */
    private function createHostedPageUrl()
    {
        $paymentMethod = $this->getRequest()->getParam('payment_method');
        $order = $this->getOrder();
        $externalOrderNum = $this->createExternalPayment($order);
        $currencyCode = $this->storeManager->getStore()->getBaseCurrencyCode();
        $returnUrl = $this->createReturnUrl($order->getEntityId());

        $billing_address = $this->convertToAddressParameter($order->getBillingAddress());
        $shipping_address = $this->convertToAddressParameter($order->getShippingAddress());

        $komojuSession = $this->komojuApi->createSession([
            'return_url' => $returnUrl,
            'default_locale' => $this->config->getKomojuLocale(),
            'payment_types' => [$paymentMethod],
            'payment_data' => [
                'amount' => $order->getGrandTotal(),
                'currency' => $currencyCode,
                'external_order_num' => $externalOrderNum,
                'billing_address' => $billing_address,
                'shipping_address' => $shipping_address,
            ],
        ]);

        return $komojuSession->session_url;
    }

    /**
     * Convert Magento's street data to Sessions's street parameter format.
     * @var string[]|null
     * @return array
     */
    private function convertStreet($street)
    {
        $street1 = '';
        $street2 = '';

        if (is_array($street)) {
            $street1 = $street[0];
            switch (count($street)) {
                case 2:
                    $street2 = $street[1];
                    break;
                case 3:
                    $street2 = trim(join(' ', [$street[1], $street[2]]));
                    break;
            }
        }

        return [$street1, $street2];
    }

    /**
     * Convert Magento's address data to Sessions address parameter format.
     * @var Address|null
     * @return array|null
     */
    private function convertToAddressParameter($address)
    {
        if ($address == null) {
            return null;
        }

        $streets = $this->convertStreet($address->getStreet());

        if ($address->getCompany()) {
            $streets[1] .= ' ' . $address->getCompany();
        }

        $param = [
            'zipcode' => $address->getPostcode(),
            'street_address1' => $streets[0],
            'street_address2' => $streets[1],
            'country' => $this->countryFactory->create()->loadByCode($address->getCountryId())->getName(),
            'city' => $address->getCity(),
        ];

        if ($address->getRegion()) {
            $param['state'] = $address->getRegion();
        }

        return $param;
    }

    /**
     * If a failed order can be cancelled: this restores stock to the product,
     * adds the products back to cart, and cancels the original failed order
     */

    private function processFailedOrder()
    {
        $order = $this->getOrder();
        if ($order->canCancel()) {
            $this->_checkoutSession->restoreQuote();
            $order->registerCancellation();
            $order->save();
        }
    }

    /**
     * gets the last order made from the checkoutSession. The checkoutSession
     * is constructed from the request so this is scoped to the request that
     * was sent to this endpoint, and not the last order made in the entire app.
     * I'm not sure what the difference between the last order and last "real"
     * order is, but this seems to be the commonly accepted way to access this
     * data.
     * @return Order
     */
    private function getOrder()
    {
        if (!$this->order) {
            $this->order = $this->_checkoutSession->getLastRealOrder();
        }
        return $this->order;
    }

    /**
     * Creates the return url for the PostSessionRedirect endpoint in this plugin. Because we
     * don't want to leave an order in limbo if the user does not complete the session,
     * we have a specific endpoint that takes the order id and HMAC token and marks
     * the order as cancelled in the system
     * @return String
     */
    private function createReturnUrl($orderId)
    {
        $secretKey = $this->config->getSecretKey();
        $endpoint = 'komoju/hostedpage/postsessionredirect?order_id=' . $orderId;
        $hmac = hash_hmac('sha256', $endpoint, $secretKey);
        $returnUrl = $this->_url->getUrl($endpoint .= '&hmac_magento=' . $hmac);
        return $returnUrl;
    }

    /**
     * Marks the order as pending in the database. Because at this point
     * the customer has submitted the order and it's been saved in the system
     * but have not paid for it the order is being marked as awaiting payment so
     * the Magento admins have a better understanding of where the order is at
     * @return void
     */
    private function markOrderAsPendingPayment()
    {
        $order = $this->getOrder();
        $order->setState(ORDER::STATE_PENDING_PAYMENT);
        $order->setStatus(ORDER::STATE_PENDING_PAYMENT);
        $order->save();
    }

    /**
     * Because the webhook sends events for changes to transactions it's
     * possible (but not supported) to have multiple storefronts connected to the
     * same Komoju account. Because the webhook events use the external_order_num
     * to indicate which order the event relates to there could be naming collisions
     * for multiple systems (A good example of this would be multiple systems that
     * both an incrementing id field. In that case both systems would have a order
     * with ID 1, and there's no way to know which order the event relates to). To
     * deal with this possibility we're constructing an id unique to each instance
     * of the plugin and tying it to the order. When a webhook event is send this
     * unique id is then mapped back to the relevant order
     * @var Order $order
     * @return String
     */
    private function createExternalPayment(Order $order): string
    {
        return $this->externalPayment->createExternalPayment($order)->getExternalPaymentId();
    }
}
