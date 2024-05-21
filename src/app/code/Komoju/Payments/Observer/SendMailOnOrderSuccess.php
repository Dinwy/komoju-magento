<?php

namespace Komoju\Payments\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Psr\Log\LoggerInterface;

class SendMailOnOrderSuccess implements ObserverInterface
{
    protected OrderFactory $orderModel;
    protected OrderSender $orderSender;
    protected Session $checkoutSession;
    private LoggerInterface $logger;
    private StoreManagerInterface $storeManager;
    private TransportBuilder $transportBuilder;

    public function __construct(
        OrderFactory $orderModel,
        OrderSender $orderSender,
        Session $checkoutSession,
        LoggerInterface $logger = null
    ) {
        $this->orderModel = $orderModel;
        $this->orderSender = $orderSender;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger ?: ObjectManager::getInstance()->get(LoggerInterface::class);
        $this->storeManager = ObjectManager::getInstance()->get(StoreManagerInterface::class);
        $this->transportBuilder = ObjectManager::getInstance()->get(TransportBuilder::class);
    }

    /**
     * Execute the observer on the 'xx' event
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $this->logger->info('SendMailOnOrderSuccess observer triggered');

        $order = $observer->getEvent()->getOrder();
        if (!$order) return;

        $this->logger->info('### Sending order email for order ID: ' . $order->getIncrementId());
        $this->orderSender->send($order, true);
        // $this->sendTestEmail();
    }

    public function sendTestEmail()
    {
        $templateId = 'sales_email_order_template';
        $fromEmail = 'dinwy@outlook.com';  // sender Email id
        $fromName = 'Admin';             // sender Name
        $toEmail = 'whix2010@gmail.com'; // receiver email id

        try {
            $templateVars = [
                'msg' => 'test',
                'msg1' => 'test1'
            ];

            $storeId = $this->storeManager->getStore()->getId();

            $from = ['email' => $fromEmail, 'name' => $fromName];

            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $templateOptions = [
                'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                'store' => $storeId
            ];
            $transport = $this->transportBuilder->setTemplateIdentifier($templateId, $storeScope)
                ->setTemplateOptions($templateOptions)
                ->setTemplateVars($templateVars)
                ->setFrom($from)
                ->addTo($toEmail)
                ->getTransport();
            $transport->sendMessage();
        } catch (\Exception $e) {
            $this->logger->info($e->getMessage());
        }
    }
}
