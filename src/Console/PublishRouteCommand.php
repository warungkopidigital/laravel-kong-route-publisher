<?php

namespace KWRI\KongPublisher\Console;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Console\Command;
use Illuminate\Routing\Controller;
use Symfony\Component\Console\Input\InputOption;
use Ignittion\Kong\Kong;
use Illuminate\Filesystem\Filesystem;

class PublishRouteCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'kong:publish-route';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish all registered routes';

    /**
     * The router instance.
     *
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * An array of all the registered routes.
     *
     * @var \Illuminate\Routing\RouteCollection
     */
    protected $routes;


    private $client;

    private $filesystem;

    /**
     * Create a new route command instance.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function __construct(Router $router)
    {
        parent::__construct();

        $this->router = $router;
        $this->routes = $router->getRoutes();
        $kongUrl = getenv('KONG_URL') ?: 'http://localhost';
        $kongPort = getenv('KONG_PORT') ?: 8001;

        $this->client = new Kong(
            $kongUrl,
            $kongPort
        );

        $this->filesystem = app(Filesystem::class);
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        if (count($this->routes) == 0) {
            return $this->error("Your application doesn't have any routes.");
        }

        $this->publishRoutes($this->getRoutes());
    }

    /**
     * Compile the routes into a displayable format.
     *
     * @return array
     */
    protected function getRoutes()
    {
        $results = [];

        foreach ($this->routes as $route) {
            $results[] = $this->getRouteInformation($route);
        }

        if ($sort = $this->option('sort')) {
            $results = Arr::sort($results, function ($value) use ($sort) {
                return $value[$sort];
            });
        }

        if ($this->option('reverse')) {
            $results = array_reverse($results);
        }

        return array_filter($results);
    }

    /**
     * Get the route information for a given route.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array
     */
    protected function getRouteInformation(Route $route)
    {
        return $this->filterRoute([
            'host'   => $route->domain(),
            'method' => $route->methods(),
            'uri'    => $route->uri(),
            'name'   => $route->getName(),
            'action' => $route->getActionName(),
            'middleware' => $this->getMiddleware($route),
        ]);
    }

    public function isValidRoute($route)
    {
        return $route['uri'] !== '/' && (strpos($route['uri'], '{') === false);
    }

    /**
     * publish the route information to kong.
     *
     * @param  array  $routes
     * @return void
     */
    protected function publishRoutes(array $routes)
    {
        try {
            $nodes = $this->client->node()->get();
            $this->assertKongIsLife($nodes);
            $api = $this->client->api();
            $payloads = [];
            $invalidRoute = [];
            foreach ($routes as $route) {

                if (!$this->isValidRoute($route)) {
                    $invalidRoute[] = $route;
                    continue;
                }

                $uri = $route['uri'];
                $host = str_replace(['http://', 'https://'], '', url('/'));
                $name = str_replace('/', '.', $uri);
                $methods = implode(',', $route['method']);

                if (isset($payloads[$name])) {
                    $payloads[$name]['methods'] .= ',' . $methods;
                } else {
                    $payloads[$name] = [
                        'name' => $name,
                        'uris' => '/' . $uri,
                        'methods' => $methods,
                        'upstream_url' => url($uri),
                    ];
                }
            }
            // Create pushed log file
            $logFile = storage_path('logs');
            // Create not registered log file
            $this->filesystem->put(
                $logFile . '/invalid-kong-route-'. date('Y-m-d',time()) . '.log',
                json_encode($invalidRoute)
            );

            foreach ($payloads as $payload) {
                $response = $api->call('put', "apis", [], $payload);
                $this->filesystem->append(
                    $logFile . '/pushed-kong-route-'. date('Y-m-d',time()) . '.log',
                    json_encode($response) . "\n\n"
                );
                $this->info($payload['name'] . " published");
            }

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Check kong is life
     * @Exception \Exception  throw exception that kong is not accessbile
     */
    private function assertKongIsLife($nodes)
    {
        if ($nodes->code !== 200) {
            throw new \Exception("Kong is not accessible.");
        }
    }

    /**
     * Get before filters.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return string
     */
    protected function getMiddleware($route)
    {
        $middlewares = array_values($route->middleware());

        $actionName = $route->getActionName();

        if (! empty($actionName) && $actionName !== 'Closure') {
            $middlewares = array_merge($middlewares, $this->getControllerMiddleware($actionName));
        }

        return implode(',', $middlewares);
    }

    /**
     * Get the middleware for the given Controller@action name.
     *
     * @param  string  $actionName
     * @return array
     */
    protected function getControllerMiddleware($actionName)
    {
        Controller::setRouter($this->laravel['router']);

        $segments = explode('@', $actionName);

        return $this->getControllerMiddlewareFromInstance(
            $this->laravel->make($segments[0]), $segments[1]
        );
    }

    /**
     * Get the middlewares for the given controller instance and method.
     *
     * @param  \Illuminate\Routing\Controller  $controller
     * @param  string  $method
     * @return array
     */
    protected function getControllerMiddlewareFromInstance($controller, $method)
    {
        $middleware = $this->router->getMiddleware();

        $results = [];

        foreach ($controller->getMiddleware() as $name => $options) {
            if (! $this->methodExcludedByOptions($method, $options)) {
                $results[] = Arr::get($middleware, $name, $name);
            }
        }

        return $results;
    }

    /**
     * Determine if the given options exclude a particular method.
     *
     * @param  string  $method
     * @param  array  $options
     * @return bool
     */
    protected function methodExcludedByOptions($method, array $options)
    {
        return (! empty($options['only']) && ! in_array($method, (array) $options['only'])) ||
            (! empty($options['except']) && in_array($method, (array) $options['except']));
    }

    /**
     * Filter the route by URI and / or name.
     *
     * @param  array  $route
     * @return array|null
     */
    protected function filterRoute(array $route)
    {
        if (($this->option('name') && ! Str::contains($route['name'], $this->option('name'))) ||
             $this->option('path') && ! Str::contains($route['uri'], $this->option('path')) ||
             $this->option('method') && ! Str::contains($route['method'], $this->option('method'))) {
            return;
        }

        return $route;
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['method', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by method.'],

            ['name', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by name.'],

            ['path', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by path.'],

            ['reverse', 'r', InputOption::VALUE_NONE, 'Reverse the ordering of the routes.'],

            ['sort', null, InputOption::VALUE_OPTIONAL, 'The column (host, method, uri, name, action, middleware) to sort by.', 'uri'],
        ];
    }
}
