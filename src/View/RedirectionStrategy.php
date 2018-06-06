<?php

/**
 * @author Rob Allen <rob@akrabat.com>
 */

namespace JUser\View;

use BjyAuthorize\Exception\UnAuthorizedException;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zend\Http\Response;
use Zend\Mvc\Application;
use Zend\Mvc\MvcEvent;
use BjyAuthorize\Guard\Route;
use BjyAuthorize\Guard\Controller;
use BjyAuthorize\View\UnauthorizedStrategy;

/**
 * Dispatch error handler, catches exceptions related with authorization and
 * redirects the user agent to a configured location
 *
 * @author Ben Youngblood <bx.youngblood@gmail.com>
 * @author Marco Pivetta  <ocramius@gmail.com>
 */
class RedirectionStrategy extends UnauthorizedStrategy implements ListenerAggregateInterface
{
    /**
     * @var string route to be used to handle redirects
     */
    protected $redirectRoute = 'zfcuser/login';

    /**
     * @var string URI to be used to handle redirects
     */
    protected $redirectUri;

    /**
     * @var \Zend\Stdlib\CallbackHandler[]
     */
    protected $listeners = array();

    public function __construct()
    {
        parent::__construct('error/403');
    }

    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(MvcEvent::EVENT_DISPATCH_ERROR, array($this, 'onDispatchError'), -5000);
    }

    /**
     * {@inheritDoc}
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->listeners as $index => $listener) {
            if ($events->detach($listener)) {
                unset($this->listeners[$index]);
            }
        }
    }

    /**
     * Handles redirects in case of dispatch errors caused by unauthorized access
     *
     * @param \Zend\Mvc\MvcEvent $event
     */
    public function onDispatchError(MvcEvent $event)
    {
        // Do nothing if the result is a response object
        $result     = $event->getResult();
        $routeMatch = $event->getRouteMatch();
        $response   = $event->getResponse();
        $router     = $event->getRouter();
        $error      = $event->getError();
        $url        = $this->redirectUri;
        $identity   = $event->getApplication()->getServiceManager()->
            get('zfcuser_user_service')->getAuthService()->getIdentity();

        if ($result instanceof Response
            || ! $routeMatch
            || ($response && ! $response instanceof Response)
            || ! (
                Route::ERROR === $error
                || Controller::ERROR === $error
                || (
                    Application::ERROR_EXCEPTION === $error
                    && ($event->getParam('exception') instanceof UnAuthorizedException)
                )
            )
        ) {
            return;
        }

        if ($identity) { //if we have an identity, don't redirect to login)
            return parent::onDispatchError($event);
        }

        if (null === $url) {
            $url = $router->assemble(array(), array('name' => $this->redirectRoute));
        }

        // Work out where were we trying to get to
        $options['name'] = $routeMatch->getMatchedRouteName();
        $redirect = $router->assemble($routeMatch->getParams(), $options);

        $response = $response ?: new Response();

        $response->getHeaders()->addHeaderLine('Location', $url . '?redirect=' . $redirect);
        $response->setStatusCode(302);

        $event->setResponse($response);
    }

    /**
     * @param string $redirectRoute
     */
    public function setRedirectRoute($redirectRoute)
    {
        $this->redirectRoute = (string) $redirectRoute;
    }

    /**
     * @param string|null $redirectUri
     */
    public function setRedirectUri($redirectUri)
    {
        $this->redirectUri = $redirectUri ? (string) $redirectUri : null;
    }
}