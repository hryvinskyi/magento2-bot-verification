<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Config\Backend;

use Hryvinskyi\BotVerification\Api\LineListReaderInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * The trusted-proxy ranges as an administrator enters them: one CIDR range per line
 *
 * Each line is read alone by the same builder that later builds the ranges the request path searches, so a value is
 * stored only when every line is a range it accepts, and the message names the line it refused. Blank lines and the
 * whitespace around a line are dropped, and the lines are stored separated by line feeds. An empty value is valid:
 * nothing configured is trusted.
 */
class TrustedProxies extends Value
{
    /**
     * Moment a single line is built at; any positive moment, since the snapshot is only checked and discarded
     */
    private const CHECKED_AT = 1;

    /**
     * @param Context $context Model context
     * @param Registry $registry Registry
     * @param ScopeConfigInterface $config Configuration the stored value is compared with
     * @param TypeListInterface $cacheTypeList Cache types a changed value invalidates
     * @param RangeSnapshotBuilderInterface $builder Reads each line as the request path will
     * @param LineListReaderInterface $lineListReader Splits the value into lines the way the settings read it back
     * @param AbstractResource|null $resource Resource model
     * @param AbstractDb|null $resourceCollection Resource collection
     * @param array<string, mixed> $data Model data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly RangeSnapshotBuilderInterface $builder,
        private readonly LineListReaderInterface $lineListReader,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Store the ranges one per line, refusing the value when a line is not a CIDR range
     *
     * @return $this
     * @throws LocalizedException When a line is not a CIDR range, naming the line
     */
    public function beforeSave()
    {
        $ranges = $this->lineListReader->read((string) $this->getValue());
        foreach ($ranges as $range) {
            try {
                $this->builder->build([$range], self::CHECKED_AT);
            } catch (RangeListRejectedException $exception) {
                throw new LocalizedException(
                    __('"%1" is not a CIDR range, e.g. 10.0.0.0/8 or 2001:db8::/32.', $range),
                    $exception
                );
            }
        }
        $this->setValue(implode("\n", $ranges));

        return parent::beforeSave();
    }
}
