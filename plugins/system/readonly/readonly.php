<?php defined('_JEXEC') or die('Restricted Access');

class plgSystemReadonly extends JPlugin {
	function __construct(& $subject, $config) {
		parent::__construct($subject, $config);
	}

	function onAfterRoute() {
		$app	= JFactory::getApplication();
		//we only want to run this plugin for the administrator interface if the user is in the readonly_group
		if(!$app->isAdmin()) return;
		$user = JFactory::getUser();
		if(!$user->id) return;
		$readonly_group = $this->params->get('readonly_group');
		if(empty($readonly_group)) return;
		$usergroups = JAccess::getGroupsByUser($user->id);
		if(!in_array($readonly_group, $usergroups)) return;

		//
		//now we know that the user is in the readonly group in the administrator interface.
		//let's get our request variables and test whether or not we need to deny the current request.
		$task = JRequest::getCmd('task');
		if(strpos($task,'.')) {
			$parts = explode('.',$task);
			$task = trim($parts[1]);
		}
		$deny_request = false;

		//1. Do not allow the user to perform any of our disallowed tasks
		$denied_tasks = array('save','apply','add','edit','delete','new','update','remove');
		$extratasks = $this->params->get('extra_denied_tasks');
		if(!empty($extratasks)) {
			$extratasksarray = preg_split("/\s*(\r\n|\n|\r)+\s*/",trim($extratasks));
			foreach($extratasksarray as $et) {
				if(!empty($et)) {
					$denied_tasks[] = $et;
				}
			}
		}
		if(in_array($task,$denied_tasks)) {
			$deny_request = true;
		}

		//2. Also do not allow users to complete any extra disallowed requests
		$denied_requests = $this->params->get('extra_denied_requests');
		if(!empty($denied_requests) && !$deny_request) {
			$deniedarray = preg_split("/\s*(\r\n|\n|\r)+\s*/",trim($denied_requests));
			foreach($deniedarray as $teststring) {
				if($this->_request_test($teststring)) {
					$deny_request = true;
				}
			}
		}

		//3. Before we deny the request, let's make sure that it does not match the exceptions list
		if($deny_request) {
			$exceptions = $this->params->get('readonly_exceptions');
			$exception_array = preg_split("/(\r\n|\n|\r)+/", trim($exceptions));
			foreach($exception_array as $teststring) {
				if($this->_request_test($teststring)) {
					$deny_request = false;
				}
			}
		}

		//4. Either eny the request or display a message to the user that they are in readonly mode
		if($deny_request) {
			$app->redirect('index.php','You are viewing in readonly mode. You are not allowed to perform that action.','error');
		} else {
			$messagequeue = $app->getMessageQueue();
			$messageinfo = 'You are viewing in readonly mode.';
			$messagetype = 'info';
			$messagearray = array('message' => $messageinfo, 'type' => $messagetype);
			$messagesaved = false;
			if(is_array($messagequeue) && !empty($messagequeue) && in_array($messagearray, $messagequeue)) {
				$messagesaved = true;
			}
			if(!$messagesaved) {
				$app->enqueueMessage($messageinfo,$messagetype);
			}
		}
	}

	/*
	 * Private Function _request_test
	 * Compares the given teststring against the current request.
	 *
	 * @Param $teststring = the string we are testing against. Requires special formatting
	 * @Param $option = the current option
	 * @Param $view = the current view
	 * @Param $task = the current task
	 *
	 * @Returns true if the string matches, false if it does not
	 */
	private function _request_test($teststring) {
		$details = explode(',',$teststring);
		foreach($details as $reqvar) {
			if(trim($reqvar) == '') {
				continue;
			} elseif(strpos($reqvar,'=')) {
				$parts = explode('=',$reqvar);
				$reqval = trim($parts[1]);
				$reqvar = trim($parts[0]);
				if(JRequest::getString($reqvar) != $reqval) {
					return false;
				}
			} else {
				if(is_null(JRequest::getVar($reqvar))) {
					return false;
				}
			}
		}

		return true;
	}
}
