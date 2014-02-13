<?php
defined('_JEXEC') or die('Restricted access');

// Access check.
if (!JFactory::getUser()->authorise('core.manage', 'com_ittimport')) {
	return JError::raiseWarning(404, JText::_('JERROR_ALERTNOAUTHOR'));
}

//import dependencies
jimport('joomla.application.component.controller');
include_once(dirname(__FILE__).DS.'helper.php');

//set the page title and icon
IttImportHelper::initializeDocument();


$controller = JController::getInstance('IttImport');
$controller->execute(JRequest::getCmd('task'));
$controller->redirect();
