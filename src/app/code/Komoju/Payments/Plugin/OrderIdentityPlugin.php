<?php

namespace Komoju\Payments\Plugin;

use Magento\Checkout\Model\Session;
use Magento\Sales\Model\Order\Email\Container\OrderIdentity;

class OrderIdentityPlugin
{
    protected Session $checkoutSession;

    /**
     * Constructor
     *
     * @param Session $checkoutSession
     */
    public function __construct(Session $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Toggle email sending behavior based on session flag
     *
     * @param OrderIdentity $subject
     * @param callable $proceed
     * @return bool
     */
    public function aroundIsEnabled(OrderIdentity $subject, callable $proceed): bool
    {
        $returnValue = $proceed();

        $forceOrderMailSentOnSuccess = $this->checkoutSession->getForceOrderMailSentOnSuccess();
        if (isset($forceOrderMailSentOnSuccess) && $forceOrderMailSentOnSuccess) {
            $returnValue = !$returnValue;
            $this->checkoutSession->unsForceOrderMailSentOnSuccess();
        }

        return $returnValue;
    }
}
