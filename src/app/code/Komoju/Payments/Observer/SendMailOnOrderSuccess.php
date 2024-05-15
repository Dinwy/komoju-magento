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
        LoggerInterface $logger = null,
    ) {
        $this->orderModel = $orderModel;
        $this->orderSender = $orderSender;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger ?: ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
    }

    /**
     * Execute the observer on the 'checkout_onepage_controller_success_action' event
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $this->logger->info('SendMailOnOrderSuccess observer triggered');

        $order = $observer->getEvent()->getData('order');
        if (!$order) return;
        $this->logger->info('Sending order email for order ID: ' . $order->getIncrementId());
        $this->orderSender->send($order, true);
    }
}
