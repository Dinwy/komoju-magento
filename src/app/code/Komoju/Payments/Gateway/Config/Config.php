<?php

namespace Komoju\Payments\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Locale\Resolver as LocaleResolver;
use Magento\Payment\Gateway\Config\Config as PaymentConfig;

/**
 * This class provides a programmatic interface between the rest of the module
 * and the options set for the module in the admin panel.
 */
class Config extends PaymentConfig
{
    private UrlInterface $urlInterface;
    private LocaleResolver $locale;

    /**
     * Komoju config constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param null|string $methodCode The method code is injected in frontend/di.xml
     * @param string $pathPattern
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlInterface,
        Resolver $locale,
        ?String $methodCode = null,
        string $pathPattern = self::DEFAULT_PATH_PATTERN
    ) {
        $this->urlInterface = $urlInterface;
        $this->locale = $locale;
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    /**
     * Returns the value for the "Enable this solution" option on the admin page.
     * NOTE: Just because this is selected doesn't mean that the payment method will
     * appear, if the store currency isn't by Komoju then it isn't displayed on the
     * checkout page.
     * @param int|null $storeId
     * @return bool
     */
    public function isActive($storeId = null): bool
    {
        return (bool) $this->getValue('active', $storeId);
    }

    /**
     * Returns the value of the Title field on the admin page.
     * @param int|null $storeId
     * @return string
     */
    public function getTitle($storeId = null): string
    {
        return $this->getValue('title', $storeId);
    }

    /**
     * Returns the value of the "Komoju merchant ID" on the field on the admin
     * page.
     * @param int|null $storeId
     * @return string
     */
    public function getMerchantId($storeId = null)
    {
        return $this->getValue('merchant_id', $storeId);
    }

    /**
     * Returns the value of the "Secret Key from Komoju" on the field on the admin
     * page.
     * @param int|null $storeId
     * @return string
     */
    public function getSecretKey($storeId = null)
    {
        return $this->getValue('secret_key', $storeId);
    }

    /**
     * Returns the value of the "Webhook Secret Token" on the field on the admin
     * page.
     * @param int|null $storeId
     * @return string
     */
    public function getWebhookSecretToken($storeId = null)
    {
        return $this->getValue('webhook_secret_token', $storeId);
    }

    /**
     * Returns the URL for the redirect controller, where the hosted page API is
     * built and sent to the browser to redirect the user for payment. This isn't
     * related to any fields on the admin page, but rather a internal plugin
     * constant.
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->urlInterface->getUrl('komoju/hostedpage/redirect');
    }

    /**
     * Returns the value of "Show Title on Checkout Screen" on the field on the
     * admin page
     * @return boolean
     */
    public function showTitle($storeId = null)
    {
        return (bool) $this->getValue('show_title', $storeId);
    }

    /**
     * Gets the store locale and compares it to acceptable komoju locales
     * @return string The store locale or default locale
     */
    public function getKomojuLocale()
    {
        $defaultLocale = 'en';
        $komojuLocales = ['en','ja'];
        $storeLocale = substr($this->locale->getLocale(), 0, 2);
        return in_array($storeLocale, $komojuLocales) ? $storeLocale : $defaultLocale;
    }
}
