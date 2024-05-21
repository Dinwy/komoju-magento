<?php

namespace Komoju\Payments\Plugin;

use Magento\Sales\Model\Order;
use Komoju\Payments\Model\WebhookEvent;
use Komoju\Payments\Exception\UnknownEventException;

class WebhookEventProcessor
{

    /**
     * @var
     */
    private $webhookEvent;

    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @var \Magento\Sales\Model\Order\CreditmemoFactory
     */
    private $creditmemoFactory;

    /**
     * @var \Magento\Sales\Model\Service\CreditmemoService
     */
    private $creditmemoService;

    /**
     * @var \Komoju\Payments\Model\RefundFactory $komojuRefundFactory
     */
    private $komojuRefundFactory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Class constructor
     * @param \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory
     * @param \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
     * @param \Komoju\Payments\Model\RefundFactory $komojuRefundFactory
     * @param \Psr\Log\LoggerInterface $logger,
     * @param \Komoju\Payments\Model\WebhookEvent $webhookEvent,
     * @param \Magento\Sales\Model\Order $order
     */
    public function __construct(
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Komoju\Payments\Model\RefundFactory $komojuRefundFactory,
        \Psr\Log\LoggerInterface $logger,
        \Komoju\Payments\Model\WebhookEvent $webhookEvent,
        \Magento\Sales\Model\Order $order
    ) {
            $this->creditmemoFactory = $creditmemoFactory;
            $this->creditmemoService = $creditmemoService;
            $this->komojuRefundFactory = $komojuRefundFactory;
            $this->logger = $logger;
            $this->webhookEvent = $webhookEvent;
            $this->order = $order;
    }

    /**
     * Takes the event and the order and updates the system according to how the
     * payment has progressed with Komoju
     * @param Komoju\Payments\Model\WebhookEvent $webhookEvent
     * @param Magento\Sales\Model\Order $order
     */
    public function processEvent()
    {
        if ($this->webhookEvent->eventType() == 'payment.captured') {
            $paymentAmount = $this->webhookEvent->amount();
            $currentTotalPaid = $this->order->getTotalPaid();
            $this->order->setTotalPaid($paymentAmount + $currentTotalPaid);
            $this->order->setState(Order::STATE_PROCESSING);
            $this->order->setStatus(Order::STATE_PROCESSING);

            $statusHistoryComment = $this->prependExternalOrderNum(
                __(
                    'Payment successfully received in the amount of: %1 %2',
                    $paymentAmount,
                    $this->webhookEvent->currency()
                )
            );
            $this->order->addStatusHistoryComment($statusHistoryComment);

            $this->order->save();
        } elseif ($this->webhookEvent->eventType() == 'payment.authorized') {
            $statusHistoryComment = $this->prependExternalOrderNum(
                __(
                    'Received payment authorization for type: %1. Payment deadline is: %2',
                    $this->webhookEvent->paymentType(),
                    $this->webhookEvent->paymentDeadline()
                )
            );
            $this->order->addStatusHistoryComment($statusHistoryComment);

            $this->order->save();
        } elseif ($this->webhookEvent->eventType() == 'payment.expired') {
            $this->order->setState(Order::STATE_CANCELED);
            $this->order->setStatus(Order::STATE_CANCELED);

            $statusHistoryComment = $this->prependExternalOrderNum(__('Payment was not received before expiry time'));
            $this->order->addStatusHistoryComment($statusHistoryComment);

            $this->order->save();
        } elseif ($this->webhookEvent->eventType() == 'payment.cancelled') {
            $this->order->cancel();

            $statusHistoryComment = $this->prependExternalOrderNum(__('Received cancellation notice from KOMOJU'));
            $this->order->addStatusHistoryComment($statusHistoryComment);
            $this->order->save();
        } elseif ($this->webhookEvent->eventType() == 'payment.refunded') {
            $refundedAmount = $this->webhookEvent->amountRefunded();
            $refundCurrency = $this->webhookEvent->currency();

            $statusHistoryComment = $this->prependExternalOrderNum(__('Order has been fully refunded.'));

            $this->order->setState(Order::STATE_COMPLETE);
            $this->order->setStatus(Order::STATE_COMPLETE);
            $this->order->addStatusHistoryComment($statusHistoryComment);
            $this->order->save();
        } elseif ($this->webhookEvent->eventType() == 'payment.refund.created') {
            $grandTotal = $this->order->getBaseGrandTotal();
            $totalAmountRefunded = $this->webhookEvent->amountRefunded();
            $refundCurrency = $this->webhookEvent->currency();

            $this->order->setTotalRefunded($totalAmountRefunded);

            $refunds = $this->webhookEvent->getRefunds();
            $refundsToProcess = [];

            foreach ($refunds as $refund) {
                $refundId = $refund['id'];
                $komojuRefundCollection = $this->komojuRefundFactory->create()->getCollection();
                $refundRecord = $komojuRefundCollection->getRecordForRefundId($refundId, $this->logger);

                if (!$refundRecord) {
                    array_push($refundsToProcess, $refund);
                }
            }

            foreach ($refundsToProcess as $refund) {
                $refundId = $refund['id'];
                $refundedAmount = $refund['amount'];
                $statusHistoryComment = $this->prependExternalOrderNum(
                    __('Refund for order created. Amount: %1 %2', $refundedAmount, $refundCurrency)
                );

                $creditmemo = $this->creditmemoFactory->createByOrder($this->order);
                $creditmemo->setSubtotal($refundedAmount);
                $creditmemo->addComment($statusHistoryComment);
                $this->creditmemoService->refund($creditmemo, true);

                $creditmemoId = $creditmemo->getEntityId();
                $komojuRefund = $this->komojuRefundFactory->create();
                $komojuRefund->addData([
                    'refund_id' => $refundId,
                    'sales_creditmemo_id' => $creditmemoId,
                ]);
                $komojuRefund->save();

                $this->order->addStatusHistoryComment($statusHistoryComment);
            }

            // $baseTotalNotRefunded = $invoice->getBaseGrandTotal() - $invoice->getBaseTotalRefunded();
            // $baseToOrderRate = $order->getBaseToOrderRate();
            // $creditmemo->setBaseSubtotal($baseTotalNotRefunded);
            // $creditmemo->setSubtotal($baseTotalNotRefunded * $baseToOrderRate);
            // $creditmemo->setBaseGrandTotal($refundAmount);
            // $creditmemo->setGrandTotal($refundAmount * $baseToOrderRate);

            $this->order->save();
        } else {
            throw new UnknownEventException(__('Unknown event type: %1', $this->webhookEvent->eventType()));
        }
    }

    /**
     * Prepends the external order num to the string passed in. Because the
     * external_order_num passed to Komoju is a unique generated value, by
     * adding it to status history it makes it easier for the Magento admins to
     * search through the Komoju website with a unique value mapped to the order
     * @param string $str
     * @return string
     */
    private function prependExternalOrderNum($str)
    {
        return __('KOMOJU External Order ID: %1 %2', $this->webhookEvent->externalOrderNum(), $str);
    }
}
