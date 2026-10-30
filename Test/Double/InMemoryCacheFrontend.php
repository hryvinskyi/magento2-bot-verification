<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Magento\Framework\Cache\FrontendInterface;

/**
 * A cache that keeps its entries in memory, never expires them, and records which identifiers were loaded, saved and
 * removed
 *
 * It has no storage backend: asking for one is a mistake in the test.
 */
class InMemoryCacheFrontend implements FrontendInterface
{
    /**
     * @var array<string, array{data: string, tags: array<mixed>, lifetime: int|bool|null}> Identifier => entry
     */
    public array $entries = [];

    /**
     * @var list<string> Identifiers loaded, in order, once per load
     */
    public array $loaded = [];

    /**
     * @var list<string> Identifiers saved, in order, once per save
     */
    public array $saved = [];

    /**
     * @var list<string> Identifiers removed, in order, once per removal
     */
    public array $removed = [];

    /**
     * Replace the data of every entry, keeping its tags and lifetime
     *
     * @param string $data Data every entry holds afterwards
     * @return void
     */
    public function replaceAllData(string $data): void
    {
        foreach (array_keys($this->entries) as $identifier) {
            $this->entries[$identifier]['data'] = $data;
        }
    }

    /**
     * @inheritDoc
     */
    public function test($identifier)
    {
        return isset($this->entries[$identifier]) ? 1 : false;
    }

    /**
     * @inheritDoc
     */
    public function load($identifier)
    {
        $this->loaded[] = $identifier;

        return isset($this->entries[$identifier]) ? $this->entries[$identifier]['data'] : false;
    }

    /**
     * @inheritDoc
     * @param array<mixed> $tags
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $this->saved[] = $identifier;
        $this->entries[$identifier] = ['data' => $data, 'tags' => $tags, 'lifetime' => $lifeTime];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function remove($identifier)
    {
        $this->removed[] = $identifier;
        unset($this->entries[$identifier]);

        return true;
    }

    /**
     * @inheritDoc
     * @param array<mixed> $tags
     */
    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        $this->entries = [];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getBackend()
    {
        throw new \LogicException('An in-memory cache has no storage backend.');
    }

    /**
     * @inheritDoc
     */
    public function getLowLevelFrontend()
    {
        throw new \LogicException('An in-memory cache has no low-level frontend.');
    }
}
