<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests;

use MulerTech\CspBundle\MulerTechCspBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MulerTechCspBundleTest extends TestCase
{
    public function testBundleExtensionAlias(): void
    {
        $bundle = new MulerTechCspBundle();
        $container = new ContainerBuilder();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('csp', $extension->getAlias());
    }
}
