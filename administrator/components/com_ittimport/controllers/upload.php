<?php
defined('_JEXEC') or die('Restricted access');

//import joomla controlleradmin library
jimport('joomla.application.component.controller');

/*
 * IttImport Controller
 */
class IttImportControllerUpload extends JController {
	
	public function __construct($config = array()) {
		parent::__construct($config);

		$this->registerTask('upload', 'upload');
	}

	public function getModel($name = 'Upload', $prefix = 'IttImportModel', $config = array('ignore_request' => true)) {
		return parent::getModel($name, $prefix, $config);
	}
	
	function upload() {
		JRequest::checkToken() or jexit(JText::_('JINVALID_TOKEN'));
		
		//Verify that the user is allowed to upload the file
		$user = JFactory::getUser();
		if(!$user->authorise('core.manage', 'com_ittimport')) {
			JError::raiseWarning(403, JText::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'));
			return false;
		}

		//Initialize the models and perform the upload
		$uploadModel = $this->getModel();
		$reporterModel = $this->getModel('Reporter');
		$uploadModel->setReporter($reporterModel);
		
		//Now fetch the file and upload it
		$uploadfile = JRequest::getVar('ittimport_importfile', null, 'files', 'array');
		if(is_null($uploadfile)) {
			JError::raiseError(500, JText::_('COM_ITTIMPORT_UPLOAD_NULL'));
			return false;
		} elseif($uploadfile['error'] > 0) {
			JError::raiseError(500, JText::sprintf('COM_ITTIMPORT_UPLOAD_FILEERROR',$uploadfile['error']));
			return false;
		}

		$success = $uploadModel->upload($uploadfile['tmp_name'], $uploadfile['name']);

		//If we actually got the start of the import, send an email to the administrator(s) based on how the component configuration is set
		$params = JComponentHelper::getParams('com_ittimport');
		$notify_admin_flag = $params->get('notify_admin');
		$send_email = ($notify_admin_flag=='0' || ($notify_admin_flag=='1' && $success === true))? false:true;
		if($success !== false && $send_email) {
			$config = JFactory::getConfig();
			$fromemail = $config->getValue('config.mailfrom');
			$fromname = $config->getValue('config.fromname');
			$recipient = explode(',',$params->get('admin_emails'));
			if(!is_array($recipient) || empty($recipient)) {
				JError::raiseWarning(420, JText::_('COM_ITTIMPORT_ADMIN_EMAIL_IS_EMPTY'));
				return true;
			}
			if($success === true) {
				$subject = JText::_('COM_ITTIMPORT_IMPORT_SUCCESS_EMAIL_SUBJECT');
				$body = JText::sprintf('COM_ITTIMPORT_SINGLE_IMPORT_SUCCESS_EMAIL_BODY',JRoute::_('index.php?option=com_ittimport&view=details&id='.$reporterModel->getUploadId(),false,-1));
			} else {
				$subject = JText::_('COM_ITTIMPORT_IMPORT_ERROR_EMAIL_SUBJECT');
				$body = JText::sprintf('COM_ITTIMPORT_SINGLE_IMPORT_ERROR_EMAIL_BODY',JRoute::_('index.php?option=com_ittimport&view=details&id='.$reporterModel->getUploadId(),false,-1));
			}
			$mailer = JFactory::getMailer();
			$mailer->sendMail($fromemail, $fromname, $recipient, $subject, $body);

		}
		
		//If the upload was successful, display success page. If not, display error message.
		if($success !== false) {
			//$this->setRedirect('index.php?option=com_ittimport&view=details&id='.$reporterModel->getUploadId(),JText::_('COM_ITTIMPORT_UPLOAD_SUCCESSFULL'));
			JFactory::getApplication()->enqueueMessage(JText::_('COM_ITTIMPORT_UPLOAD_SUCCESSFULL'));
		} else {
			JError::raiseWarning(499, JText::sprintf('COM_ITTIMPORT_ERROR_PROCESSING',$uploadfile['name']));
		}
	}
	
}
