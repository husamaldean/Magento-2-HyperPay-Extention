<?php
namespace Hyperpay\Extension\Controller\Index;



class Request extends \Magento\Framework\App\Action\Action
{
   
    /**
     *
     * @var \Magento\Framework\View\Result\PageFactory 
     */
    protected $_pageFactory;
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
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;
     /**
     *
     * @var \Hyperpay\Extension\Model\Adapter
     */
    protected $_adapter;
     /**
     *
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress
     */
    protected $_remote;
    /**
     *
     * @var \Magento\Framework\App\Request\Http $_request
     */
    protected $_request;
     /**
     *
     * @var \Magento\Framework\Locale\Resolver
     */
    protected $_resolver;
    /**
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;
    /**
     * Constructor
     * 
     * @param \Magento\Framework\App\Action\Context                $context
     * @param \Magento\Framework\Registry                          $coreRegistry
     * @param \Hyperpay\Extension\Helper\Data                         $helper
     * @param \Magento\Checkout\Model\Session                      $checkoutSession
     * @param \Magento\Framework\View\Result\PageFactory           $pageFactory
     * @param \Magento\Framework\Locale\Resolver                    $resolver
     * @param \Hyperpay\Extension\Model\Adapter                       $adapter
     * @param \Magento\Framework\App\Request\Http                      $request
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remote
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Registry $coreRegistry,
        \Hyperpay\Extension\Helper\Data $helper,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\View\Result\PageFactory $pageFactory,
        \Magento\Framework\Locale\Resolver     $resolver,
        \Magento\Framework\App\Request\Http $request,
        \Hyperpay\Extension\Model\Adapter $adapter,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remote
    ) 
    { 
        $this->_coreRegistry=$coreRegistry;
        parent::__construct($context);
        $this->_checkoutSession = $checkoutSession;
        $this->_helper=$helper;
        $this->_pageFactory = $pageFactory;
        $this->_adapter=$adapter;
        $this->_resolver = $resolver;
        $this->_remote=$remote;
        $this->_request = $request;
        $this->_storeManager=$storeManager;
    }
    public function execute()
    {
        try {
            if(!($this->_checkoutSession->getQuote())) {
                $this->_helper->doError('Quote is not found');
            }

            $quote=$this->_checkoutSession->getQuote();
        } catch (\Exception $e) {
            $this->messageManager->addError($e->getMessage());
            return $this->_pageFactory->create();
        }
        try
        {
            $code = $this->_request->getParam('code');

            $urlReq=$this->prepareTheCheckout($quote,$code);
        }
        catch (\Exception $e)
        {
            $this->messageManager->addError($e->getMessage());
            return $this->_pageFactory->create();
        }
        $this->_coreRegistry->register('formurl', $urlReq);
        $this->_coreRegistry->register('brand', $this->_helper->getBrand($code));
        $base = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $status= $base."hyperpay/index/status/?method=".$code;
        $this->_coreRegistry->register('status', $status);
        return $this->_pageFactory->create();
    }
    /**
     * Build data and make a request to hyperpay payment gateway
     * and return url of form 
     *
     * @param $order
     * @return string
     */ 
    public function prepareTheCheckout($quote,$code)
    {

        //$shippingMethod =$order->getShippingMethod();
        $email = $quote->getBillingAddress()->getEmail();
        if (!$email) $email = $quote->getCustomerEmail();
        if (!$email) $email = $quote->getShippingAddress()->getEmail();
        //order#
        $quote->reserveOrderId()->save();
        $orderId=$quote->getReservedOrderId();
        $amount=$quote->getBaseGrandTotal();
        $total=$this->_helper->convertPrice($code, $amount);

        if($this->_adapter->getEnv()) {
            $grandTotal = (int) $total;
        }else {
            $grandTotal=number_format($total, 2, '.', '');
        }

        $currency=$this->_adapter->getSupportedCurrencyCode($code);
        $paymentType =$this->_adapter->getPaymentType($code);
        $this->_adapter->setPaymentTypeAndCurrency($quote, $paymentType, $currency);

        $ip = $this->_remote->getRemoteAddress();
        $baseUrl = $this->_adapter->getUrl();
        $url = $baseUrl.'checkouts';
        $data = "entityId=".$this->_adapter->getEntity($code).
            "&amount=".$grandTotal.
            "&currency=".$currency.
            "&paymentType=".$paymentType.
            "&customer.ip=".$ip.
            "&customer.email=".$email.
            "&shipping.customer.email=".$email.
            "&merchantTransactionId=".$orderId;
        $accesstoken = $this->_adapter->getAccessToken();
        $auth = array('Authorization'=>'Bearer '.$accesstoken);
        $this->_helper->setHeaders($auth);
        $data .= $this->_helper->getBillingAndShippingAddress($quote,$code);
        if(!empty($this->_adapter->getRiskChannelId())) {
            $data .= "&risk.channelId=".$this->_adapter->getRiskChannelId(). 
                    "&risk.serviceId=I".
                    "&risk.amount=".$grandTotal.
                    "&risk.parameters[USER_DATA1]=Mobile";
        }
        
             
        
        $data .= $this->_adapter->getModeHyperpay();
        /*   .
        "&shipping.method=".$shippingMethod*/
        if($code=='HyperPay_SadadNcb') {
            $data .="&bankAccount.country=SA"; 
        }
        if ($code=='HyperPay_stc') {
            $data .= '&customParameters[branch_id]=1';
            $data .= '&customParameters[teller_id]=1';
            $data .= '&customParameters[device_id]=1';
            $data .= '&customParameters[locale]='. substr($this->_resolver->getLocale(),0,-3);
            $data .= '&customParameters[bill_number]=' . $orderId;

        }
        if($this->_adapter->getEnv() && $code=='HyperPay_ApplePay') {
            $data .= "&customParameters[3Dsimulator.forceEnrolled]=true";
        }
        if ($this->checkIfExist($orderId,$baseUrl)) {
            throw new \Exception(__("This order has already been processed,Please place a new order"));
        }
        $decodedData = $this->_helper->getCurlReqData($url, $data);
        if (!isset($decodedData['id'])) {
            $this->_helper->doError('Request id is not found');
        }
        return $this->_adapter->getUrl()."paymentWidgets.js?checkoutId=".$decodedData['id'];

        
    }
    private function checkIfExist($id,$baseUrl)
    {
        $url = $baseUrl."query";
        $url .= "?entityId=".$this->_adapter->getMerchantEntity();
        $url .=	"&merchantTransactionId=".$id;
        $auth = array('Authorization'=>'Bearer '.$this->_adapter->getAccessToken());
        $this->_helper->setHeaders($auth);
        $response =  $this->_helper->getCurlRespData($url);
        if ($response['result']['code']==="700.400.580")
        {
            return false;
        }
        return true;
    }

    
}
