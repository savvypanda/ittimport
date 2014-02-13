<?php
defined('_JEXEC') or die('Restricted access');
 
// include required libraries
jimport('joomla.application.component.helper');

 
/**
 * Script file of IttImport component
 */
class com_ittImportInstallerScript {

	/*
     * method to install the component
	 *
	 * @return void
	*/
	function install($parent) {
		//$db = JFactory::getDBO();
		//$fields = $db->getTableFields('#__vm');
		//if (!array_key_exists('myfield', $fields['#__vm'])) {
			//$query = 'ALTER TABLE `#__vm` ADD COLUMN `myfield` MYFIELDTYPE, ADD INDEX(`myfield`)';
			//$db->setQuery($query);
			//$db->query();
		//}
	}

	/*
	 * method to uninstall the component
	 *
	 * @return void
	*/
	function uninstall($parent) {
		$this->_removeCliScript();
		echo '<p>'.JText::_('COM_ITTIMPORT_UNINSTALL_TEXT').'</p>';
	}

	/*
	 * method to update the component
	 *
	 * @return void
	*/
	function update($parent) {
		JController::setMessage(JText::sprintf('COM_ITTIMPORT_UPDATE_TEXT', $parent->get('manifest')->version));
		$parent->getParent()->setRedirectURL('index.php?option=com_ittimport');
	}

	/*
	 * method to run before an install/update/discover_install method
	 *
	 * @return void
	*/
	function preflight($type, $parent) {
		//if(false) {
		//	$parent->getParent()->abort(JText::_('COM_ITTIMPORT_INSTALL_FAILED'));
		//}
	}

	/*
	 * method to run after an install/update/discover_install method
	 *
	 * @return void
	*/
	function postflight($type, $parent) {
		// $type is the type of change (install, update or discover_install)
		//all we have to do (no matter what type of install it was) is copy the cli files
		$this->_copyCliScript($parent);
	}

	/*
	 * method to run during install to copy the cli script to the joomla cli directory
	 */
	function _copyCliScript($parent) {
		$src = $parent->getParent()->getPath('source');

		jimport("joomla.filesystem.file");

		$clifile = $src.DS.'cli'.DS.'ittimport_cron.php';
		$clitarget = JPATH_ROOT.DS.'cli'.DS.'ittimport_cron.php';
		if(JFile::exists($clitarget)) {
			JFile::delete($clitarget);
		}
		if(JFile::exists($clifile)) {
			JFile::move($clifile, $clitarget);
		}
	}

	/*
	 * method to run during uninstall to remove the cli script if present
	 */
	function _removeCliScript() {
		jimport("joomla.filesystem.file");

		$clitarget = JPATH_ROOT.DS.'cli'.DS.'ittimport_cron.php';
		if(JFile::exists($clitarget)) {
			JFile::delete($clitarget);
		}
	}
}
