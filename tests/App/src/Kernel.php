<?php

namespace App;

use App\Command\CloseInvoicesCommand;
use App\Controller\OrderController;
use App\MessageHandler\SendInvoicesHandler;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Slowpoke\Symfony\SlowpokeBundle;
use Slowpoke\Symfony\Tests\Fixtures\FakeSender;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A real Symfony app for the integration tests. Variants:
 *   test  the bundle with its defaults, traces go to a FakeSender
 *   agent the bundle configured by the shipped config/packages/slowpoke.yaml, traces go over HTTP
 *   off   slowpoke.enabled: false written in the configuration
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** @var string */
    private $variant;

    public function __construct(string $variant)
    {
        $this->variant = $variant;
        parent::__construct($variant, false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new DoctrineBundle(), new SlowpokeBundle()];
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        // One cache per dependency set: bin/test runs several Symfony versions side by side. The
        // name is a hash on purpose - with the plain directory name the compiled Twig templates of
        // a default install landed under ".../var/cache/vendor/", and the origin finder skips
        // everything inside a vendor directory, so no query ever pointed at its template again.
        return $this->getProjectDir() . '/var/cache/' . substr(sha1(getenv('COMPOSER_VENDOR_DIR') ?: 'default'), 0, 10) . '/' . $this->variant;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $framework = [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'router' => ['utf8' => true],
            'messenger' => [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [Message\SendInvoices::class => 'async'],
            ],
        ];
        if (self::VERSION_ID >= 60400) {
            $framework['handle_all_throwables'] = true;
            $framework['php_errors'] = ['log' => true];
        }
        $c->extension('framework', $framework);
        $c->extension('twig', ['default_path' => '%kernel.project_dir%/templates', 'strict_variables' => true]);
        $c->extension('doctrine', ['dbal' => ['driver' => 'pdo_sqlite', 'memory' => true]]);

        $services = $c->services();
        $services->set('logger', \Psr\Log\NullLogger::class); // expected 404 and 500 stay out of the test output
        $services->set(OrderController::class)->autowire()->public()->tag('controller.service_arguments');
        $services->set(SendInvoicesHandler::class)->autowire()->tag('messenger.message_handler');
        $services->set(CloseInvoicesCommand::class)->autowire()->tag('console.command');
        $services->alias('test.db', 'doctrine.dbal.default_connection')->public();
        $services->alias('test.bus', 'messenger.default_bus')->public();
        $services->alias('test.transport', 'messenger.transport.async')->public();

        if ($this->variant === 'test') {
            $services->set('slowpoke.sender', FakeSender::class)->public();
            // Only here: with slowpoke.enabled false the bundle registers nothing at all, and an
            // alias to a service that does not exist stops the container from compiling.
            $services->alias('test.route_templates', 'slowpoke.route_templates')->public();
        }
        if ($this->variant === 'agent') {
            $c->import(dirname(__DIR__, 3) . '/config/packages/slowpoke.yaml');
        }
        if ($this->variant === 'off') {
            $c->extension('slowpoke', ['enabled' => false]);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('order_index', '/orders')->methods(['GET'])->controller([OrderController::class, 'index']);
        $routes->add('order_report', '/orders/report')->controller([OrderController::class, 'report']);
        $routes->add('order_show', '/orders/{id<\d+>}')->controller([OrderController::class, 'show']);
        $routes->add('order_forward', '/orders/{id}/forward')->controller([OrderController::class, 'forward']);
        $routes->add('order_create', '/orders')->methods(['POST'])->controller([OrderController::class, 'create']);
        $routes->add('order_purge', '/orders/purge')->methods(['DELETE'])->controller([OrderController::class, 'purge']);
        $routes->add('customer_lookup', '/customers/lookup')->controller([OrderController::class, 'lookup']);
        $routes->add('broken', '/broken')->controller([OrderController::class, 'broken']);
        $routes->add('home', '/')->controller([OrderController::class, 'home']);
    }
}
