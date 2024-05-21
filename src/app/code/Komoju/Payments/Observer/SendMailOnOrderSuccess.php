<?php

namespace Komoju\Payments\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Checkout\Model\Session;
use Psr\Log\LoggerInterface;

class SendMailOnOrderSuccess implements ObserverInterface
{
    protected OrderFactory $orderModel;
    protected OrderSender $orderSender;
    protected Session $checkoutSession;
    private LoggerInterface $logger;

    public function __construct(
        OrderFactory $orderModel,
        OrderSender $orderSender,
        Session $checkoutSession,
        LoggerInterface $logger = null
    ) {
        $this->orderModel = $orderModel;
        $this->orderSender = $orderSender;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger ?: ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
    }

    /**
     * Execute the observer on the 'send_mail_on_order_success' event
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $this->logger->info('SendMailOnOrderSuccess observer triggered');

        $order = $observer->getEvent()->getOrder();
        if (!$order)
            return;
        $this->logger->info('Sending order email for order ID: ' . $order->getIncrementId());
        $this->orderSender->send($order, true);
        $this->sendEmail();
    }

    public function sendEmail()
    {
        // this is an example and you can change template id,fromEmail,toEmail,etc as per your need.
        $templateId = 'sales_email_order_template';
        $fromEmail = 'dinwy@outlook.com';  // sender Email id
        $fromName = 'Admin';             // sender Name
        $toEmail = 'whix2010@gmail.com'; // receiver email id

        try {
            // template variables pass here
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
            $this->_logger->info($e->getMessage());
        }
    }
}

// <?php

// namespace Komoju\Payments\Observer;

// use Magento\Framework\App\ObjectManager;
// use Magento\Framework\Event\ObserverInterface;
// use Magento\Framework\Event\Observer;
// use Magento\Sales\Model\OrderFactory;
// use Magento\Sales\Model\Order\Email\Sender\OrderSender;
// use Magento\Checkout\Model\Session;
// use Magento\Store\Model\StoreManagerInterface;
// use Magento\Framework\Mail\Template\TransportBuilder;

// use Psr\Log\LoggerInterface;

// class SendMailOnOrderSuccess implements ObserverInterface
// {
//     protected OrderFactory $orderModel;
//     protected OrderSender $orderSender;
//     protected Session $checkoutSession;
//     private LoggerInterface $logger;
//     private StoreManagerInterface $storeManager;
//     private TransportBuilder $transportBuilder;

//     public function __construct(
//         OrderFactory $orderModel,
//         OrderSender $orderSender,
//         Session $checkoutSession,
//         StoreManagerInterface $storeManager,
//         TransportBuilder $transportBuilder,
//         LoggerInterface $logger = null,
// ) {
//     $this->orderModel = $orderModel;
//     $this->orderSender = $orderSender;
//     $this->checkoutSession = $checkoutSession;
//     $this->storeManager = $storeManager;
//     $this->transportBuilder = $transportBuilder;
//     $this->logger = $logger ?: ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
// }

// /**
//  * Execute the observer on the 'checkout_onepage_controller_success_action' event
//  *
//  * @param Observer $observer
//  * @return void
//  */
// public function execute(Observer $observer)
// {
//     $this->logger->info('SendMailOnOrderSuccess observer triggered');

//     $order = $observer->getEvent()->getData('order');
//     if (!$order) return;
//     $this->logger->info('Sending order email for order ID: ' . $order->getIncrementId());
//     $this->sendEmail();
// }
// }
