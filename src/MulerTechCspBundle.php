<?php

declare(strict_types=1);

namespace MulerTech\CspBundle;

use MulerTech\CspBundle\EventSubscriber\CspHeaderSubscriber;
use MulerTech\CspBundle\Service\CspHeaderBuilder;
use MulerTech\CspBundle\Twig\CspExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;

final class MulerTechCspBundle extends AbstractBundle
{
    protected string $extensionAlias = 'mulertech_csp';

    private const array DEFAULT_DIRECTIVES = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", 'nonce(main)'],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'media-src' => ["'self'"],
        'object-src' => ["'none'"],
        'frame-src' => ["'none'"],
        'frame-ancestors' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'upgrade-insecure-requests' => true,
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->canBeDisabled()
            ->children()
                ->booleanNode('report_only')
                    ->defaultFalse()
                ->end()
                ->arrayNode('always_add')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('report')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('url')->defaultNull()->end()
                        ->scalarNode('route')->defaultNull()->end()
                        ->arrayNode('route_params')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('chance')
                            ->defaultValue(100)
                            ->min(0)
                            ->max(100)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('directives')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->variablePrototype()->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!($config['enabled'] ?? true)) {
            return;
        }

        /** @var array<string, array<int|string, string>|bool> $userDirectives */
        $userDirectives = $config['directives'] ?? [];
        $directives = $this->mergeDirectives($userDirectives);

        $container->services()
            ->set('mulertech_csp.nonce_generator', CspNonceGenerator::class)
            ->alias(CspNonceGenerator::class, 'mulertech_csp.nonce_generator');

        $builderDef = $container->services()
            ->set('mulertech_csp.header_builder', CspHeaderBuilder::class)
            ->args([
                '$nonceGenerator' => new Reference('mulertech_csp.nonce_generator'),
                '$directives' => $directives,
                '$alwaysAdd' => $config['always_add'] ?? [],
                '$reportConfig' => $config['report'] ?? ['url' => null, 'route' => null, 'route_params' => [], 'chance' => 100],
                '$urlGenerator' => interface_exists(UrlGeneratorInterface::class) && $builder->has('router')
                    ? new Reference('router')
                    : null,
            ]);

        $container->services()
            ->set('mulertech_csp.header_subscriber', CspHeaderSubscriber::class)
            ->args([
                '$builder' => new Reference('mulertech_csp.header_builder'),
                '$dispatcher' => new Reference('event_dispatcher'),
                '$reportOnly' => $config['report_only'] ?? false,
                '$reportConfig' => $config['report'] ?? ['url' => null, 'route' => null, 'route_params' => [], 'chance' => 100],
            ])
            ->tag('kernel.event_subscriber');

        if (class_exists(AbstractExtension::class)) {
            $container->services()
                ->set('mulertech_csp.twig_extension', CspExtension::class)
                ->args([
                    '$nonceGenerator' => new Reference('mulertech_csp.nonce_generator'),
                ])
                ->tag('twig.extension');
        }

        $container->services()
            ->alias(CspHeaderBuilder::class, 'mulertech_csp.header_builder');
    }

    /**
     * @param array<string, array<int|string, string>|bool> $userDirectives
     *
     * @return array<string, list<string>|bool>
     */
    private function mergeDirectives(array $userDirectives): array
    {
        if ([] === $userDirectives) {
            return self::DEFAULT_DIRECTIVES;
        }

        $merged = self::DEFAULT_DIRECTIVES;

        foreach ($userDirectives as $name => $value) {
            if (is_array($value)) {
                $merged[$name] = array_values($value);
            } else {
                $merged[$name] = $value;
            }
        }

        return $merged;
    }
}
