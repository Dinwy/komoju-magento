<?php
namespace Komoju\Payments\Model;

/**
 * The ExternalPayment model is the programmatic access for the external_payment
 * data. The ExternalPayment data serves as a connection between the unique ID
 * passed to Komoju as the external_order_num and the core sales order in Magento.
 *
 * Because it's possible multiple systems can use the same Komoju account just
 * using the order id as the external_order_num could cause some conflicts, since
 * it's just a incrementing number (which is a very common pattern for databases).
 * Since we don't have a way for a system to demarcate itself to Komoju, if one system
 * generated webhooks for an order, the other system would not know if the order is
 * for itself, which could cause orders to be set to the incorrect state. To deal with
 * this the ExternalPayment model was created, which would allow for a unique ID to
 * be created, and be linked to the relevant order in Magento.
 */
class ExternalPayment extends \Magento\Framework\Model\AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Komoju\Payments\Model\ResourceModel\ExternalPayment::class);
    }

    /**
     * Creates an external payment object. The external payment id is created using
     * PHP's uniqid value, combined with the order id. This creates a very low chance
     * for a collision with any other system that may be using the same Komoju account.
     * @param Magento\Sales\Model\Order $order
     * @return Komoju\Payments\Model\ExternalPayment
     */
    public function createExternalPayment($order)
    {
        $orderId = $order->getEntityId();
        $externalPaymentId = uniqid($orderId . '-');

        $data = ['sales_order_id' => $orderId, 'external_payment_id' => $externalPaymentId];
        $this->setData($data);
        $this->save();

        return $this;
    }
}
