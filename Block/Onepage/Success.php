<?php

namespace Zero1\ImprovedCheckoutSuccessPageHyva\Block\Onepage;

use Zero1\ImprovedCheckoutSuccessPageHyva\Model\Source\Blocks as SourceModelBlocks;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
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
     * @var ConfigurableType
     */
    private $configurableType;

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
        ConfigurableType $configurableType,
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
        $this->configurableType = $configurableType;
    }

    public function getCustomerDetails(): array
    {
        return [
            'name' => $this->getCustomerName(),
            'email' => $this->order->getCustomerEmail()
        ];
    }

    public function getCustomerName(): string
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

    public function getOrderDetails(): array
    {
        $currencySymbol = $this->currency->getCurrency($this->order->getOrderCurrencyCode())->getSymbol();

        $dateOrderWasPlacedFull = $this->order->getCreatedAtFormatted(\IntlDateFormatter::LONG); // "5 March 2024 at 09:16:24 GMT"
        $dateOrderWasPlaced = preg_replace('/\sat\s.*$/', '', $dateOrderWasPlacedFull);

        $shippingAmount = $this->order->getShippingAmount();
        if ((float) $shippingAmount == 0.0) {
            $shippingAmount = 'FREE';
        } else {
            $shippingAmount = $currencySymbol . number_format($shippingAmount, 2);
        }

        $taxAmount = $this->order->getTaxAmount();
        $grandTotalIncVat = $this->order->getGrandTotal();
        $grandTotalExVat = $grandTotalIncVat - $taxAmount;

        return [
            'shippingMethod' => $this->order->getShippingDescription(),
            'shippingAmount' => $shippingAmount,
            'taxAmount' => $currencySymbol . number_format($taxAmount, 2),
            'grandTotalExVat' => $currencySymbol . number_format($grandTotalExVat, 2),
            'grandTotalIncVat' => $currencySymbol . number_format($grandTotalIncVat, 2),
            'incrementId' => $this->order->getIncrementId(),
            'createdAt' => $dateOrderWasPlaced
        ];
    }

    /**
     * @deprecated 1.1.3
     * Use getOrderDetails() instead. This method is kept for backward compatibility and will be removed in future versions.
     *
     * @return array
     */
    public function getMoreOrderDetails(): array
    {
        return $this->getOrderDetails();
    }

    public function getShippingDetails(): array
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


    public function getBillingDetails(): array
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
    public function getOrderItemsDetails(): array
    {
        $items = [];
        $orderItems = $this->order->getAllVisibleItems();
        if (!$orderItems) {
            return $items;
        }

        $currencySymbol = $this->currency->getCurrency($this->order->getOrderCurrencyCode())->getSymbol();

        foreach ($orderItems as $orderItem) {
            // Skip child items and zero-price fulfilment rows (e.g. bundle components
            // added as standalone order items by third-party modules).
            if ($orderItem->getParentItemId()) {
                continue;
            }
            $price = (float)$orderItem->getPriceInclTax() ?: (float)$orderItem->getPrice();
            $rowTotal = (float)$orderItem->getRowTotalInclTax() ?: (float)$orderItem->getRowTotal();
            if ($price == 0.0 && $rowTotal == 0.0) {
                continue;
            }

            $qty = (float)$orderItem->getQtyOrdered();

            $items[] = [
                'name'         => $orderItem->getName(),
                'sku'          => $orderItem->getSku(),
                'qty'          => $qty,
                'qtyFormatted' => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'),
                'price'        => $currencySymbol . number_format($price, 2),
                'rowTotal'     => $currencySymbol . number_format($rowTotal, 2),
                'image'        => $this->getOrderItemImage($orderItem),
                'options'      => $this->getOrderItemOptions($orderItem),
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
    public function getOrderItemImage($orderItem): string
    {
        // getProductId() returns the parent product for configurable/bundle order items,
        // which is where the images live. Try this first.
        try {
            $url = $this->resolveProductImageUrl(
                $this->productRepository->getById((int)$orderItem->getProductId())
            );
            if ($url) {
                return $url;
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // fall through
        }

        // Fallback: look up by SKU. For a simple product this is the same as above.
        // For a configurable child SKU with no images, also try the configurable parent.
        try {
            $product = $this->productRepository->get($orderItem->getSku());
            $url = $this->resolveProductImageUrl($product);
            if ($url) {
                return $url;
            }

            $parentIds = $this->configurableType->getParentIdsByChild($product->getId());
            if (!empty($parentIds)) {
                $url = $this->resolveProductImageUrl(
                    $this->productRepository->getById($parentIds[0])
                );
                if ($url) {
                    return $url;
                }
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // no product found
        }

        return '';
    }

    /**
     * Try all standard image roles then the raw media gallery to find a usable URL.
     * Returns empty string if no image can be found.
     */
    private function resolveProductImageUrl($product): string
    {
        $imageType = $this->getOrderItemsImageType();

        foreach ([$imageType, 'image', 'small_image', 'thumbnail'] as $role) {
            $path = $product->getData($role);
            if ($path && $path !== 'no_selection') {
                return $this->mediaConfig->getBaseMediaUrl() . $path;
            }
        }

        // getMediaGalleryImages() may not be populated via repository; use raw gallery data.
        $galleryImages = $product->getMediaGallery('images');
        if (is_array($galleryImages)) {
            foreach ($galleryImages as $image) {
                if (!empty($image['file']) && empty($image['disabled'])) {
                    return $this->mediaConfig->getBaseMediaUrl() . $image['file'];
                }
            }
        }

        return '';
    }

    /**
     * Configurable image type for line items, defaults to small_image.
     *
     * @return string
     */
    public function getOrderItemsImageType(): string
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
    public function getOrderItemOptions($orderItem): array
    {
        $options = [];
        $productOptions = $orderItem->getProductOptions();
        if (!is_array($productOptions)) {
            return $options;
        }

        // Configurable attributes (e.g. Size: Large, Color: Blue)
        if (!empty($productOptions['attributes_info'])) {
            foreach ($productOptions['attributes_info'] as $attribute) {
                $options[] = [
                    'label' => $attribute['label'] ?? '',
                    'value' => $attribute['value'] ?? '',
                ];
            }
        }

        // Bundle selections (each bundle option with its chosen item)
        if (!empty($productOptions['bundle_options'])) {
            foreach ($productOptions['bundle_options'] as $bundleOption) {
                if (empty($bundleOption['value'])) {
                    continue;
                }
                foreach ($bundleOption['value'] as $bundleValue) {
                    $qty = (int)($bundleValue['qty'] ?? 1);
                    $title = $bundleValue['title'] ?? '';
                    $options[] = [
                        'label' => $bundleOption['label'] ?? '',
                        'value' => ($qty > 1 ? $qty . ' x ' : '') . $title,
                    ];
                }
            }
        }

        // Custom options (dropdowns, text fields, etc.)
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

    public function getRelatedProductIdsOfOrderItems(): array
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
