<?php
namespace Komoju\Payments\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderManagementInterface;

use Psr\Log\LoggerInterface;

class OrderCancelObserver implements ObserverInterface
{
    protected $orderRepository;
    protected $quoteManagement;
    private $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        QuoteManagement $quoteManagement,
        CartRepositoryInterface $quoteRepository,
        OrderManagementInterface $orderManagement,
        LoggerInterface $logger = null,
    ) {
        $this->orderRepository = $orderRepository;
        $this->quoteManagement = $quoteManagement;
        $this->quoteRepository = $quoteRepository;
        $this->orderManagement = $orderManagement;
        $this->logger = $logger ?: ObjectManager::getInstance()->get(LoggerInterface::class);
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();

        $this->logger->info('OrderCancelObserver: Order ID: ' . $order->getIncrementId());

        if ($order->getState() == Order::STATE_CANCELED) {
            $quote = $this->quoteManagement->getQuote($order->getQuoteId());
            $quote->setReservedOrderId($order->getIncrementId());
            $quote->save();
            $newOrder = $this->quoteManagement->submit($quote);
            $this->orderRepository->save($newOrder);
        }
    }
}
