<?php
/**
 * Created by PhpStorm.
 * User: Westopher
 * Date: 5/31/2015
 * Time: 1:05 PM
 */

namespace RateLimiter\Routing\Filter;

use Cake\Event\Event;
use Cake\Routing\DispatcherFilter;
use Cake\Collection\Collection;

/**
 * Class RateLimiterFilter
 *
 * Usage:
 * DispatchFilter::add('RateLimiter.RateLimiter, [
 *     'routes' => [
 *         ['controller' => $c_name, 'action' => $action],
 *         [
 *             'controller' => $c_name,
 *             'action' => $action,
 *             'period' => '30 minutes',
 *             'limit' => 3
 *         ]
 *     ],
 *     'period' => '1 hour',
 *     'limit' => 5
 * ]);
 *
 * SessionStorage:
 * 'RateLimiter' => [
 *     'controller_1' => [
 *         'action_1' => [
 *            'last_accessed' => 'TIMESTAMP VALUE',
 *            'pings' => 5
 *        ]
 *    ]
 * ]
 *
 * @package RateLimiter\Routing\Filter
 */
class RateLimiterFilter extends DispatchFilter
{
    public $routes = null;
    public $controller = null;
    public $action = null;
    public $default_limit = null;
    public $default_period = null;
    public $matched_route = null;
    public $period = null;
    public $limit = null;
    public $request = null;
    public $last_accessed = null;
    public $pings = null;
    public $over_limit = false;

    /**
     * @param Event $event
     * @throws \Exception
     */
    public function beforeFilter(Event $event)
    {
        // Get default period and limit if set
        $this->default_limit = $this->config('limit');
        $this->default_period = $this->config('period');
        $routes = $this->config('routes');

        // Build a new collection to retrieve route from based on $c/$a combo
        if (!empty($routes) && is_array($routes)) {
            $this->routes = new Collection($routes);
        }

        // Retrieve our request, controller, and action
        $this->request = $event->data['request'];
        $this->controller = $this->request->param('controller');
        $this->action = $this->request->param('action');

        // End flow if route is not handled
        if (!$this->_routeIsHandled()) {
            return;
        }

        $this->_setPeriod();
        $this->_setLimit();
        $this->_processSession();
        if ($this->_isOverRateLimit()) {
            $event->stopPropagation();
        }
        $this->_postCheckSessionSave();
    }

    protected function _routeIsHandled()
    {
        $this->matched_route = $this->routes->firstMatch([
            'controller' => $this->controller,
            'action' => $this->action
        ]);
        return $this->matched_route;
    }

    protected function _setPeriod()
    {
        // Set our period if scoped to controller/action, else set to default period
        if (isset($this->matched_route['period'])) {
            $this->period = $this->matched_route['period'];
        } elseif ($this->default_period) {
            $this->period = $this->default_period;
        } else {
            throw new \Exception(__('You must specify a period to rate limit requests on.'));
        }
    }

    protected function _setLimit()
    {
        // Set our period if scoped to controller/action, else set to default limit
        if (isset($this->matched_route['limit'])) {
            $this->limit = $this->matched_route['limit'];
        } elseif ($this->default_limit) {
            $this->limit = $this->default_limit;
        } else {
            throw new \Exception(__('You must specify a limit to rate limit requests over.'));
        }
    }

    protected function _processSession()
    {
        $session_str = 'RateLimiter' . '.' . $this->controller . '.' . $this->action;
        $rate_limited_session = $this->request->session()->read($session_str);

        if (!$rate_limited_session) {
            $rate_limited_session = [
                'last_accessed' => new Time('now'),
                'pings' => 1
            ];
            $this->request->session()->write($session_str, $rate_limited_session);
        }

        $this->last_accessed = $rate_limited_session['last_accessed'];
        $this->pings = $rate_limited_session['pings'];
    }

    protected function _isOverRateLimit()
    {
        if ($this->last_accessed->wasWithinLast($this->period)) {
            // We have pinged within the rate limit time period
            $this->pings++;
            if ($this->pings > $this->limit) {
                // We are over our rate limited
                $this->over_limit = true;
                return true;
            } else {
                return false;
            }
        } else {
            $this->last_accessed = new Time('now');
            $this->pings++;
            return false;
        }
    }

    protected function _postCheckSessionSave()
    {
        $session_str = 'RateLimiter' . '.' . $this->controller . '.' . $this->action;
        $this->request->session()->write($session_str, [
            'last_accessed' => $this->last_accessed,
            'pings' => $this->pings
        ]);
    }
}