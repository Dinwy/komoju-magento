<?php

namespace Komoju\Payments\Plugin;

use Magento\Sales\Model\Order;
use Komoju\Payments\Model\WebhookEvent;
use Komoju\Payments\Exception\UnknownEventException;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Komoju\Payments\Model\RefundFactory;
use Psr\Log\LoggerInterface;

class WebhookEventProcessor
{
    private WebhookEvent $webhookEvent;
    private Order $order;
    private CreditmemoFactory $creditmemoFactory;
    private CreditmemoService $creditmemoService;
    private RefundFactory $komojuRefundFactory;
    private LoggerInterface $logger;

    /**
     * Class constructor.
     *
     * @param CreditmemoFactory $creditmemoFactory
     * @param CreditmemoService $creditmemoService
     * @param RefundFactory $komojuRefundFactory
     * @param LoggerInterface $logger
     * @param WebhookEvent $webhookEvent
     * @param Order $order
     */
    public function __construct(
        CreditmemoFactory $creditmemoFactory,
        CreditmemoService $creditmemoService,
        RefundFactory $komojuRefundFactory,
        LoggerInterface $logger,
        WebhookEvent $webhookEvent,
        Order $order
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
     *
     * @throws UnknownEventException
     */
    public function processEvent(): void
    {
        $eventType = $this->webhookEvent->eventType();
        switch ($eventType) {
            case 'payment.captured':
                $this->processPaymentCaptured();
                break;
            case 'payment.authorized':
                $this->processPaymentAuthorized();
                break;
            case 'payment.expired':
                $this->processPaymentExpired();
                break;
            case 'payment.cancelled':
                $this->processPaymentCancelled();
                break;
            case 'payment.refunded':
                $this->processPaymentRefunded();
                break;
            case 'payment.refund.created':
                $this->processPaymentRefundCreated();
                break;
            default:
                throw new UnknownEventException(__('Unknown event type: %1', $eventType));
        }
    }

    private function processPaymentCaptured(): void
    {
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
    }

    private function processPaymentAuthorized(): void
    {
        $statusHistoryComment = $this->prependExternalOrderNum(
            __(
                'Received payment authorization for type: %1. Payment deadline is: %2',
                $this->webhookEvent->paymentType(),
                $this->webhookEvent->paymentDeadline()
            )
        );
        $this->order->addStatusHistoryComment($statusHistoryComment);
        $this->order->save();
    }

    private function processPaymentExpired(): void
    {
        $this->order->setState(Order::STATE_CANCELED);
        $this->order->setStatus(Order::STATE_CANCELED);

        $statusHistoryComment = $this->prependExternalOrderNum(__('Payment was not received before expiry time'));
        $this->order->addStatusHistoryComment($statusHistoryComment);

        $this->order->save();
    }

    private function processPaymentCancelled(): void
    {
        $this->order->cancel();

        $statusHistoryComment = $this->prependExternalOrderNum(__('Received cancellation notice from KOMOJU'));
        $this->order->addStatusHistoryComment($statusHistoryComment);

        $this->order->save();
    }

    private function processPaymentRefunded(): void
    {
        $statusHistoryComment = $this->prependExternalOrderNum(__('Order has been fully refunded.'));
        $this->order->setState(Order::STATE_COMPLETE);
        $this->order->setStatus(Order::STATE_COMPLETE);
        $this->order->addStatusHistoryComment($statusHistoryComment);

        $this->order->save();
    }

    private function processPaymentRefundCreated(): void
    {
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
                $refundsToProcess[] = $refund;
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

        $this->order->save();
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
