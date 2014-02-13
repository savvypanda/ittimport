<?php
defined('_JEXEC') or die('Restricted access');

//import Joomla view library
jimport('joomla.application.component.view');

/*
 * HTML View class for the IttImport component
 */
class IttImportViewIttImport extends JView {
	function display($tpl=null) {
		//Set the toolbar
		$layout = $this->getLayout();
		if(is_null($layout) || $layout == 'default') {
			IttImportHelper::addToolBar('upload');
		} else {
			IttImportHelper::addToolBar();
		}

		//Display the template
		parent::display($tpl);
	}
}