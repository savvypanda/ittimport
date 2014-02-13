<?php
defined('_JEXEC') or die('Restricted access');

//import dependencies
jimport('joomla.application.component.controller');

/*
 * IttImport Controller
 */
class IttImportController extends JController {
	
	function display($cachable=false, $urlparams=false) {
		JRequest::setVar('view', JRequest::getCmd('view','IttImport'));
		parent::display($cachable, $urlparams);
		return $this;
	}

	public function getModel($name = 'IttImport', $prefix = 'IttImportModel', $config = array('ignore_request' => true)) {
		return parent::getModel($name, $prefix, $config);
	}
}
