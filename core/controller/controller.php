<?php

/**
 * Controller.php
 * 
 * Contains the Controller class, which is the basis for all controllers in Escher.
 * @author Thom Stricklin <code@thomshouse.net>
 * @version 1.0
 * @package Escher
 */

/**
 * Controller base class
 * @package Escher
 */
class Controller extends EscherObject {
	protected $defaultAction = 'index';
	protected $defaultAllowArgs = FALSE;
	protected $args = NULL;
	protected $argPrecedesActions = FALSE;
	protected $output_type = 'php';
	protected $calledAction = NULL;
	protected $isACLRestricted = FALSE;
	protected $ACLRestrictedActions = array();
	protected $input = array();
	protected $dispatchBase = array();
	protected $modelType;
	protected $model;
	public $id;
	public $data = array();
	
	/**
	 * Constructor object.  
	 * @param array $args An array of arguments to load.
	 */
	function __construct($args=NULL) {
		parent::__construct();
		if (!is_null($args)) {
			$this->args = (array)$args;
		}

		// Convenient access for commonly used objects
		$this->headers = Load::Headers();
		$this->input = Load::Input();
		$this->session = Load::Session();
		$this->USER = Load::User();
		$this->UI = Load::UI();
	}

	function initialize($vars) {
		$this->assignVars($vars);
		if (!empty($this->modelType)) {
			$this->model = Load::Model($this->modelType,$this->id);
			$this->observeObject($this->model);
		}
	}

	function onObservation($object,$event=NULL) {
		if ($event=='save' && $object===$this->model) {
			$route = $this->router->getRoute();
			if ($route->_m()=='route_dynamic'
				&& $object->id()!=$route->instance_id
			) {
				$route->instance_id = $object->id();
				$route->save();
			}
		}
	}
	
	/**
	 * Executes the controller's behavior based on the arguments provided.  
	 * @param array $args An array of arguments to execute on.
	 */
	function execute($args=NULL) {
		// If this controller requires access, do an ACL check.
		if ($this->isACLRestricted==TRUE) {
			$acl = Load::ACL();
			if (!$acl->check()) { Load::Error('401'); }
		}

		// Clean up args
		if (is_null($args)) { $args = $this->args; }
		$args = (array)$args;

		// Determine the intended action
		// Priority 1: Action specified as property
		if (isset($this->action)) {
			$action = $this->action;

		// Priority 2: 2nd Argument in case of valid $argPrecedesActions
		} elseif (isset($args[1])
			&& ($this->argPrecedesActions===TRUE
				|| (is_array($this->argPrecedesActions)
					&& in_array($args[1],$this->argPrecedesActions)))
			&& method_exists($this,'action_'.$args[1])
			&& $args[1]!=$this->defaultAction
		) {
			$action = $args[1];
			$this->dispatchBase = array_slice($args,0,2);
			array_splice($args,1,1);

		// Priority 3: 1st Argument in case of valid manage function
		} elseif (isset($args[0]) && method_exists($this,'manage_'.$args[0])
			&& $args[0]!=$this->defaultAction
		) {
			$this->dispatchBase = array_slice($args,0,1);
			$action = array_shift($args);
			$functype = "manage";

		// Priority 4: 1st Argument in case of valid action
		} elseif (isset($args[0]) && method_exists($this,'action_'.$args[0])
			&& $args[0]!=$this->defaultAction
		) {
			$this->dispatchBase = array_slice($args,0,1);
			$action = array_shift($args);
			$functype = "action";

		// Priority 5: Default argument if valid
		} else {
			$action = $this->defaultAction;
		}

		// Don't execute default action if $args are present & not allowed
		if ($action==$this->defaultAction
			&& !$this->defaultAllowArgs
			&& !empty($args)
		) {
			return false;
		}

		// Determine whether the intended action is valid 
		if (empty($functype)) {
			if (method_exists($this,"manage_$action")) {
				$functype = "manage";
			} elseif (method_exists($this,"action_$action")) {
				$functype = "action";
			} else {
				return false;
			}
		}

		// If our action requires access, do an ACL check
		if ($functype=="manage" || in_array($action,$this->ACLRestrictedActions)) {
			$acl = Load::ACL();
			if (!$acl->check(NULL,$action)) { Load::Error('401'); }
		}

		// Record which action we are calling, and call it
		$this->calledAction = $action;
		$result = call_user_func(array(&$this,"{$functype}_{$action}"),$args);

		// Return true if result is true or data is non-empty
		return (bool)($result || !empty($this->data));
	}

	final protected function dispatch($dispatch,$display=FALSE,$args=array(),$options=array()) {
		// Split up the $dispatch string into its component parts
		if (!preg_match('#^(?:([\w/-]+)@|)(?:(\w+):|)(:?\w+)(?:>(\w+)|)(/[\w/-]+|)$#',
			$dispatch,$parts)
		) { return false; }
		list(,$base,$plugin,$controller,$action,$dispatchArgs) = $parts;
		if (strpos($controller,':')===0) {
			$hooks = Load::Hooks();
			$controller = $hooks->getDispatch(substr($controller,1));
		} elseif (!empty($plugin)) {
			$controller = array($plugin,$controller);
		}

		// Prepend $this->dispatchBase to $base
		if (!empty($this->dispatchBase)) {
			if (!empty($base)) { $base = '/'.$base; }
			$base = implode('/',$this->dispatchBase).$base;
		}

		// Explode $args to array
		if (is_string($args)) {
			$args = preg_split('#/#',$args,-1,PREG_SPLIT_NO_EMPTY);
		} elseif (!is_array($args)) {
			$args = array();
		}
		// Add args from $dispatch to array
		if (!empty($dispatchArgs)) {
			$args = array_merge(
				preg_split('#/#',$dispatchArgs,-1,PREG_SPLIT_NO_EMPTY),
				$args);
		}

		// Load the controller and begin to populate
		if (!empty($controller) && $controller = Load::Controller($controller,$args)) {
			if (!empty($action)) {
				if (is_numeric($action)) { $controller->id = $action; }
					else { $controller->action = $action; }
			}
			if (!empty($options)) { $controller->assignVars($options); }
			$controller->router = Load::Helper('router','dispatch',
				array(
					'router' => $this->router,
					'dispatch' => $dispatch,
					'base' => $base,
					'args' => $args,
				)
			);
            $router = Load::Router();
            $router->pushDispatchRouter($controller->router);
			$result = $controller->execute() || !empty($controller->data);
            $router->popDispatchRouter();
		}

        if (!empty($result)) {
            $router->pushDispatchRouter($controller->router);
            if ($display) {
                if ($output = $controller->render($controller->getCalledAction(),$controller->data,TRUE)) {
                    $headers = Load::Headers();
                    $headers->sendHTTP();
                    exit($output);
                }
                $router->popDispatchRouter();
                Load::Error('404');
            } else {
                $result = $controller->render($controller->getCalledAction(),$controller->data);
                $router->popDispatchRouter();
                return $result;
            }
        }
	}
	
	// Display the specified view for this controller with the data provided
	function display($view,$data=array(),$themed=TRUE,$type=NULL) {
		if ($result = $this->render($view,$data,$themed,$type)) {
			$headers = Load::Headers();
			$headers->sendHTTP();
			exit($result);
		}
		Load::Error('404');
	}
	
	// Render the specified view for this controller with the data provided
	function render($view,$data=array(),$themed=FALSE,$type=NULL) {
		if (is_null($type)) {
			$type = $this->output_type;
		}
		$out = Load::Output($type);
		$out->assignVars($data);
		if (!$themed) {
			return $out->displayControllerView($this,$view);
		} else {
			$content = $out->displayControllerView($this,$view);
			if ($content===FALSE) { return FALSE; }
			$ui = Load::UI();
			return $out->displayTheme($ui->theme(),$content);
		}
	}
	
	// Allow others to see what action was called
	final function getCalledAction() {
		return $this->calledAction;
	}
	
	// What controller is this?
	final function _c() {
		$class = get_class($this);
		return substr($class,strpos($class,'Controller_')+11);
	}
}