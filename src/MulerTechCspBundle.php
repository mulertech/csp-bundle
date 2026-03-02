<?php

declare(strict_types=1);

namespace MulerTech\CspBundle;

use MulerTech\CspBundle\EventSubscriber\CspHeaderSubscriber;
use MulerTech\CspBundle\Twig\CspExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Twig\Extension\AbstractExtension;

final class MulerTechCspBundle extends AbstractBundle
{
    protected string $extensionAlias = 'csp';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->canBeDisabled()
            ->children()
                ->booleanNode('report_only')
                    ->defaultFalse()
                ->end()
                ->arrayNode('directives')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default-src')->defaultValue("'self'")->end()
                        ->scalarNode('script-src')->defaultValue("'self' 'nonce-{nonce}'")->end()
                        ->scalarNode('style-src')->defaultValue("'self' 'unsafe-inline'")->end()
                        ->scalarNode('img-src')->defaultValue("'self' data:")->end()
                        ->scalarNode('font-src')->defaultValue("'self'")->end()
                        ->scalarNode('connect-src')->defaultValue("'self'")->end()
                        ->scalarNode('media-src')->defaultValue("'self'")->end()
                        ->scalarNode('object-src')->defaultValue("'none'")->end()
                        ->scalarNode('frame-src')->defaultValue("'none'")->end()
                        ->scalarNode('frame-ancestors')->defaultValue("'none'")->end()
                        ->scalarNode('base-uri')->defaultValue("'self'")->end()
                        ->scalarNode('form-action')->defaultValue("'self'")->end()
                        ->scalarNode('worker-src')->defaultValue("'self'")->end()
                        ->scalarNode('manifest-src')->defaultValue("'self'")->end()
                        ->booleanNode('upgrade-insecure-requests')->defaultTrue()->end()
                        ->scalarNode('report-uri')->defaultNull()->end()
                        ->scalarNode('report-to')->defaultNull()->end()
                    ->end()
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

        $container->services()
            ->set('csp.nonce_generator', CspNonceGenerator::class)
            ->alias(CspNonceGenerator::class, 'csp.nonce_generator');

        $container->services()
            ->set('csp.header_subscriber', CspHeaderSubscriber::class)
            ->args([
                '$nonceGenerator' => $builder->getDefinition('csp.nonce_generator'),
                '$directives' => $config['directives'],
                '$reportOnly' => $config['report_only'],
            ])
            ->tag('kernel.event_subscriber');

        if (class_exists(AbstractExtension::class)) {
            $container->services()
                ->set('csp.twig_extension', CspExtension::class)
                ->args([
                    '$nonceGenerator' => $builder->getDefinition('csp.nonce_generator'),
                ])
                ->tag('twig.extension');
        }
    }
}
