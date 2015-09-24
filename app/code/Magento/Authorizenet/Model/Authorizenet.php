<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Authorizenet\Model;

use Magento\Payment\Model\Method\Logger;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class Authorizenet extends \Magento\Payment\Model\Method\Cc
{
    /**
     * AIM gateway url
     */
    const CGI_URL = 'https://secure.authorize.net/gateway/transact.dll';

    /**
     * Transaction Details gateway url
     */
    const CGI_URL_TD = 'https://apitest.authorize.net/xml/v1/request.api';

    const REQUEST_METHOD_CC = 'CC';

    const REQUEST_TYPE_AUTH_CAPTURE = 'AUTH_CAPTURE';

    const REQUEST_TYPE_AUTH_ONLY = 'AUTH_ONLY';

    const REQUEST_TYPE_CAPTURE_ONLY = 'CAPTURE_ONLY';

    const REQUEST_TYPE_CREDIT = 'CREDIT';

    const REQUEST_TYPE_VOID = 'VOID';

    const REQUEST_TYPE_PRIOR_AUTH_CAPTURE = 'PRIOR_AUTH_CAPTURE';

    const RESPONSE_DELIM_CHAR = '(~)';

    const RESPONSE_CODE_APPROVED = 1;

    const RESPONSE_CODE_DECLINED = 2;

    const RESPONSE_CODE_ERROR = 3;

    const RESPONSE_CODE_HELD = 4;

    const RESPONSE_REASON_CODE_APPROVED = 1;

    const RESPONSE_REASON_CODE_PENDING_REVIEW_AUTHORIZED = 252;

    const RESPONSE_REASON_CODE_PENDING_REVIEW = 253;

    const RESPONSE_REASON_CODE_PENDING_REVIEW_DECLINED = 254;

    const PAYMENT_UPDATE_STATUS_CODE_SUCCESS = 'Ok';

    /**
     * Transaction fraud state key
     */
    const TRANSACTION_FRAUD_STATE_KEY = 'is_transaction_fraud';

    /**
     * Real transaction id key
     */
    const REAL_TRANSACTION_ID_KEY = 'real_transaction_id';

    /**
     * Gateway actions locked state key
     */
    const GATEWAY_ACTIONS_LOCKED_STATE_KEY = 'is_gateway_actions_locked';

    /**
     * @var \Magento\Authorizenet\Helper\Data
     */
    protected $dataHelper;

    /**
     * Request factory
     *
     * @var \Magento\Authorizenet\Model\RequestFactory
     */
    protected $requestFactory;

    /**
     * Response factory
     *
     * @var \Magento\Authorizenet\Model\ResponseFactory
     */
    protected $responseFactory;

    /**
     * Stored information about transaction
     *
     * @var array
     */
    protected $transactionDetails = [];

    /**
     * {@inheritdoc}
     */
    protected $_debugReplacePrivateDataKeys = ['merchantAuthentication', 'x_login'];

    /**
     * @var \Magento\Framework\Xml\Security
     */
    protected $security;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param \Magento\Authorizenet\Helper\Data $dataHelper
     * @param \Magento\Authorizenet\Model\Request\Factory $requestFactory
     * @param \Magento\Authorizenet\Model\Response\Factory $responseFactory
     * @param \Magento\Framework\Xml\Security $security
     * @param \Magento\Framework\Model\Resource\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Authorizenet\Helper\Data $dataHelper,
        \Magento\Authorizenet\Model\Request\Factory $requestFactory,
        \Magento\Authorizenet\Model\Response\Factory $responseFactory,
        \Magento\Framework\Xml\Security $security,
        \Magento\Framework\Model\Resource\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->dataHelper = $dataHelper;
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
        $this->security = $security;

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $moduleList,
            $localeDate,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Check method for processing with base currency
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->getAcceptedCurrencyCodes())) {
            return false;
        }
        return true;
    }

    /**
     * Return array of currency codes supplied by Payment Gateway
     *
     * @return array
     */
    public function getAcceptedCurrencyCodes()
    {
        if (!$this->hasData('_accepted_currency')) {
            $acceptedCurrencyCodes = $this->dataHelper->getAllowedCurrencyCodes();
            $acceptedCurrencyCodes[] = $this->getConfigData('currency');
            $this->setData('_accepted_currency', $acceptedCurrencyCodes);
        }
        return $this->_getData('_accepted_currency');
    }

    /**
     * Cancel the payment through gateway
     *
     * @param  \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     */
    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        return $this->void($payment);
    }

    /**
     * Fetch fraud details
     *
     * @param string $transactionId
     * @return \Magento\Framework\DataObject
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function fetchTransactionFraudDetails($transactionId)
    {
        $responseXmlDocument = $this->getTransactionDetails($transactionId);
        $response = new \Magento\Framework\DataObject();

        if (empty($responseXmlDocument->transaction->FDSFilters->FDSFilter)) {
            return $response;
        }

        $response->setFdsFilterAction(
            $this->dataHelper->getFdsFilterActionLabel((string)$responseXmlDocument->transaction->FDSFilterAction)
        );
        $response->setAvsResponse((string)$responseXmlDocument->transaction->AVSResponse);
        $response->setCardCodeResponse((string)$responseXmlDocument->transaction->cardCodeResponse);
        $response->setCavvResponse((string)$responseXmlDocument->transaction->CAVVResponse);
        $response->setFraudFilters($this->getFraudFilters($responseXmlDocument->transaction->FDSFilters));

        return $response;
    }

    /**
     * Get fraud filters
     *
     * @param \Magento\Framework\Simplexml\Element $fraudFilters
     * @return array
     */
    protected function getFraudFilters($fraudFilters)
    {
        $result = [];

        foreach ($fraudFilters->FDSFilter as $filer) {
            $result[] = [
                'name' => (string)$filer->name,
                'action' => $this->dataHelper->getFdsFilterActionLabel((string)$filer->action)
            ];
        }

        return $result;
    }

    /**
     * Return authorize payment request
     *
     * @return \Magento\Authorizenet\Model\Request
     */
    protected function getRequest()
    {
        $request = $this->requestFactory->create()
            ->setXVersion(3.1)
            ->setXDelimData('True')
            ->setXRelayResponse('False')
            ->setXTestRequest($this->getConfigData('test') ? 'TRUE' : 'FALSE')
            ->setXLogin($this->getConfigData('login'))
            ->setXTranKey($this->getConfigData('trans_key'));
        return $request;
    }

    /**
     * Prepare request to gateway
     *
     * @param \Magento\Framework\DataObject|\Magento\Payment\Model\InfoInterface $payment
     * @return \Magento\Authorizenet\Model\Request
     * @link http://www.authorize.net/support/AIM_guide.pdf
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function buildRequest(\Magento\Framework\DataObject $payment)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();
        $this->setStore($order->getStoreId());
        $request = $this->getRequest()
            ->setXType($payment->getAnetTransType())
            ->setXMethod(self::REQUEST_METHOD_CC);

        if ($order && $order->getIncrementId()) {
            $request->setXInvoiceNum($order->getIncrementId());
        }

        if ($payment->getAmount()) {
            $request->setXAmount($payment->getAmount(), 2);
            $request->setXCurrencyCode($order->getBaseCurrencyCode());
        }

        switch ($payment->getAnetTransType()) {
            case self::REQUEST_TYPE_AUTH_CAPTURE:
                $request->setXAllowPartialAuth($this->getConfigData('allow_partial_authorization') ? 'True' : 'False');
                break;
            case self::REQUEST_TYPE_AUTH_ONLY:
                $request->setXAllowPartialAuth($this->getConfigData('allow_partial_authorization') ? 'True' : 'False');
                break;
            case self::REQUEST_TYPE_CREDIT:
                /**
                 * Send last 4 digits of credit card number to authorize.net
                 * otherwise it will give an error
                 */
                $request->setXCardNum($payment->getCcLast4());
                $request->setXTransId($payment->getXTransId());
                break;
            case self::REQUEST_TYPE_VOID:
                $request->setXTransId($payment->getXTransId());
                break;
            case self::REQUEST_TYPE_PRIOR_AUTH_CAPTURE:
                $request->setXTransId($payment->getXTransId());
                break;
            case self::REQUEST_TYPE_CAPTURE_ONLY:
                $request->setXAuthCode($payment->getCcAuthCode());
                break;
        }

        if (!empty($order)) {
            $billing = $order->getBillingAddress();
            if (!empty($billing)) {
                $request->setXFirstName($billing->getFirstname())
                    ->setXLastName($billing->getLastname())
                    ->setXCompany($billing->getCompany())
                    ->setXAddress($billing->getStreetLine(1))
                    ->setXCity($billing->getCity())
                    ->setXState($billing->getRegion())
                    ->setXZip($billing->getPostcode())
                    ->setXCountry($billing->getCountry())
                    ->setXPhone($billing->getTelephone())
                    ->setXFax($billing->getFax())
                    ->setXCustId($order->getCustomerId())
                    ->setXCustomerIp($order->getRemoteIp())
                    ->setXCustomerTaxId($billing->getTaxId())
                    ->setXEmail($order->getCustomerEmail())
                    ->setXEmailCustomer($this->getConfigData('email_customer'))
                    ->setXMerchantEmail($this->getConfigData('merchant_email'));
            }

            $shipping = $order->getShippingAddress();
            if (!empty($shipping)) {
                $request->setXShipToFirstName($shipping->getFirstname())
                    ->setXShipToLastName($shipping->getLastname())
                    ->setXShipToCompany($shipping->getCompany())
                    ->setXShipToAddress($shipping->getStreetLine(1))
                    ->setXShipToCity($shipping->getCity())
                    ->setXShipToState($shipping->getRegion())
                    ->setXShipToZip($shipping->getPostcode())
                    ->setXShipToCountry($shipping->getCountry());
            }

            $request->setXPoNum($payment->getPoNumber())
                ->setXTax($order->getBaseTaxAmount())
                ->setXFreight($order->getBaseShippingAmount());
        }

        if ($payment->getCcNumber()) {
            $request->setXCardNum($payment->getCcNumber())
                ->setXExpDate(sprintf('%02d-%04d', $payment->getCcExpMonth(), $payment->getCcExpYear()))
                ->setXCardCode($payment->getCcCid());
        }

        return $request;
    }

    /**
     * Post request to gateway and return response
     *
     * @param \Magento\Authorizenet\Model\Request $request
     * @return \Magento\Authorizenet\Model\Response
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function postRequest(\Magento\Authorizenet\Model\Request $request)
    {
        $result = $this->responseFactory->create();
        $client = new \Magento\Framework\HTTP\ZendClient();
        $url = $this->getConfigData('cgi_url') ?: self::CGI_URL;
        $debugData = ['url' => $url, 'request' => $request->getData()];
        $client->setUri($url);
        $client->setConfig(['maxredirects' => 0, 'timeout' => 30]);

        foreach ($request->getData() as $key => $value) {
            $request->setData($key, str_replace(self::RESPONSE_DELIM_CHAR, '', $value));
        }

        $request->setXDelimChar(self::RESPONSE_DELIM_CHAR);
        $client->setParameterPost($request->getData());
        $client->setMethod(\Zend_Http_Client::POST);

        try {
            $response = $client->request();
            $responseBody = $response->getBody();
            $debugData['response'] = $responseBody;
        } catch (\Exception $e) {
            $result->setXResponseCode(-1)
                ->setXResponseReasonCode($e->getCode())
                ->setXResponseReasonText($e->getMessage());

            throw new \Magento\Framework\Exception\LocalizedException(
                $this->dataHelper->wrapGatewayError($e->getMessage())
            );
        } finally {
            $this->_debug($debugData);
        }

        $r = explode(self::RESPONSE_DELIM_CHAR, $responseBody);
        if ($r) {
            $result->setXResponseCode((int)str_replace('"', '', $r[0]))
                ->setXResponseReasonCode((int)str_replace('"', '', $r[2]))
                ->setXResponseReasonText($r[3])
                ->setXAvsCode($r[5])
                ->setXTransId($r[6])
                ->setXInvoiceNum($r[7])
                ->setXAmount($r[9])
                ->setXMethod($r[10])
                ->setXType($r[11])
                ->setData('x_MD5_Hash', $r[37])
                ->setXAccountNumber($r[50]);
        } else {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Something went wrong in the payment gateway.')
            );
        }
        return $result;
    }

    /**
     * If gateway actions are locked return true
     *
     * @param  \Magento\Payment\Model\InfoInterface $payment
     * @return bool
     */
    protected function isGatewayActionsLocked($payment)
    {
        return $payment->getAdditionalInformation(self::GATEWAY_ACTIONS_LOCKED_STATE_KEY);
    }

    /**
     * This function returns full transaction details for a specified transaction ID.
     *
     * @param string $transactionId
     * @return \Magento\Framework\DataObject
     * @throws \Magento\Framework\Exception\LocalizedException
     * @link http://www.authorize.net/support/ReportingGuide_XML.pdf
     * @link http://developer.authorize.net/api/transaction_details/
     */
    protected function getTransactionResponse($transactionId)
    {
        $responseXmlDocument = $this->getTransactionDetails($transactionId);

        $response = new \Magento\Framework\DataObject();
        $response->setXResponseCode((string)$responseXmlDocument->transaction->responseCode)
            ->setXResponseReasonCode((string)$responseXmlDocument->transaction->responseReasonCode)
            ->setTransactionStatus((string)$responseXmlDocument->transaction->transactionStatus);

        return $response;
    }

    /**
     * Load transaction details
     *
     * @param string $transactionId
     * @return \Magento\Framework\Simplexml\Element
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function loadTransactionDetails($transactionId)
    {
        $requestBody = sprintf(
            '<?xml version="1.0" encoding="utf-8"?>' .
            '<getTransactionDetailsRequest xmlns="AnetApi/xml/v1/schema/AnetApiSchema.xsd">' .
            '<merchantAuthentication><name>%s</name><transactionKey>%s</transactionKey></merchantAuthentication>' .
            '<transId>%s</transId>' .
            '</getTransactionDetailsRequest>',
            $this->getConfigData('login'),
            $this->getConfigData('trans_key'),
            $transactionId
        );

        $client = new \Magento\Framework\HTTP\ZendClient();
        $url = $this->getConfigData('cgi_url_td') ?: self::CGI_URL_TD;
        $client->setUri($url);
        $client->setConfig(['timeout' => 45]);
        $client->setHeaders(['Content-Type: text/xml']);
        $client->setMethod(\Zend_Http_Client::POST);
        $client->setRawData($requestBody);

        $debugData = ['url' => $url, 'request' => $this->removePrivateDataFromXml($requestBody)];

        try {
            $responseBody = $client->request()->getBody();
            if (!$this->security->scan($responseBody)) {
                $this->_logger->critical('Attempt loading of external XML entities in response from Authorizenet.');
                throw new \Exception();
            }
            $debugData['response'] = $responseBody;
            libxml_use_internal_errors(true);
            $responseXmlDocument = new \Magento\Framework\Simplexml\Element($responseBody);
            libxml_use_internal_errors(false);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Unable to get transaction details. Try again later.')
            );
        } finally {
            $this->_debug($debugData);
        }

        if (!isset($responseXmlDocument->messages->resultCode)
            || $responseXmlDocument->messages->resultCode != static::PAYMENT_UPDATE_STATUS_CODE_SUCCESS
        ) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Unable to get transaction details. Try again later.')
            );
        }

        $this->transactionDetails[$transactionId] = $responseXmlDocument;
        return $responseXmlDocument;
    }

    /**
     * Get transaction information
     *
     * @param string $transactionId
     * @return \Magento\Framework\Simplexml\Element
     */
    protected function getTransactionDetails($transactionId)
    {
        return isset($this->transactionDetails[$transactionId])
            ? $this->transactionDetails[$transactionId]
            : $this->loadTransactionDetails($transactionId);
    }

    /**
     * Remove nodes with private data from XML string
     *
     * Uses values from $_debugReplacePrivateDataKeys property
     *
     * @param string $xml
     * @return string
     */
    protected function removePrivateDataFromXml($xml)
    {
        foreach ($this->getDebugReplacePrivateDataKeys() as $key) {
            $xml = preg_replace(sprintf('~(?<=<%s>).*?(?=</%s>)~', $key, $key), Logger::DEBUG_KEYS_MASK, $xml);
        }
        return $xml;
    }
}
