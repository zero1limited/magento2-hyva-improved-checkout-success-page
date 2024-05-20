<?php

namespace Zero1\ImprovedCheckoutSuccessPageHyva\Block\Onepage;

use Zero1\ImprovedCheckoutSuccessPageHyva\Model\Source\Blocks as SourceModelBlocks;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\View\Element\Template;
use Magento\Checkout\Model\Session\Proxy as CheckoutSession;
use Magento\Store\Model\Store;
use Magento\Framework\Locale\CurrencyInterface;

class Success extends Template
{

    private $order;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var SourceModelBlocks
     */
    private $blocks;

    /**
     * @var MediaConfig
     */
    private $mediaConfig;

    /**
     * @var Store
     */
    private $store;

    /**
     * @var CurrencyInterface
     */

    private $currency;

    public function __construct(
        Template\Context $context,
        CheckoutSession $checkoutSession,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SourceModelBlocks $blocks,
        MediaConfig $mediaConfig,
        Store $store,
        CurrencyInterface $currency,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $lastRealOrder = $checkoutSession->getLastRealOrder();
        $this->order = $lastRealOrder->loadByIncrementId($lastRealOrder->getIncrementId());
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->blocks = $blocks;
        $this->mediaConfig = $mediaConfig;
        $this->store = $store;
        $this->currency = $currency;
    }

    public function getCustomerDetails()
    {
        return [
            'name' => $this->getCustomerName(),
            'email' => $this->order->getCustomerEmail()
        ];
    }

    public function getCustomerName()
    {
        $useCheckoutNameForGuestCustomers = $this->getConfigFlag('general', 'use_chekout_name_for_guest_customers');
        $guestCustomerName = $this->getConfigValue('general', 'guest_name');

        if (boolval($this->order->getCustomerIsGuest()) === true) {
            if ($useCheckoutNameForGuestCustomers === true) {
                return $this->getShippingDetails()['customerName'];
            }
            return $guestCustomerName;
        }
        return $this->order->getCustomerFirstname() . ' ' . $this->order->getCustomerLastname();
    }

    public function getOrderDetails()
    {
        $currencySymbol = $this->currency->getCurrency($this->order->getOrderCurrencyCode())->getSymbol();
        $dateOrderWasPlacedFull = $this->order->getCreatedAtFormatted(\IntlDateFormatter::LONG); // "5 March 2024 at 09:16:24 GMT"
        $dateOrderWasPlaced = preg_replace('/\sat\s.*$/', '', $dateOrderWasPlacedFull);

        return [
            'incrementId' => $this->order->getIncrementId(),
            'baseGrandTotal' => $currencySymbol . number_format($this->order->getBaseGrandTotal(), 2),
            'createdAt' => $dateOrderWasPlaced
        ];
    }

    public function getShippingDetails()
    {
        $shippingAddress = $this->order->getShippingAddress();
        if ($shippingAddress) {
            return [
                'customerName' => $shippingAddress->getFirstname() . ' ' . $shippingAddress->getLastname(),
                'company' => $shippingAddress->getCompany(),
                'street' => $shippingAddress->getStreet(),
                'city' => $shippingAddress->getCity(),
                'region' => $shippingAddress->getRegion(),
                'postcode' => $shippingAddress->getPostcode(),
                'countryId' => $shippingAddress->getCountryId()
            ];
        }
        return [];
    }

    public function getBillingDetails()
    {
        return [
            'company' => $this->order->getBillingAddress()->getCompany(),
            'street' => $this->order->getBillingAddress()->getStreet(),
            'city' => $this->order->getBillingAddress()->getCity(),
            'region' => $this->order->getBillingAddress()->getRegion(),
            'postcode' => $this->order->getBillingAddress()->getPostcode(),
            'countryId' => $this->order->getBillingAddress()->getCountryId()
        ];
    }

    public function getRelatedProductIdsOfOrderItems()
    {
        $orderItems = $this->order->getAllItems();
        $relatedProductIdsArray = [];
        if ($orderItems) {
            foreach ($orderItems as $orderItem) {
                $product = $this->productRepository->get($orderItem->getSku());
                $relatedProductIds = $product->getRelatedProductIds();
                foreach ($relatedProductIds as $relatedProductId) {
                    $relatedProductIdsArray[] = $relatedProductId;
                }
            }
        }
        return $relatedProductIdsArray;
    }

    public function getRelatedProducts()
    {
        $this->searchCriteriaBuilder->addFilter('entity_id', $this->getRelatedProductIdsOfOrderItems(), 'in');
        $products = $this->productRepository->getList($this->searchCriteriaBuilder->create())->getItems();
        return $products;
    }

    public function getRelatedProductImageType()
    {
        return $this->getConfigValue('related_products_row', 'image_type');
    }
	
    public function getRelatedProductImage($relatedProduct)
    {
        $baseMediaUrl = $this->mediaConfig->getBaseMediaUrl();
        $productImage = $baseMediaUrl . $relatedProduct->getImage($this->getRelatedProductImageType());
        return $productImage;
    }

    public function getRelatedProductsTitleText()
    {
        $title = $this->getConfigValue('related_products_row', 'title');
        if ($title != '') {
            return $title;
        }
        return null;
    }

    public function relatedProductsAreAbleToRender()
    {
        $config = $this->getConfigFlag('related_products_row', 'enable');
        if ($config === true && is_array($this->getRelatedProducts())) {
            return true;
        }
        return false;
    }

    public function getMaximumNumberOfRelatedProductsToDisplay()
    {
        $count = $this->getConfigValue('related_products_row', 'max_number');
        if (is_numeric($count)) {
            return $count;
        }
        return 6;
    }

    public function getCurrentCurrencyCode()
    {
        return $this->store->getCurrentCurrency()->getCurrencySymbol();
    }

    public function getConfigValue($group, $path)
    {
        return $this->_scopeConfig->getValue('improved_checkout_success_page/' . $group . '/' . $path);
    }

    public function getConfigFlag($group, $path)
    {
        return $this->_scopeConfig->isSetFlag('improved_checkout_success_page/' . $group . '/' . $path);
    }

    public function getStaticBlockToRender()
    {
        if ($this->getConfigFlag('cms_static_block_row', 'enable') === true) {
            $blockId = $this->getConfigValue('cms_static_block_row', 'block_id');
            return $this->getLayout()->createBlock('Magento\Cms\Block\Block')->setBlockId($blockId)->toHtml();
        }
        return null;
    }

    public function getPageTitle()
    {
        $pageTitle = $this->getConfigValue('general', 'page_title');
        $pageTitle = preg_replace("/%customer_name%/", '<span>' . $this->getCustomerName() . '</span>', $pageTitle ?? '');
        return $pageTitle;
    }

    public function getIntroText()
    {
        $introText = $this->getConfigValue('general', 'intro_text');
        $introText = preg_replace("/%customer_name%/", (string)$this->getCustomerDetails()['name'], $introText ?? '');
        $introText = preg_replace("/%customer_email%/", (string)$this->getCustomerDetails()['email'], $introText ?? '');
        return $introText;
    }

    public function getNewsletterBlock()
    {
        if ($this->getConfigFlag('newsletter_row', 'enable') === true) {
            return $this->getLayout()->createBlock('Magento\Newsletter\Block\Subscribe')
                ->setTemplate('Magento_Newsletter::subscribe.phtml')->toHtml();
        }
        return null;
    }

}
