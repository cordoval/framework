<?php

namespace Pagekit\Component\Session;

use Pagekit\Framework\Application;
use Pagekit\Framework\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class SessionServiceProvider implements ServiceProviderInterface
{
    protected $app;

    public function register(Application $app)
    {
        $this->app = $app;

        $app['session'] = function($app) {
            $session = new Session($app['session.storage']);
            $session->registerBag($app['message']);
            return $session;
        };

        $app['message'] = function() {
            return new Message;
        };

        $app['session.storage'] = function($app) {

            switch ($app['config']['session.storage']) {

                case 'database':

                    $handler = new Handler\DatabaseSessionHandler($app['db'], $app['config']['session.table']);
                    $storage = new NativeSessionStorage($app['session.options'], $handler);

                    break;

                case 'array':

                    $storage = new MockArraySessionStorage;
                    $app['session.test'] = true;

                    break;

                default:

                    $handler = new NativeFileSessionHandler($app['config']['session.files']);
                    $storage = new NativeSessionStorage($app['session.options'], $handler);

                    break;
            }

            return $storage;
        };

        $app['session.options'] = function($app) {

            $options = $app['config']['session'];

            if (isset($options['cookie'])) {

                foreach ($options['cookie'] as $name => $value) {
                    $options[$name == 'name' ? 'name' : 'cookie_'.$name] = $value;
                }

                unset($options['cookie']);
            }

            if (isset($options['lifetime']) && !isset($options['gc_maxlifetime'])) {
                $options['gc_maxlifetime'] = $options['lifetime'];
            }

            return $options;
        };

        $app['session.test'] = false;
    }

    public function boot(Application $app)
    {
        $app['events']->addListener(KernelEvents::REQUEST, array($this, 'onEarlyKernelRequest'), 128);

        if ($app['session.test']) {
            $app['events']->addListener(KernelEvents::REQUEST, array($this, 'onKernelRequest'), 192);
            $app['events']->addListener(KernelEvents::RESPONSE, array($this, 'onKernelResponse'), -128);
        }
    }

    public function onEarlyKernelRequest(GetResponseEvent $event)
    {
        if (!isset($this->app['session.options']['cookie_path'])) {
            $this->app['session.storage']->setOptions(array('cookie_path' => $event->getRequest()->getBasePath()));
        }

        $event->getRequest()->setSession($this->app['session']);
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            return;
        }

        // bootstrap the session
        if (!isset($this->app['session'])) {
            return;
        }

        $session = $this->app['session'];
        $cookies = $event->getRequest()->cookies;

        if ($cookies->has($session->getName())) {
            $session->setId($cookies->get($session->getName()));
        } else {
            $session->migrate(false);
        }
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            return;
        }

        $session = $event->getRequest()->getSession();
        if ($session && $session->isStarted()) {
            $session->save();

            $params = session_get_cookie_params();

            $event->getResponse()->headers->setCookie(new Cookie($session->getName(), $session->getId(), 0 === $params['lifetime'] ? 0 : time() + $params['lifetime'], $params['path'], $params['domain'], $params['secure'], $params['httponly']));
        }
    }
}
