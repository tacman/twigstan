<?php

declare(strict_types=1);

namespace TwigStan\EndToEnd\Types;

use TwigStan\EndToEnd\AbstractEndToEndTestCase;

final class TestCase extends AbstractEndToEndTestCase
{
    public function test(): void
    {
        parent::runTests(__DIR__);
    }
}