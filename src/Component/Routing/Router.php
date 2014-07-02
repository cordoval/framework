<?php

namespace Pagekit\Component\Routing;

use Pagekit\Component\Routing\Controller\ControllerCollection;
use Pagekit\Component\Routing\Generator\UrlGeneratorDumper;
use Pagekit\Component\Routing\RequestContext as ExtendedRequestContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\Matcher\Dumper\PhpMatcherDumper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

class Router implements RouterInterface, RequestMatcherInterface
{
    /**
     * @var HttpKernelInterface
     */
    protected $kernel;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var RequestContext
     */
    protected $context;

    /**
     * @var UrlMatcher
     */
    protected $matcher;

    /**
     * @var UrlGenerator
     */
    protected $generator;

    /**
     * @var RouteCollection
     */
    protected $routes;

    /**
     * @var AliasCollection
     */
    protected $aliases;

    /**
     * @var ControllerCollection
     */
    protected $controllers;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var string
     */
    protected $cacheKey;

    /**
     * Constructor.
     *
     * @param HttpKernelInterface  $kernel
     * @param ControllerCollection $controllers
     * @param array                $options
     */
    public function __construct(HttpKernelInterface $kernel, ControllerCollection $controllers, array $options = array())
    {
        $this->kernel      = $kernel;
        $this->controllers = $controllers;
        $this->aliases     = new AliasCollection;
        $this->context     = new ExtendedRequestContext;

        $this->options = array_replace(array(
            'cache'     => null,
            'matcher'   => 'Pagekit\Component\Routing\Matcher\UrlMatcher',
            'generator' => 'Pagekit\Component\Routing\Generator\UrlGenerator'
        ), $options);
    }

    /**
     * Get the current request.
     *
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * {@inheritdoc}
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * {@inheritdoc}
     */
    public function setContext(RequestContext $context)
    {
        $this->context = $context;
    }

    /**
     * Gets a route by name.
     *
     * @param string $name The route name
     *
     * @return Route|null A Route instance or null when not found
     */
    public function getRoute($name)
    {
        return $this->getRouteCollection()->get($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getRouteCollection()
    {
        if (!$this->routes) {
            $this->routes = $this->controllers->getRoutes();
            $this->routes->addCollection($this->aliases->getRoutes($this->routes));
        }

        return $this->routes;
    }

    /**
     * Gets the URL matcher instance.
     *
     * @return UrlMatcher
     */
    public function getMatcher()
    {
        if (!$this->matcher) {
            if ($this->options['cache']) {

                $class   = sprintf('UrlMatcher%s', $this->getCacheKey());
                $cache   = sprintf('%s/%s.matcher.cache', $this->options['cache'], $this->getCacheKey());
                $options = array('class' => $class, 'base_class' => $this->options['matcher']);

                if (!file_exists($cache)) {
                    file_put_contents($cache, (new PhpMatcherDumper($this->getRouteCollection()))->dump($options));
                }

                require_once($cache);

                $this->matcher = new $class($this->context);
                $this->matcher->setAliases($this->aliases);

            } else {

                $class = $this->options['matcher'];

                $this->matcher = new $class($this->getRouteCollection(), $this->context);
                $this->matcher->setAliases($this->aliases);
            }
        }

        return $this->matcher;
    }

    /**
     * Gets the UrlGenerator instance associated with this Router.
     *
     * @return UrlGenerator
     */
    public function getGenerator()
    {
        if (!$this->generator) {
            if ($this->options['cache']) {

                $class   = sprintf('UrlGenerator%s', $this->getCacheKey());
                $cache   = sprintf('%s/%s.generator.cache', $this->options['cache'], $this->getCacheKey());
                $options = array('class' => $class, 'base_class' => $this->options['generator']);

                if (!file_exists($cache)) {
                    file_put_contents($cache, (new UrlGeneratorDumper($this->getRouteCollection()))->dump($options));
                }

                require_once($cache);

                $this->generator = new $class($this->context);
                $this->generator->setAliases($this->aliases);

            } else {

                $class = $this->options['generator'];

                $this->generator = new $class($this->getRouteCollection(), $this->context);
                $this->generator->setAliases($this->aliases);
            }
        }

        return $this->generator;
    }

    /**
     * Gets an alias.
     *
     * @param  string $name
     * @return array
     */
    public function getAlias($name)
    {
        return $this->aliases->get($name);
    }

    /**
     * Adds an alias.
     *
     * @param string   $path
     * @param string   $name
     * @param callable $inbound
     * @param callable $outbound
     */
    public function addAlias($path, $name, callable $inbound = null, callable $outbound = null)
    {
        $this->aliases->add($path, $name, $inbound, $outbound);
    }

    /**
     * Aborts the current request by sending a proper HTTP error.
     *
     * @param int $code
     * @param string $message
     * @param array $headers
     * @throws HttpException
     * @throws NotFoundHttpException
     */
    public function abort($code, $message = '', array $headers = array())
    {
        if ($code == 404) {
            throw new NotFoundHttpException($message);
        } else {
            throw new HttpException($code, $message, null, $headers);
        }
    }

    /**
     * Terminates a request/response cycle.
     *
     * @param Request  $request
     * @param Response $response
     */
    public function terminate(Request $request, Response $response)
    {
        $this->kernel->terminate($request, $response);
    }

    /**
     * Handles a Request to convert it to a Response.
     *
     * @param  Request $request
     * @param  int     $type
     * @param  bool    $catch
     * @return Response
     */
    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        $this->request = $request;
        $this->context->fromRequest($request);

        return $this->kernel->handle($request, $type, $catch);
    }

    /**
     * Handles a Subrequest to call an action internally.
     *
     * @param  string $route
     * @param  array  $query
     * @param  array  $request
     * @param  array  $attributes
     * @throws \RuntimeException
     * @return Response
     */
    public function call($route, array $query = null, array $request = null, array $attributes = null)
    {
        if (empty($this->request)) {
            throw new \RuntimeException('No Request set.');
        }

        $defaults = $this->getGenerator()->getDefaults($route);

        $attributes = array_replace($defaults, (array) $attributes);
        $attributes['_route'] = $route;

        return $this->kernel->handle($this->request->duplicate($query, $request, $attributes), HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * {@inheritdoc}
     */
    public function match($pathinfo)
    {
        return $this->getMatcher()->match($pathinfo);
    }

    /**
     * {@inheritdoc}
     */
    public function matchRequest(Request $request)
    {
        return $this->getMatcher()->matchRequest($request);
    }

    /**
     * {@inheritdoc}
     */
    public function generate($name, $parameters = array(), $referenceType = self::ABSOLUTE_PATH)
    {
        return $this->getGenerator()->generate($name, $parameters, $referenceType);
    }

    /**
     * @return string
     */
    protected function getCacheKey()
    {
        if (!$this->cacheKey) {

            $resources = $this->controllers->getResources();
            $resources['aliases'] = $this->aliases->getResources();

            $this->cacheKey = sha1(json_encode($resources));
        }

        return $this->cacheKey;
    }
}
