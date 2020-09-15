<?php
namespace Hyperpay\Extension\Controller\Index;

 use \Magento\Sales\Model\Order as OrderStatus;


class Status extends \Magento\Framework\App\Action\Action
{
    /**
     *
     * @var \Magento\Framework\View\Result\PageFactory 
     */
    protected $_pageFactory;
    /**
     *
     * @var \Hyperpay\Extension\Model\Adapter 
     */
    protected $_adapter;
    /**
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry;
    /**
     *
     * @var \Hyperpay\Extension\Helper\Data
     */
    protected $_helper;
     /**
     *
     * @var \Magento\Framework\App\Request\Http
     */
    protected $_request;
    /**
     *
     * @var \Magento\Quote\Model\QuoteManagement $quoteManagement
     */
    protected $_quoteManagement;
    /**
     *
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;
    /**
     *
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $_orderInterface;
    /**
     *
     * @var \Magento\Quote\Model\Quote
     */
    protected $_quote;
    /**
     *
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;
    /**
     * @var \Magento\Directory\Model\CurrencyFactory
     */
    protected $_currencyFactory;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;
    /**
     * Constructor
     * 
     * @param \Magento\Framework\App\Action\Context      $context
     * @param \Hyperpay\Extension\Model\Adapter             $adapter
     * @param \Magento\Framework\Registry                $coreRegistry
     * @param \Hyperpay\Extension\Helper\Data               $helper
     * @param \Magento\Framework\View\Result\PageFactory $pageFactory
     * @param \Magento\Framework\App\Request\Http        $request
     * @param  \Magento\Quote\Model\Quote               $quote
     * @param \Magento\Quote\Model\QuoteManagement $quoteManagement
     * @param \Magento\Directory\Model\CurrencyFactory $currencyFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager,
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Sales\Api\Data\OrderInterface $orderInterface
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Hyperpay\Extension\Model\Adapter $adapter,
        \Magento\Framework\Registry $coreRegistry,
        \Hyperpay\Extension\Helper\Data $helper,
        \Magento\Framework\View\Result\PageFactory $pageFactory,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Quote\Model\Quote $quote,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Api\Data\OrderInterface $orderInterface
    ) 
    { 
        parent::__construct($context);
        $this->_pageFactory = $pageFactory;
        $this->_coreRegistry=$coreRegistry;
        $this->_request = $request;
        $this->_helper=$helper;
        $this->_quote=$quote;
        $this->_adapter=$adapter;
        $this->_quoteManagement = $quoteManagement;
        $this->_customerSession = $customerSession;
        $this->_checkoutSession = $checkoutSession;
        $this->_orderInterface = $orderInterface;
        $this->_storeManager = $storeManager;
        $this->_currencyFactory = $currencyFactory;
    }
    public function execute()
    {
        $this->messageManager->getMessages(true);
        try {
            $data= $this->getHyperpayStatus();
            $quote = $this->_quote->load($data['merchantTransactionId'], 'reserved_order_id');
        } catch (\Exception $e) {
            $this->messageManager->addError($e->getMessage());
            return $this->_pageFactory->create();
        }
        try{
            if($this->_customerSession->isLoggedIn())
            {
                $orderId = $this->_quoteManagement->placeOrder($quote->getId());
            }
            else
            {
                $quote->setCustomerId(null)
                    ->setCustomerEmail($quote->getBillingAddress()->getEmail())
                    ->setCustomerIsGuest(true)
                    ->setCustomerGroupId(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);

                $quote->save();
                $orderId = $this->_quoteManagement->submit($quote)->getEntityId();
            }

            $order = $this->_orderInterface->load($orderId);
            $currentCurrency = $this->_adapter->getSupportedCurrencyCode($this->_request->getParam('method'));
            $baseCurrency = $this->_storeManager->getStore()->getBaseCurrency()->getCode();
            if ($currentCurrency != $baseCurrency) {
                $rateToBase = $this->_currencyFactory->create()->load($currentCurrency)->getAnyRate($this->_storeManager->getStore()->getBaseCurrency()->getCode());
                $data['amount'] = $data['amount'] * $rateToBase;
            }
            $order->setGrandTotal($data['amount']);
            $order->getPayment()->setMethod($this->_request->getParam('method'))->save();
            $this->_adapter->setInfo($order, $data['id']);
            $status = $this->_adapter->orderStatus($data, $order);
            $this->_coreRegistry->register('status', $status);
            $this->_coreRegistry->register('order', $order->getIncrementId());
            $this->_checkoutSession->setLastQuoteId($quote->getId());
            $this->_checkoutSession->setLastSuccessQuoteId($quote->getId());
            $this->_checkoutSession->setLastOrderId($order->getId());
            $this->_checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->_checkoutSession->setLastOrderStatus($order->getStatus());

            if ($status !== 'success')
            {
                $this->_checkoutSession->restoreQuote();
                $this->_redirect('checkout/onepage/failure');
            }else{
                $this->_redirect('checkout/onepage/success');
            }

        }catch(\Exception $e)
        {
            $this->messageManager->addError($e->getMessage());
            $this->_redirect('checkout/onepage/failure');

        }

        

    }
    /**
     * Retrieve payment gateway response and set id to payment table
     *
     * @param $order
     * @return string
     */ 
    public function getHyperpayStatus()
    {

        if(empty($this->_request->getParam('id'))) {
            $this->_helper->doError('Checkout id is not found');
        }

        $id = $this->_request->getParam('id');
        $url = $this->_adapter->getUrl()."checkouts/".$id."/payment";
        $url .= "?entityId=".$this->_adapter->getEntity($this->_request->getParam('method'));
        $auth = array('Authorization'=>'Bearer '.$this->_adapter->getAccessToken());
        $this->_helper->setHeaders($auth);

        $decodedData = $this->_helper->getCurlRespData($url);
        if (!isset($decodedData)) {
            $this->_helper->doError('No response data found');
        }
        if (!isset($decodedData['id'])) {
            $this->_helper->doError('Response id is not found');
        }
        
        return $decodedData;
        
    }


}
