<?php

declare(strict_types=1);

namespace App\Core;

use App\Contracts\EventDispatcherInterface;
use App\Database\Database;
use App\Database\Migrator;
use App\Events\EventDispatcher;
use App\Middleware\CsrfMiddleware;
use App\Security\Csrf;
use App\Session\Session;
use App\Translation\Translator;

/**
 * Chef d'orchestre du noyau : configuration, services, modules puis requête HTTP.
 */
final class Application
{
    private static ?self $instance = null;

    private Container $container;
    private Config $config;
    private EventDispatcherInterface $events;
    private ModuleManager $modules;
    private bool $booted = false;

    public function __construct(private readonly string $basePath)
    {
        self::$instance = $this;
        $this->container = new Container();
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        Env::load($this->basePath . '/.env');
        $this->config = new Config($this->basePath . '/config');
        $this->container->instance(Config::class, $this->config);

        date_default_timezone_set((string) $this->config->get('app.timezone', 'Africa/Tunis'));

        $errorHandler = new ErrorHandler(
            (bool) $this->config->get('app.debug', false),
            $this->basePath . '/storage/logs'
        );
        $errorHandler->register();
        $this->container->instance(ErrorHandler::class, $errorHandler);

        $this->events = new EventDispatcher();
        $this->container->instance(EventDispatcher::class, $this->events);
        $this->container->instance(EventDispatcherInterface::class, $this->events);

        $this->container->singleton(Database::class, function (Container $container): Database {
            /** @var array<string, mixed> $settings */
            $settings = $container->get(Config::class)->get('database', []);
            return new Database($settings);
        });

        $this->container->singleton(Session::class, function (Container $container): Session {
            /** @var array<string, mixed> $settings */
            $settings = $container->get(Config::class)->get('session', []);
            if (($settings['save_path'] ?? '') === '') {
                $settings['save_path'] = $this->basePath . '/storage/sessions';
            }
            return new Session($settings);
        });

        $this->container->singleton(Csrf::class, fn (): Csrf => new Csrf(
            $this->container->get(Session::class)
        ));

        $this->container->singleton(View::class, fn (): View => new View($this->basePath . '/app/Views'));

        $this->container->singleton(Translator::class, fn (): Translator => new Translator(
            $this->basePath . '/lang',
            (string) $this->config->get('app.locale', 'fr'),
            (string) $this->config->get('app.fallback_locale', 'fr')
        ));

        $this->modules = new ModuleManager($this, $this->basePath . '/modules');
        $this->container->instance(ModuleManager::class, $this->modules);

        $this->container->singleton(Migrator::class, fn (): Migrator => new Migrator(
            $this->container->get(Database::class),
            $this->basePath . '/database/migrations',
            $this->modules->migrationPaths()
        ));

        $this->modules->discover();
        $this->modules->boot();

        $this->booted = true;
        $this->events->dispatch('app.booted', ['application' => $this]);
    }

    public function run(): void
    {
        $this->boot();

        /** @var Session $session */
        $session = $this->container->get(Session::class);
        $session->start();

        $router = new Router($this->container);
        $router->addGlobalMiddleware(CsrfMiddleware::class);
        $this->container->instance(Router::class, $router);
        $this->modules->loadRoutes($router);

        $request = Request::capture();
        $response = $router->dispatch($request);

        $this->events->dispatch('request.handled', [
            'request' => $request,
            'response' => $response,
        ]);

        $response->send();
    }

    public function make(string $abstract): object
    {
        return $this->container->get($abstract);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function events(): EventDispatcherInterface
    {
        return $this->events;
    }

    public function modules(): ModuleManager
    {
        return $this->modules;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }
}
