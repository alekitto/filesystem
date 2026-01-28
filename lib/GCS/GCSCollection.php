<?php

declare(strict_types=1);

namespace Kcs\Filesystem\GCS;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Google\Cloud\Storage\StorageObject;

use function assert;
use function preg_quote;
use function preg_replace;

class GCSCollection extends AbstractLazyCollection
{
    private string $prefixPattern;

    /** @param iterable<StorageObject> $iterator */
    public function __construct(
        private readonly iterable $iterator,
        string $prefix,
    ) {
        $this->prefixPattern = '#^' . preg_quote($prefix, '#') . '#';
    }

    protected function doInitialize(): void
    {
        $this->collection = new ArrayCollection();

        foreach ($this->iterator as $item) {
            $key = $item->name();
            $relativeKey = preg_replace($this->prefixPattern, '', $key);
            assert($relativeKey !== null);

            /* @phpstan-ignore-next-line */
            $this->collection[] = new GCSFileStat(
                $item,
                $key,
                $relativeKey,
            );
        }
    }
}
