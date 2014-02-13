<?php
defined('_JEXEC') or die('Restricted access');

//import Joomla modellist library
jimport('joomla.application.component.modellist');

/*
 * IttImport reporter model
 */
class IttImportModelReporter extends JModelList {
	//protected $db;
	private $upload_id;

	/*
	 * Constructor
	 */
	public function __construct($config = array()) {
		parent::__construct($config);
		$this->setState('list.limit', $this->getUserStateFromRequest('list.limit','limit',20,'INT'));
		$this->setState('list.start', $this->getUserStateFromRequest('list.start','limitstart',0,'INT'));
	}

	/*
	 * getUploadId function: returns the upload_id of the current upload after startUpload has been called
	 *
	 * No input
	 *
	 * Returns the upload_id of the current upload if it has been set. False otherwise.
	 */
	public function getUploadId() {
		if(is_int($this->upload_id)) {
			return $this->upload_id;
		} else {
			return false;
		}
	}
	
	/*
	 * getListQuery function: used by the Joomla listmodel to get the items, and for the pagination
	 *
	 * No input
	 *
	 * Returns the Joomla query object containing the information to be displayed
	 */
	protected function getListQuery() {
		/* $query = $this->_db->getQuery(true);
		$query->select('upload_id, user_id, r.username, filename, timestamp, added, updated, cancelled, errored, skipped, total')
			  ->from('#__ittimport_upload u LEFT JOIN #__users r ON u.user_id = r.id');
		return $query; */
		$query = 'SELECT u.upload_id, u.user_id, r.username, u.filename, u.timestamp,
						 added, updated, cancelled, errored, skipped, total
				  FROM #__ittimport_upload u
				  LEFT JOIN #__users r ON u.user_id = r.id
				  ORDER BY u.upload_id DESC';
		$this->_db->setQuery($query);
		return $this->_db->getQuery();
	}
	
	
	/*
	 * startUpload function: starts recording a new upload in the database.
	 *
	 * Input: $user_id: the user_id of the user that initiated the upload
	 * Input: $filename: the original name of the file that is being uploaded
	 *
	 * Returns true on success, false on error
	 *
	 */
	public function startUpload($user_id, $filename) {
		$query = 'INSERT INTO #__ittimport_upload(user_id, filename, timestamp) VALUES ('.$this->_db->quote($user_id).','.$this->_db->quote($filename).', CURRENT_TIMESTAMP)';
		$this->_db->setQuery($query);
		$this->_db->query();
		if($dberror = $this->_db->getErrorMsg()) {
			JError::raiseError(500, JText::sprintf('COM_ITTIMPORT_REPORT_INSERT_SQLERROR',$dberror));
			$this->upload_id = false;
			return false;
		}
		$upload_id = $this->_db->insertid();
		if(!is_int($upload_id) || $upload_id <= 0) {
			JError::raiseError(500, JText::_('COM_ITTIMPORT_REPORT_INSERT_NOID'));
			$this->upload_id = false;
			return false;
		}
		
		$this->upload_id = $upload_id;
		return true;
	}

	public function finishUpload() {
		if(empty($this->upload_id)) {
			JError::raiseError(500, JText::_('COM_ITTIMPORT_REPORT_NOUPLOAD'));
			return false;
		}

		$query = 'SELECT SUM(CASE WHEN status=\'added\' THEN 1 ELSE 0 END) as added,
						 SUM(CASE WHEN status=\'updated\' THEN 1 ELSE 0 END) as updated,
						 SUM(CASE WHEN status=\'cancelled\' THEN 1 ELSE 0 END) as cancelled,
						 SUM(CASE WHEN status=\'errored\' THEN 1 ELSE 0 END) as errored,
						 SUM(CASE WHEN status=\'skipped\' THEN 1 ELSE 0 END) as skipped,
						 COUNT(details_id) as total
				  FROM #__ittimport_details
				  WHERE upload_id='.$this->upload_id;
		$this->_db->setQuery($query);
		$stats = $this->_db->loadAssoc();
		if(!empty($stats)) {
			$query = 'UPDATE #__ittimport_upload SET added='.$stats['added'].', updated='.$stats['updated'].', cancelled='.$stats['cancelled'].', errored='.$stats['errored'].', skipped='.$stats['skipped'].', total='.$stats['total'].' WHERE upload_id='.$this->upload_id;
			$this->_db->setQuery($query);
			$this->_db->query();
			if($dberror = $this->_db->getErrorMsg()) {
				JError::raiseError(500, JText::sprintf('COM_ITTIMPORT_REPORT_INSERT_SQLERROR',$dberror));
				return false;
			}
		}
		return true;
	}
	
	/*
	 * recordEvent function: Adds a details record to the database for the current upload with the specified information.
	 *
	 * Requires that the startUpload function has been run first
	 *
	 * Input: $id: The id of the record in the upload file
	 * Input: $eventid: If a matching event was found in the database, the JEvents ID of the matching event
	 * Input: $status: should be one of: 'added', 'updated', 'cancelled', or 'errored'
	 * Input: $details: Additional information about the upload to be displayed in the details view. If the status is 'errored', then this should be the error message
	 *
	 * returns true on success, false on error
	 *
	 */
	public function recordEvent($person_id, $course_number, $status, $details = '') {
		if(empty($this->upload_id)) {
			JError::raiseError(500, JText::_('COM_ITTIMPORT_REPORT_NOUPLOAD'));
			return false;
		}

		if($status == 'errored' && $details == '') {
			$backtrace = debug_backtrace();
			$details = '<p>No Error Message included: Call stack:';
			for($i=count($details),$j=0;$i>=0&&$j<5;$i--,$j++) :
				$details .= '<br />'.$details['file'].':'.$details['line'].' in '.$details['function'].'('.explode(',',$details['args']).')';
			endfor;
			$details .= '</p>';
		}

		$query = sprintf('INSERT INTO #__ittimport_details(upload_id, person_id, course_no, status, details) VALUES (%s,%s,%s,%s,%s)'
							,$this->_db->quote($this->upload_id)
							,$this->_db->quote($person_id)
							,$this->_db->quote($course_number)
							,$this->_db->quote($status)
							,$this->_db->quote($details)
		);
		$this->_db->setQuery($query);
		$this->_db->query();
		if($dberror = $this->_db->getErrorMsg()) {
			JError::raiseError(500, JText::sprintf('COM_ITTIMPORT_REPORT_INSERT_SQLERROR',$dberror));
			return false;
		}
		return true;
	}
}
