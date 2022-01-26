<?php

namespace Zero1\ImprovedCheckoutSuccessPageHyva\Model\Source;

use Magento\Cms\Model\BlockFactory;
use Magento\Framework\Data\OptionSourceInterface;

class Blocks implements OptionSourceInterface
{

    /**
     * @var BlockFactory
     */
    private $blockFactory;

    public function __construct(
        BlockFactory $blockFactory
    ) {
        $this->blockFactory = $blockFactory;
    }

    public function toOptionArray()
    {
        $blockCollection = $this->blockFactory->create()->getCollection();
        $blocks = [];
        foreach ($blockCollection as $block) {
            /** @var \Magento\Cms\Model\Block $block */
            $blocks[] = [
                'value' => $block->getIdentifier(),
                'label' => $block->getTitle()
            ];
        }
        return $blocks;
    }
}
