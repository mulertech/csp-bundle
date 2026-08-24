<?php

declare(strict_types=1);

namespace MulerTech\CspBundle;

use MulerTech\CspBundle\Controller\CspReportController;
use MulerTech\CspBundle\EventSubscriber\CspHeaderSubscriber;
use MulerTech\CspBundle\Report\CspReportParser;
use MulerTech\CspBundle\Service\CspHeaderBuilder;
use MulerTech\CspBundle\Twig\CspExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
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
                ->arrayNode('report_only_directives')
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
        /** @var array<string, array<int|string, string>|bool> $candidateOverrides */
        $candidateOverrides = $config['report_only_directives'] ?? [];

        if ([] !== $candidateOverrides && ($config['report_only'] ?? false)) {
            throw new InvalidConfigurationException('mulertech_csp: "report_only_directives" describes a candidate policy observed next to the enforced one, so it cannot be combined with "report_only: true", which already sends the whole policy as report-only. Keep "report_only: false" to compare an enforced policy with a candidate, or drop "report_only_directives" to observe a single policy.');
        }

        $directives = $this->applyOverrides(self::DEFAULT_DIRECTIVES, $userDirectives);
        $candidateDirectives = [] === $candidateOverrides
            ? []
            : $this->applyOverrides($directives, $candidateOverrides);

        $container->services()
            ->set('mulertech_csp.nonce_generator', CspNonceGenerator::class)
            ->tag('kernel.reset', ['method' => 'reset'])
            ->alias(CspNonceGenerator::class, 'mulertech_csp.nonce_generator');

        $container->services()
            ->set('mulertech_csp.header_builder', CspHeaderBuilder::class)
            ->args([
                '$nonceGenerator' => new Reference('mulertech_csp.nonce_generator'),
                '$directives' => $directives,
                '$alwaysAdd' => $config['always_add'] ?? [],
                '$reportConfig' => $config['report'] ?? ['url' => null, 'route' => null, 'route_params' => [], 'chance' => 100],
                // Extensions are loaded against isolated containers, where the router is never visible yet:
                // the reference must stay optional and be resolved when the real container compiles.
                '$urlGenerator' => new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        $container->services()
            ->set('mulertech_csp.header_subscriber', CspHeaderSubscriber::class)
            ->args([
                '$builder' => new Reference('mulertech_csp.header_builder'),
                '$dispatcher' => new Reference('event_dispatcher'),
                '$reportOnly' => $config['report_only'] ?? false,
                '$candidateDirectives' => $candidateDirectives,
            ])
            ->tag('kernel.event_subscriber');

        $container->services()
            ->set('mulertech_csp.report_parser', CspReportParser::class)
            ->alias(CspReportParser::class, 'mulertech_csp.report_parser');

        // The service id is the class name because that is what a route referencing the
        // controller by FQCN resolves against. No route is declared here: opening a public
        // unauthenticated endpoint stays an explicit gesture of the application.
        $container->services()
            ->set(CspReportController::class)
            ->args([
                '$parser' => new Reference('mulertech_csp.report_parser'),
                '$dispatcher' => new Reference('event_dispatcher'),
            ])
            ->tag('controller.service_arguments');

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
     * @param array<string, list<string>|bool>              $base
     * @param array<string, array<int|string, string>|bool> $overrides
     *
     * @return array<string, list<string>|bool>
     */
    private function applyOverrides(array $base, array $overrides): array
    {
        foreach ($overrides as $name => $value) {
            $base[$name] = is_array($value) ? array_values($value) : $value;
        }

        return $base;
    }
}
