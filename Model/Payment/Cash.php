<?php

declare(strict_types=1);

namespace PagoFacil\Payment\Model\Payment;

use Exception;
use Magento\Customer\Model\Address;
use Magento\Customer\Model\Customer;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\{DataObject, Registry};
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PagoFacil\Payment\Block\Info\Custom;
use PagoFacil\Payment\Exceptions\AmountException;
use PagoFacil\Payment\Exceptions\ClientException;
use PagoFacil\Payment\Exceptions\PaymentException;
use PagoFacil\Payment\Model\Payment\Interfaces\ConfigInterface;
use PagoFacil\Payment\Source\Transaction\Charge;
use Psr\Log\LoggerInterface;
use Magento\Payment\Model\Method\Logger;
use PagoFacil\Payment\Model\Payment\Interfaces\CashInterface;
use PagoFacil\Payment\Source\Client\EndPoint;
use PagoFacil\Payment\Source\Client\PagoFacil as Client;
use PagoFacil\Payment\Source\Register;
use PagoFacil\Payment\Source\User\Client as UserClient;

class Cash extends AbstractMethod implements CashInterface
{
    use ConfigData;
    /** @var EndPoint $endpoint */
    private $endpoint;
    /** @var UserClient $user */
    private $user;
    /** @var Client $client  */
    private $client;

    /**
     * Cash constructor.
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @param DirectoryHelper|null $directory
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = [],
        DirectoryHelper $directory = null
    ) {
        $url = null;
        $this->_isGateway = true;
        $this->_canCapture = true;
        $this->_canAuthorize = true;
        $this->_code = static::CODE;
        $this->_isOffline = true;

        parent::__construct($context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data,
            $directory
        );

        /** @var LoggerInterface $logger */
        $logger = ObjectManager::getInstance()->get(LoggerInterface::class);

        if ($this->getConfigData('is_sandbox')) {
            $url = $this->getConfigDataPagofacil(
                'endpoint_sandbox',
                ConfigInterface::CODECONF
            );
        } else {
            $url = $this->getConfigDataPagofacil(
                'endpoint_production',
                ConfigInterface::CODECONF
            );
        }

        $this->endpoint = new EndPoint(
            $url,
            $this->getConfigData('uri_cash')
        );

        $this->user = new UserClient(
            $this->getConfigDataPagofacil(
                'display_user_id',
                ConfigInterface::CODECONF
            ),
            $this->getConfigDataPagofacil(
                'display_user_branch_office_id',
                ConfigInterface::CODECONF
            ),
            $this->getConfigDataPagofacil(
                'display_user_phase_id',
                ConfigInterface::CODECONF
            ),
            $this->endpoint
        );

        try {
            Register::add('user', $this->user);
        } catch (Exception $exception) {
            $logger->alert($exception->getMessage());
        }

        try {
            Register::add(
                'client',
                new Client($this->user->getEndpoint()->getCompleteUrl())
            );
        } catch (Exception $exception) {
            $logger->alert($exception->getMessage());
        }
    }

    /**
     * @param DataObject $data
     * @return AbstractMethod
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        /** @var LoggerInterface $logger */
        $logger = ObjectManager::getInstance()->get(LoggerInterface::class);
        return parent::assignData($data);
    }

    public function authorize(InfoInterface $payment, $amount)
    {
        /** @var Payment $payment */
        /** @var Order $order */
        /** @var Charge $charge */
        /** @var LoggerInterface $logger */
        /** @var Client $httpClient */

        if ($amount <= 0) {
            throw new AmountException('Invalid amount auth');
        }

        $logger = ObjectManager::getInstance()->get(LoggerInterface::class);

        $order = $payment->getOrder();

        try {
            $order->setStatus(Order::STATE_PENDING_PAYMENT);
            $order->setState(Order::STATE_PENDING_PAYMENT);
        } catch (ClientException $exception) {
            $logger->error($exception->getMessage());
        } catch (PaymentException $exception) {
            $logger->error($exception->getMessage());
        }
        $payment->setTransactionId(64654);
        $payment->setParentTransactionId(64654);
        $payment->setIsTransactionClosed(false);

        $this->getInfoInstance()->setAdditionalInformation('', '');

        return $this;
    }

    public function capture(InfoInterface $payment, $amount)
    {
        /** @var Payment $payment */
        /** @var Order $order */
        /** @var UserClient $user */
        /** @var Customer $customer */
        /** @var LoggerInterface $logger */
        /** @var Address $billingAddress */
        /** @var Charge $charge */
        $logger = ObjectManager::getInstance()->get(LoggerInterface::class);
        $order = $payment->getOrder();
        $order->setStatus(Order::STATE_PENDING_PAYMENT);

        if (is_null($payment->getParentTransactionId())) {
            $this->authorize($payment, $amount);
        }
        throw new ClientException('no money');

        $order->setStatus(Order::STATE_PROCESSING);
        $payment->setIsTransactionClosed(true);

        return parent::capture($payment, $amount); // TODO: Change the autogenerated stub
    }
}
