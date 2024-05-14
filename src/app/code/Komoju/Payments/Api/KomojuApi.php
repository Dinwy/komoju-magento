<?php

/** Replace functionality with KomojuApi package once available **/

namespace Komoju\Payments\Api;

use Komoju\Payments\Exception\KomojuExceptionBadServer;
use Komoju\Payments\Exception\InvalidJsonException;
use Komoju\Payments\Gateway\Config\Config;
use Magento\Framework\HTTP\Client\Curl;
use Exception;

class KomojuApi
{
    private Config $config;
    private Curl $curl;
    private string $endpoint;

    /**
     * KomojuApi constructor.
     *
     * @param Config $config
     * @param Curl $curl
     */
    public function __construct(Config $config, Curl $curl)
    {
        $this->endpoint = 'https://komoju.com';
        $this->config = $config;
        $this->curl = $curl;
    }

    public function paymentMethods()
    {
        return $this->get('/api/v1/payment_methods');
    }

    public function createSession($payload)
    {
        return $this->post('/api/v1/sessions', $payload);
    }

    public function session($sessionUuid)
    {
        return $this->get('/api/v1/sessions/' . $sessionUuid);
    }

    private function get($uri)
    {
        $url = $this->endpoint . $uri;
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->config->getSecretKey() . ':'
        ];
        $this->curl->setOptions($options);

        try {
            $this->curl->get($url);
        } catch (Exception $e) {
            throw new KomojuExceptionBadServer($e->getMessage());
        }

        $body = $this->curl->getBody();
        $http_code = $this->curl->getStatus();

        if ($http_code !== 200) {
            $komojuException = new KomojuExceptionBadServer($body);
            $komojuException->httpCode = $http_code;
            throw $komojuException;
        }

        $decoded = json_decode($body);

        if (!empty(json_last_error())) {
            $errorMsg = (__("KOMOJU Payments JSON Decoding Failure. Error: %1", json_last_error_msg()));
            throw new InvalidJsonException($errorMsg);
        }

        return $decoded;
    }

    // e.g. $payload = array(
    //     'foo' => 'bar'
    // );
    private function post(string $uri, array $payload)
    {
        $url = $this->endpoint . $uri;
        $data_json = json_encode($payload);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Komoju-Via: magento'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->config->getSecretKey() . ':'
        ];
        $this->curl->setOptions($options);

        try {
            $this->curl->post($url, $data_json);
        } catch (Exception $e) {
            throw new KomojuExceptionBadServer($e->getMessage());
        }

        $body = $this->curl->getBody();
        $http_code = $this->curl->getStatus();

        if ($http_code !== 200) {
            $komojuException = new KomojuExceptionBadServer($body);
            $komojuException->httpCode = $http_code;
            throw $komojuException;
        }

        $decoded = json_decode($body);

        if (!empty(json_last_error())) {
            $errorMsg = (__("KOMOJU Payments JSON Decoding Failure. Error: %1", json_last_error_msg()));
            throw new InvalidJsonException($errorMsg);
        }

        return $decoded;
    }
}
