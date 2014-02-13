<?php
defined('_JEXEC') or die('Restricted access');

//import joomla controller library
jimport('joomla.application.component.controller');

/*
 * IttImport Controller
 */
class IttImportControllerIttImport extends JController {

	/*
	 * Constructor
	 */
	public function __construct($config = array()) {
		parent::__construct($config);
	}

	/*
	 * Display function
	 */
	function display($cachable=false) {
		JRequest::setVar('view', JRequest::getCmd('view','IttImport'));
		parent::display($cachable);
	}
}
