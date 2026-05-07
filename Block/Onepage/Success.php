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
        } else {
            return [
                'customerName' => '',
                'company' => '',
                'street' => '',
                'city' => '',
                'region' => '',
                'postcode' => '',
                'countryId' => ''
            ];
        }
    }
    

    public function getBillingDetails()
    {
        $billingAddress = $this->order->getBillingAddress();
        if (!$billingAddress) {
            return [];
        }
        return [
            'customerName' => trim($billingAddress->getFirstname() . ' ' . $billingAddress->getLastname()),
            'company' => $billingAddress->getCompany(),
            'street' => $billingAddress->getStreet(),
            'city' => $billingAddress->getCity(),
            'region' => $billingAddress->getRegion(),
            'postcode' => $billingAddress->getPostcode(),
            'countryId' => $billingAddress->getCountryId()
        ];
    }

    /**
     * Get formatted line item data for each item in the order.
     *
     * Configurable parents (e.g. configurable, bundle) are returned with their
     * children excluded so each visible row reflects what the customer sees in cart.
     *
     * @return array
     */
    public function getOrderItemsDetails()
    {
        $items = [];
        $orderItems = $this->order->getAllVisibleItems();
        if (!$orderItems) {
            return $items;
        }

        $currencySymbol = $this->currency->getCurrency($this->order->getOrderCurrencyCode())->getSymbol();

        foreach ($orderItems as $orderItem) {
            $qty = (float)$orderItem->getQtyOrdered();
            $rowTotal = (float)$orderItem->getRowTotalInclTax();
            if (!$rowTotal) {
                $rowTotal = (float)$orderItem->getRowTotal();
            }
            $price = (float)$orderItem->getPriceInclTax();
            if (!$price) {
                $price = (float)$orderItem->getPrice();
            }

            $items[] = [
                'name' => $orderItem->getName(),
                'sku' => $orderItem->getSku(),
                'qty' => $qty,
                'qtyFormatted' => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'),
                'price' => $currencySymbol . number_format($price, 2),
                'rowTotal' => $currencySymbol . number_format($rowTotal, 2),
                'image' => $this->getOrderItemImage($orderItem),
                'options' => $this->getOrderItemOptions($orderItem),
            ];
        }

        return $items;
    }

    /**
     * Get the configured image URL for an order line item.
     *
     * @param \Magento\Sales\Api\Data\OrderItemInterface $orderItem
     * @return string
     */
    public function getOrderItemImage($orderItem)
    {
        try {
            $product = $this->productRepository->get($orderItem->getSku());
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return '';
        }

        $imageType = $this->getOrderItemsImageType();
        $imagePath = $product->getImage($imageType);
        if (!$imagePath || $imagePath === 'no_selection') {
            $imagePath = $product->getImage('image');
        }
        if (!$imagePath || $imagePath === 'no_selection') {
            return '';
        }

        return $this->mediaConfig->getBaseMediaUrl() . $imagePath;
    }

    /**
     * Configurable image type for line items, defaults to small_image.
     *
     * @return string
     */
    public function getOrderItemsImageType()
    {
        $type = $this->getConfigValue('line_items_row', 'image_type');
        return $type ?: 'small_image';
    }

    /**
     * Custom options / configurable selections in label => value form.
     *
     * @param \Magento\Sales\Api\Data\OrderItemInterface $orderItem
     * @return array
     */
    public function getOrderItemOptions($orderItem)
    {
        $options = [];
        $productOptions = $orderItem->getProductOptions();
        if (!is_array($productOptions)) {
            return $options;
        }

        if (!empty($productOptions['attributes_info'])) {
            foreach ($productOptions['attributes_info'] as $attribute) {
                $options[] = [
                    'label' => $attribute['label'] ?? '',
                    'value' => $attribute['value'] ?? '',
                ];
            }
        }

        if (!empty($productOptions['options'])) {
            foreach ($productOptions['options'] as $option) {
                $options[] = [
                    'label' => $option['label'] ?? '',
                    'value' => $option['value'] ?? '',
                ];
            }
        }

        return $options;
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
