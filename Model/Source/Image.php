<?php

namespace Zero1\ImprovedCheckoutSuccessPageHyva\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Image implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 'image',
                'label' => __('Base Image')
            ],
            [
                'value' => 'small_image',
                'label' => __('Small Image')
            ],
            [
                'value' => 'thumbnail',
                'label' => __('Thumbnail')
            ],
            [
                'value' => 'swatch_image',
                'label' => __('Swatch Image')
            ]
        ];
    }
}
