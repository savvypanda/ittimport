<?php
define('_JEXEC',1);
define('DS', DIRECTORY_SEPARATOR);

if (file_exists(dirname(dirname(__FILE__)).DS.'defines.php')) {
	include_once dirname(dirname(__FILE__)).DS.'defines.php';
}
if (!defined('_JDEFINES')) {
	define('JPATH_BASE', dirname(dirname(__FILE__)));
	require_once JPATH_BASE.DS.'includes'.DS.'defines.php';
}

require_once(JPATH_LIBRARIES.DS.'import.php');
if(file_exists(JPATH_BASE.DS.'includes'.DS.'version.php')) {
	require_once JPATH_BASE.DS.'includes'.DS.'version.php';
} else {
	require_once JPATH_LIBRARIES.DS.'cms.php';
}

jimport('joomla.application.cli');

class IttImportApp extends JApplicationCli {
	public function __construct($input = null, $config = null, $dispatcher = null) {
		if(!defined('STDOUT')) define('STDOUT', fopen('php://stdout','w'));
		if(!defined('STDIN')) define('STDIN', fopen('php://stdin','r'));
		if(!defined('STDERR')) define('STDERR', fopen('php://stderr','w'));
		parent::__construct($input, $config, $dispatcher);
	}
	protected function doExecute() {
		restore_error_handler();
		JError::setErrorHandling(E_ERROR, 'die');
		JError::setErrorHandling(E_WARNING, 'echo');
		JError::setErrorHandling(E_NOTICE, 'ignore');

		jimport('joomla.environment.request');
		jimport('joomla.application.component.helper');

		if(function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		//This is to initialize the session in a CLI script
		ob_start();
		JSession::getInstance('none',array('expire'=>900));
		ob_end_clean();

		//Prepare the component information for com_ittimport
		define('JPATH_COMPONENT_ADMINISTRATOR',JPATH_ADMINISTRATOR.DS.'components'.DS.'com_ittimport');
		JFactory::getLanguage()->load('com_ittimport', JPATH_ADMINISTRATOR, 'en-GB', true);
		//this line is to allow other components that we relate with to use the JFactory::getApplication()
		JFactory::getApplication('site');

		//and this is to allow the use of JRoute::_() and JURI functions
		$livesite = JFactory::getConfig()->getValue('live_site');
		if(substr($livesite,0,4) != 'http') {
			JError::raiseWarning('411','You must set the live site URL in the configuration file in order to use the CLI script.');
			return false;
		}
		jimport('joomla.environment.uri');

		//and this is for VirtueMart's IP Tracking
		if(!isset($_SERVER['REMOTE_ADDR'])) $_SERVER['REMOTE_ADDR']='127.0.0.1';


		$params = JComponentHelper::getParams('com_ittimport');
		//Step 1. Import any available files
		if($params->get('uploads_enabled',false)) {
			if(!$this->_cron_login($params->get('cron_username'))) {
				JError::raiseError(415,'Unable to log in as the cron user. Please fix this error before trying again.');
				return false;
			}
			$this->_importFile();
		}

		//Step 2. Update any orders to a "ready to be shipped" status
		if($params->get('shipping_enabled',false)) {
			if(!$this->_cron_login($params->get('cron_shipper'))) {
				JError::raiseError(416,'Unable to log in as the cron manager user. Please fix this error before trying again.');
				return false;
			}
			$this->_shipOrders();
		}

		$this->_updateInventory();
	}

	private function _importFile() {
		require_once(JPATH_COMPONENT_ADMINISTRATOR.DS.'models'.DS.'upload.php');
		require_once(JPATH_COMPONENT_ADMINISTRATOR.DS.'models'.DS.'reporter.php');
		$uploadModel = new IttImportModelUpload();
		$reporterModel = new IttImportModelReporter();
		$uploadModel->setReporter($reporterModel);
		$params = JComponentHelper::getParams('com_ittimport');
		$config = JFactory::getConfig();

		//The cron directory is configurable in the component parameters
		$cron_directory = $params->get('cron_directory');
		$cron_directory = str_replace('{SITE_ROOT}',JPATH_BASE,$cron_directory);
		if(is_dir($cron_directory)) {
			chdir($cron_directory);
		} else {
			JError::raiseWarning(413, JText::sprintf('COM_ITTIMPORT_DIRECTORY_INVALID',$cron_directory));
			return false;
		}
		//and let's make sure there is an archives folder in the cron directory as well.
		if(!is_dir('archived')) {
			mkdir('archived', 0755);
		}

		//search for any files that should be imported. There may not be any files, or there may be many
		$cron_filename = $params->get('cron_filename');
		$files = glob($cron_filename, GLOB_BRACE|GLOB_NOSORT);
		if($files === false) {
			//JError::raiseWarning(410, JText::sprintf('COM_ITTIMPORT_FILE_SEARCH_INVALID',$cron_filename));
			$this->out(JText::sprintf('COM_ITTIMPORT_FILE_SEARCH_INVALID',$cron_filename));
			return false;
		}
		if(empty($files)) {
			//JError::raiseWarning(409,JText::sprintf("COM_ITTIMPORT_FILE_DOES_NOT_EXIST",$cron_filename));
			$this->out(JText::sprintf('COM_ITTIMPORT_FILE_DOES_NOT_EXIST',$cron_filename));
			return false;
		}
		$files = array_unique($files);
		usort($files, array(get_class($this),"filesort"));

		//now we have all of the files to import in the correct order.
		//let's import them into the database and move them into the archive directory.
		//$archives_dir = JPATH_COMPONENT_ADMINISTRATOR.DS.'upload'.DS.'archived';
		$archives_dir = 'archived';
		$archive_filename_orig = $params->get('archive_filename');
		$administrator_url_base = rtrim(JFactory::getConfig()->getValue('live_site'),'/').'/administrator/';

		$results = array();
		$has_error = false;
		foreach($files as $filepath) {
			$filename_parts = pathinfo($filepath);
			$archive_filename = $archive_filename_orig;
			$archive_filename = str_replace('{FILENAME}',$filename_parts['basename'],$archive_filename);
			$archive_filename = str_replace('{FILENAME_BASE}',$filename_parts['filename'],$archive_filename);
			$archive_filename = str_replace('{TIMESTAMP}',date('Y-m-d_His'),$archive_filename);

			//Now fetch the file and upload it
			ob_start();
			$success = $uploadModel->upload($filepath, $archive_filename);
			ob_end_clean();
			//Then archive it
			rename($filepath, $archives_dir.DS.$archive_filename);

			//and save the results
			$result = array('success'=>$success);
			$result['url'] = $administrator_url_base.'index.php?option=com_ittimport&view=details&id='.$reporterModel->getUploadId();
			if($success === true) {
				$result['message'] = JText::sprintf('COM_ITTIMPORT_CRON_IMPORT_SUCCESSFUL', $result['url']);
			} elseif ($success === false) {
				$has_error = true;
				$result['message'] = JText::_('COM_ITTIMPORT_CRON_IMPORT_FAILED');
			} else {
				$has_error = true;
				$result['message'] = JText::sprintf('COM_ITTIMPORT_CRON_IMPORT_ERRORS', $success, $result['url']);
			}
			$results[$filepath] = $result;
		}

		//now we will remove old files from the archive directory
		$archive_days = $params->get('archive_days');
		$archives_handle = opendir($archives_dir);
		while(false !== ($file = readdir($archives_handle))) {
			if($file != '.' && $file != '..' && $file != 'index.html' && time() - filemtime($archives_dir.DS.$file) > $archive_days*24*3600) {
				unlink($archives_dir.DS.$file);
			}
		}
		closedir($archives_handle);

		//If we actually got the start of the import, send an email to the administrator(s) based on how the component configuration is set
		$notify_admin_flag = $params->get('notify_admin');
		$send_email = ($notify_admin_flag=='0' || ($notify_admin_flag=='1' && !$has_error))? false:true;
		if($send_email) {
			$fromemail = $config->getValue('config.mailfrom');
			$fromname = $config->getValue('config.fromname');
			$recipient = explode(',',$params->get('admin_emails'));
			if(!is_array($recipient) || empty($recipient)) {
				//JError::raiseError(500, JText::_('COM_ITTIMPORT_ADMIN_EMAIL_IS_EMPTY'));
				$this->out(JText::_('COM_ITTIMPORT_ADMIN_EMAIL_IS_EMPTY'));
				return false;
			}

			$messages = array();
			foreach($results as $f=>$r) {
				$messages[] = $f.': '.$r['message'];
			}
			$messagestring = implode("\n",$messages);
			if($has_error) {
				$subject = JText::_('COM_ITTIMPORT_IMPORT_ERROR_EMAIL_SUBJECT');
				$body = JText::sprintf('COM_ITTIMPORT_IMPORT_ERROR_EMAIL_BODY',$messagestring);
			} else {
				$subject = JText::_('COM_ITTIMPORT_IMPORT_SUCCESS_EMAIL_SUBJECT');
				$body = JText::sprintf('COM_ITTIMPORT_IMPORT_SUCCESS_EMAIL_BODY',$messagestring);
			}

			$mailer = JFactory::getMailer();
			$mailer->sendMail($fromemail, $fromname, $recipient, $subject, $body);
		}

		//If the upload was successful, display success message. If not, display error message.
		if(!$has_error) {
			$this->out(JText::_('COM_ITTIMPORT_CRON_UPLOAD_SUCCESS'));
			return true;
		} else {
			JError::raiseWarning(499, JText::_('COM_ITTIMPORT_ERROR_PROCESSING'));
		}
	}

	private function _shipOrders() {
		require_once(JPATH_COMPONENT_ADMINISTRATOR.DS.'models'.DS.'shipping.php');
		$shippingModel = new IttImportModelShipping();
		ob_start();
		$results = $shippingModel->processOrders();
		ob_end_clean();
		if($results['shipped']) {
			$this->out($results['shipped'].' orders will be shipped.');
		}
		if($results['empty']) {
			$this->out($results['empty'].' orders had no physical items and were marked as shipped.');
		}
		if($results['cancelled']) {
			$this->out($results['cancelled'].' orders were cancelled because they were empty.');
		}
		if($results['backordered']) {
			$this->out($results['backordered'].' backorders were created for items that are out of stock.');
		}
		if($results['errored']) {
			$this->out($results['errored'].' orders failed to change their status to \'Ready to be Shippped\'.');
		}
		if(!empty($results['details'])) {
			$this->out('Additional order details from the automated shipping status change process:'."\n".implode("\n",$results['details']));
		}
	}

	private function _updateInventory() {
		$db = JFactory::getDbo();
		$query = 'UPDATE #__virtuemart_products p
					SET p.product_ordered = (
						SELECT COUNT(CASE WHEN o.order_status IN(\'P\',\'C\',\'A\',\'M\',\'D\',\'B\') AND i.order_status IN(\'P\',\'C\',\'A\',\'M\',\'D\',\'B\') THEN 1 ELSE NULL END) as product_ordered
                        FROM #__virtuemart_order_items i
                        JOIN #__virtuemart_orders o ON o.virtuemart_order_id = i.virtuemart_order_id
                        WHERE i.virtuemart_product_id = p.virtuemart_product_id
					)
					WHERE p.published=1';
		$db->setQuery($query);
		$db->query();

		if($msg = $db->getErrorMsg()) {
			$this->out('Failed to update inventory. Database error: '."\n".$msg);
		}
	}

	static function filesort($a, $b) {
		$ta=filemtime($a);
		$tb=filemtime($b);
		if($ta==$tb) return strnatcmp($a,$b);
		return($ta<$tb)?-1:1;
	}

	private function _cron_login($username) {
		$userid = $this->_forceUserFromUsername($username);
		if(!$userid || is_string($userid)) {
			JError::raiseWarning(412,(string)$userid);
			return false;
		}
		$session = JFactory::getSession();
		$session->set('user',JUser::getInstance($userid));
		return true;
	}

	private function _forceUserFromUsername($username) {
		//start with the obvious search for if the user with this username already exists
		$db = JFactory::getDbo();
		$db->setQuery('SELECT id FROM #__users WHERE username='.$db->quote($username));
		$db->query();
		$numrows = $db->getNumRows();
		if($numrows == 1) {
			return (int) $db->loadResult();
		} elseif ($numrows > 1) {
			return JText::sprintf('COM_ITTIMPORT_MULTIPLE_USERS_FOR_USERNAME',$username);
		}

		//There is no user with this username. Let's try to fetch an existing user by email address.
		//We will build the email address using the supplied username and the domain from the first admin email address from the component parameters
		$params = JComponentHelper::getParams('com_ittimport');
		$adminemails = explode(',',$params->get('admin_emails'));
		if(!is_array($adminemails) || empty($adminemails) || !strpos($adminemails[0],'@')) {
			return JText::_('COM_ITTIMPORT_ADMIN_EMAIL_IS_EMPTY');
		}
		$domain = substr($adminemails[0], strpos($adminemails[0],'@')+1);
		$email = $username.'@'.$domain;

		$db->setQuery('SELECT id FROM #__users WHERE email='.$db->quote($email));
		$db->query();
		$numrows = $db->getNumRows();
		if($numrows == 1) {
			return (int) $db->loadResult();
		} elseif($numrows > 1) {
			return JText::sprintf('COM_ITTIMPORT_MULTIPLE_USERS_FOR_USERNAME',$email);
		}

		//no user found with this username or email address. We are all clear to create a new user.
		$data = array(
			'username' => $username,
			'name' => $username,
			'email' => $email
		);
		$newuser = JUser::getInstance();
		if(!$newuser->bind($data) || !$newuser->save()) {
			return JText::sprintf('COM_ITTIMPORT_ERROR_CREATING_USER',$newuser->getError());
		}
		return (int) $newuser->id;
	}
}


JApplicationCli::getInstance('IttImportApp')->execute();
