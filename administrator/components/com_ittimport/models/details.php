<?php
defined('_JEXEC') or die('Restricted access');

//import Joomla modellist library
jimport('joomla.application.component.modellist');

/*
 * IttImport details model - for displaying the details only. Does not perform any create, update, or delete operations
 */
class IttImportModelDetails extends JModelList {
	protected $db;
	protected $upload_id;
	protected $filename;
	protected $timestamp;

	/*
	 * Constructor
	 *
	 * If there is not an upload_id set in the request, throw an error
	 */
	public function __construct($config = array()) {
		parent::__construct($config);
		$this->db = JFactory::getDBO();

		$id = JRequest::getInt('id',-1);
		if($id > 0) {
			$this->upload_id = $id;

			//now get the timestamp of the upload
			$query = 'SELECT filename, timestamp FROM #__ittimport_upload WHERE upload_id='.$id;
			$this->db->setQuery($query);
			$this->db->query();
			$results = $this->db->loadRow();
			if(is_null($results)) {
				JError::raiseWarning(100,'COM_ITTIMPORT_DETAILS_WITHOUT_TIMESTAMP');
			} else {
				list($this->filename, $this->timestamp) = $results;
			}
		} else {
			JError::raiseWarning(100,'COM_ITTIMPORT_DETAILS_WITHOUT_UPLOAD_ID');
			return;
		}

		$this->setState('list.limit', $this->getUserStateFromRequest('list.limit','limit',20,'INT'));
		$this->setState('list.start', $this->getUserStateFromRequest('list.start','limitstart',0,'INT'));
	}

	/*
	 * get function for view to access data
	 *
	 * getUploadId
	 * getTimestamp
	 */
	public function getUploadId() {
		return $this->upload_id;
	}
	public function getTimestamp() {
		return $this->timestamp;
	}
	public function getFilename() {
		return $this->filename;
	}
	
	/*
	 * getListQuery function: used by the Joomla listmodel to get the items, and for the pagination
	 *
	 * No input
	 *
	 * Returns the Joomla query object containing the information to be displayed
	 */
	protected function getListQuery() {
		if(!is_null($this->upload_id) && $this->upload_id > 0) {
			$statusfilter = '';
			if($status = JRequest::getWord('status','')) {
				$statusfilter = ' AND status='.$this->db->quote($status);
			}
			$query = 'select * from #__ittimport_details WHERE upload_id='.$this->db->quote($this->upload_id).$statusfilter;
			$this->db->setQuery($query);
			return $this->db->getQuery();
		} else {
			JError::raiseError(500, JText::_('COM_ITTIMPORT_REPORTER_GET_DETAILS_NO_ID'));
			return false;
		}
	}
}