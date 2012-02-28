<?php

abstract class Helper_router extends Helper {
	function __construct($args='') {
		// If we only receive one argument and it is scalar, force it to array form
		if (is_scalar($args)) {
			// ...and assume the scalar value is our path
			$args = array('path' => $args);	
		}
		parent::__construct($args);

		// Get the root from the config
		$CFG = Load::CFG();
		$root = $CFG['root'];

		// Maintenance mode changes the routes that get loaded
		if (!empty($CFG['maintenance_mode'])) {
			// ...but not if we have special permissions
			$acl = Load::ACL();
			// We have to load the route model to prevent a loop
			$context = Load::Model('route_static','/');
			if (!$acl->req('all',array('maintenance_mode','sysadmin'),$context)) {
				$root = $CFG['maintenance_root'];
				$this->static_routes = $CFG['maintenance_routes'];
			}
		} // End maintenance mode

		// Attempt to find a route based on the path
		$route = $this->findRoute();
		// If we can't find a route, use the root/config values
		if (!$route) {
			$args = preg_split('#/#',$this->path,-1,PREG_SPLIT_NO_EMPTY);
			$route = $root;
			$route['current_path'] = '';
			if (isset($route['args']) && is_array($route['args'])) {
				$route['args'] = array_merge($route['args'],$args);
			} else {
				$route['args'] = $args;
			}
			$route['route'] = Load::Model('route_static','/');
		}
		$this->assignVars($route);
	}

	function getArgs() {
		if (!empty($this->args)) {
			return $this->args;
		}
		return array();
	}

	function getCurrentPath($absolute=TRUE, $args=FALSE) {
		if ($absolute) {
			$CFG = Load::CFG();
			$root = $CFG['wwwroot'];
		} else {
			$root = '';
		}
		$current = $root;
		if (!empty($this->current_path)) {
			$current .= '/'.$this->current_path;
		}
		if ($args) {
			$current .= '/'.implode('/',$this->args);
		}
		return $current;
	}

	function getParentPath($absolute=TRUE) {
		if ($absolute) {
			$CFG = Load::CFG();
			$root = $CFG['wwwroot'];
		} else {
			$root = '';
		}
		$parent = $root;
		if(!empty($this->parent_path)) {
			$parent .= '/'.$this->parent_path;
		}
		return $parent;
	}

	function getRootPath($absolute=TRUE) {
		if ($absolute) {
			$CFG = Load::CFG();
			return $CFG['wwwroot'];
		} else {
			return '';
		}
	}

	function getSitePath($absolute=TRUE) {
		if (!empty($this->site_path) && strpos($this->site_path,'~')===FALSE) {
			return $this->getRootPath($absolute).'/'.$this->site_path;
		} else {
			return $this->getRootPath($absolute);
		}
	}

	function getPathByInstance($controller,$id=NULL,$absolute=TRUE) {
		if (empty($controller)) { return false; }
		if (!empty($id) && !is_numeric($id)) {
			$action = $id; $id = NULL;
		} else { $action = NULL; };
		$routes = $routes = $this->getStaticRoutes();
		foreach ($routes as $key => $route) {
			if ($controller == $route['controller']) {
				if (preg_match('/(\[[^]]+\]|\*)/',$key)) { continue; }
				if ((!empty($action) || !empty($route['action']))
					&& (empty($action) || empty($route['action'])
						|| $action!=$route['action']
					)
				) { continue; }
				if ((!empty($id) || !empty($route['instance_id']))
					&& (empty($id) || empty($route['instance_id'])
						|| $id!=$route['instance_id']
					)
				) { continue; }
				return $this->getRootPath($absolute)."/$key";
			}
		}
		return false;
	}

	function getRoute() {
		return $this->route;
	}

	function resolvePath($url,$absolute=TRUE) {
		if ($absolute && preg_match('#^(/.*)#',$url,$match)) {
			return $this->getRootPath().$match[1];
		} elseif (preg_match('#^~(/.*|)#',$url,$match)) {
			return $this->getSitePath($absolute).$match[1];
		} elseif (preg_match('#^\.\.(/.*|)#',$url,$match)) {
			return $this->getParentPath($absolute).$match[1];
		} elseif (preg_match('#^\.(/.*|)#',$url,$match)) {
			return $this->getCurrentPath($absolute).$match[1];
		} elseif (preg_match('#^@(\w+)(?:\:(\w+)|)(?:>(\w+|\d+)|)(/.*|)#',$url,$match)) {
			$controller = !empty($match[2]) ? array($match[1],$match[2]) : $match[1];
			$args = $match[4];
			$result = $this->getPathByInstance($controller,$match[3],$absolute);
			if (empty($result) && !empty($match[3])) {
				$result = $this->getPathByInstance($controller,NULL,$absolute);
				$args = '/'.$match[3].$args;
			}
			if (empty($result)) { return false; }
			return $result.$args;
		} elseif (strpos($url,':')===FALSE) {
			return $this->getCurrentPath($absolute)."/$url";
		} else {
			return $url;
		}
	}
	
	function getContext() {
		return $this->route;
	}

	function getController() {
		if (empty($this->controller)) { Load::Error('404'); }
		$args = !empty($this->args) ? $this->args : NULL;
		$controller = Load::Controller($this->controller,$args);
		$controller->router = $this;
		$vars = array();
		if (isset($this->action)) { $vars['action'] = $this->action; }
		if (isset($this->instance_id)) { $vars['id'] = $this->instance_id; }
		$controller->assignVars($vars);
		return $controller;
	}

	protected function findRoute() {
		$routes = $this->getStaticRoutes();
		$route = array();
		if (!empty($this->path)) {
			foreach($routes as $key => $route) {
				$rpreg = preg_replace(
					array('#/?\[(num|id)\]/?#','#/?\[word\]/?#','#/?\[tag\]/?#','#/\*/?$#','#\*/?#','#/$#','#^/#'),
					array('/(\d+)/','/([a-zA-Z]+)/','/([\w-]+)/','(/.+)?','([^\/])/','',''),$key);
				if (preg_match("@^/?{$rpreg}(/.*|)$@",$this->path,$matches)) {
					$route['pattern'] = $key;
					break;
				}
			}
		}
		if (empty($matches)) {
			return false;
		}
		if (!isset($route['current_path'])) {
			$pattr = preg_split('#/#',preg_replace('#/((\[[^\]]\]|\*)/?)$#','/',
				$route['pattern']),-1,PREG_SPLIT_NO_EMPTY);
			$pathr = preg_split('#/#',$this->path,-1,PREG_SPLIT_NO_EMPTY);
			$route['current_path'] = implode('/',array_slice($pathr,0,sizeof($pattr)));
		}
		if (!isset($route['parent_path'])) {
			$route['parent_path'] = implode('/',array_slice(explode('/',$route['current_path']),0,-1));
		}

		array_shift($matches);
		if (empty($matches[0])) { array_shift($matches); }
		if (!empty($matches)) {
			if (strpos($matches[sizeof($matches)-1],'/')!==FALSE) {
				$remaining_args = array_pop($matches);
				$remaining_args = preg_split('#/#',$remaining_args,-1,PREG_SPLIT_NO_EMPTY);
				$matches = array_merge($matches,$remaining_args);
			}
		}
		$route['args'] = $matches;
		$route['route'] = Load::Model('route_static','/'.$route['current_path']);
		return $route;
	}

	protected function getStaticRoutes() {
		$CFG = Load::CFG();
		$routes = (array)@$this->static_routes;
		if (empty($routes)) {
			$hooks = Load::Hooks();
			$routes = array_merge($CFG['static_routes'],$hooks->getStaticRoutes());
		}
		krsort($routes);
		return $routes;
	}
}
