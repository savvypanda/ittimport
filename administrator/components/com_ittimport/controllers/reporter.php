<?php
defined('_JEXEC') or die('Restricted access');

//import joomla controller library
jimport('joomla.application.component.controller');

/*
 * IttImport Controller
 */
class IttImportControllerReporter extends JController {
	function display($cachable=false, $urlparams=false) {
		JRequest::setVar('view', JRequest::getCmd('view','Reporter'));
		parent::display($cachable, $urlparams);
	}
	
	public function getModel($name = 'Reporter', $prefix = 'IttImportModel', $config = array('ignore_request' => true)) {
		return parent::getModel($name, $prefix, $config);
	}
}
