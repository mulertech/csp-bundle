<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests;

use MulerTech\CspBundle\CspNonceGenerator;
use MulerTech\CspBundle\EventSubscriber\CspHeaderSubscriber;
use MulerTech\CspBundle\MulerTechCspBundle;
use MulerTech\CspBundle\Service\CspHeaderBuilder;
use MulerTech\CspBundle\Twig\CspExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class MulerTechCspBundleTest extends TestCase
{
    private MulerTechCspBundle $bundle;

    protected function setUp(): void
    {
        $this->bundle = new MulerTechCspBundle();
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.build_dir', '/tmp/build');

        return $container;
    }

    public function testBundleExtensionAlias(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('mulertech_csp', $extension->getAlias());
    }

    public function testLoadExtensionWithDefaultConfig(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
            ]
        ], $container);

        self::assertTrue($container->hasDefinition('mulertech_csp.nonce_generator'));
        self::assertTrue($container->hasDefinition('mulertech_csp.header_builder'));
        self::assertTrue($container->hasDefinition('mulertech_csp.header_subscriber'));

        $builderDef = $container->getDefinition('mulertech_csp.header_builder');
        self::assertSame(CspHeaderBuilder::class, $builderDef->getClass());
    }

    public function testLoadExtensionWithDisabledBundle(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => false,
            ]
        ], $container);

        self::assertFalse($container->hasDefinition('mulertech_csp.header_builder'));
        self::assertFalse($container->hasDefinition('mulertech_csp.header_subscriber'));
    }

    public function testLoadExtensionWithCustomDirectives(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
                'directives' => [
                    'default-src' => ["'self'", 'https://example.com'],
                    'script-src' => ['nonce(main)'],
                    'upgrade-insecure-requests' => true,
                ],
            ]
        ], $container);

        $builderDef = $container->getDefinition('mulertech_csp.header_builder');
        $args = $builderDef->getArguments();

        self::assertArrayHasKey('$directives', $args);
        $directives = $args['$directives'];
        self::assertArrayHasKey('default-src', $directives);
        self::assertContains('https://example.com', $directives['default-src']);
        self::assertTrue($directives['upgrade-insecure-requests']);
    }

    public function testLoadExtensionWithAlwaysAdd(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
                'always_add' => ['https://cdn.example.com'],
            ]
        ], $container);

        $builderDef = $container->getDefinition('mulertech_csp.header_builder');
        $args = $builderDef->getArguments();

        self::assertArrayHasKey('$alwaysAdd', $args);
        self::assertSame(['https://cdn.example.com'], $args['$alwaysAdd']);
    }

    public function testLoadExtensionWithReportConfig(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
                'report' => [
                    'url' => 'https://report.example.com/csp',
                    'chance' => 50,
                ],
            ]
        ], $container);

        $builderDef = $container->getDefinition('mulertech_csp.header_builder');
        $args = $builderDef->getArguments();

        self::assertArrayHasKey('$reportConfig', $args);
        self::assertSame('https://report.example.com/csp', $args['$reportConfig']['url']);
        self::assertSame(50, $args['$reportConfig']['chance']);

        $subscriberDef = $container->getDefinition('mulertech_csp.header_subscriber');
        $subscriberArgs = $subscriberDef->getArguments();

        self::assertArrayHasKey('$reportConfig', $subscriberArgs);
        self::assertSame('https://report.example.com/csp', $subscriberArgs['$reportConfig']['url']);
    }

    public function testLoadExtensionWithReportOnly(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
                'report_only' => true,
            ]
        ], $container);

        $subscriberDef = $container->getDefinition('mulertech_csp.header_subscriber');
        $args = $subscriberDef->getArguments();

        self::assertArrayHasKey('$reportOnly', $args);
        self::assertTrue($args['$reportOnly']);
    }

    public function testLoadExtensionRegistersTwigExtension(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
            ]
        ], $container);

        self::assertTrue($container->hasDefinition('mulertech_csp.twig_extension'));
        $twigDef = $container->getDefinition('mulertech_csp.twig_extension');
        self::assertSame(CspExtension::class, $twigDef->getClass());
    }

    public function testLoadExtensionWithRouteReportConfig(): void
    {
        $container = $this->createContainer();
        $container->setParameter('kernel.environment', 'dev');
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
                'report' => [
                    'route' => 'csp_report',
                    'route_params' => ['_format' => 'json'],
                    'chance' => 100,
                ],
            ]
        ], $container);

        $builderDef = $container->getDefinition('mulertech_csp.header_builder');
        $args = $builderDef->getArguments();

        self::assertArrayHasKey('$reportConfig', $args);
        self::assertSame('csp_report', $args['$reportConfig']['route']);
        self::assertSame(['_format' => 'json'], $args['$reportConfig']['route_params']);
    }

    public function testLoadExtensionAliases(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
            ]
        ], $container);

        self::assertTrue($container->hasAlias(CspNonceGenerator::class));
        self::assertTrue($container->hasAlias(CspHeaderBuilder::class));
    }

    public function testSubscriberIsRegisteredAsEventSubscriber(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
            ]
        ], $container);

        $subscriberDef = $container->getDefinition('mulertech_csp.header_subscriber');
        $tags = $subscriberDef->getTags();

        self::assertArrayHasKey('kernel.event_subscriber', $tags);
    }

    public function testConfigureDefinesReportOnlyOption(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
                'report_only' => false,
            ]
        ], $container);

        self::assertTrue($container->hasDefinition('mulertech_csp.header_subscriber'));
    }

    public function testConfigureDefinesDirectivesOption(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
                'directives' => [
                    'script-src' => ["'self'", 'https://trusted.example.com'],
                ],
            ]
        ], $container);

        $builderDef = $container->getDefinition('mulertech_csp.header_builder');
        $args = $builderDef->getArguments();

        self::assertArrayHasKey('$directives', $args);
        self::assertArrayHasKey('script-src', $args['$directives']);
    }

    public function testConfigureDefinesReportRouteParamsOption(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
                'report' => [
                    'route' => 'api_csp_report',
                    'route_params' => ['token' => 'abc123'],
                ],
            ]
        ], $container);

        $builderDef = $container->getDefinition('mulertech_csp.header_builder');
        $args = $builderDef->getArguments();

        self::assertSame(['token' => 'abc123'], $args['$reportConfig']['route_params']);
    }

    public function testConfigureDefinesReportChanceOption(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
                'report' => [
                    'url' => 'https://report.example.com',
                    'chance' => 75,
                ],
            ]
        ], $container);

        $builderDef = $container->getDefinition('mulertech_csp.header_builder');
        $args = $builderDef->getArguments();

        self::assertSame(75, $args['$reportConfig']['chance']);
    }

    public function testLoadExtensionWithRouterService(): void
    {
        $container = $this->createContainer();
        $container->register('router', \Symfony\Component\Routing\RouterInterface::class);
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            'mulertech_csp' => [
                'enabled' => true,
            ]
        ], $container);

        $builderDef = $container->getDefinition('mulertech_csp.header_builder');
        $args = $builderDef->getArguments();

        self::assertArrayHasKey('$urlGenerator', $args);
        self::assertNotNull($args['$urlGenerator']);
    }
}
