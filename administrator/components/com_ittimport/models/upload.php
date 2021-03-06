<?php defined('_JEXEC') or die('Restricted access');

//import dependencies
jimport('joomla.application.component.model');

if(!class_exists( 'VmConfig' )) require_once(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_virtuemart'.DS.'helpers'.DS.'config.php');
VmConfig::loadConfig();
if(!class_exists('VirtuemartModelUser')) require_once(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'user.php');
if(!class_exists('VirtueMartModelOrders')) require_once(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php');
if(!class_exists('VirtueMartCart')) require_once(JPATH_VM_SITE.DS.'helpers'.DS.'cart.php');

/*
 * IttImport Upload model
 *
 */
class IttImportModelUpload extends JModel {
	private $reporter;
	private $user_id;
	private $newusergroup;
	//private $ship_cutoff;
	private $categories_to_skip;

	private $jusers = array();
	private $vcountries = array();
	private $vstates = array();
	private $courses = array();

	private $row = 0;

	private $fields = array(
		'guid' => 'person_id',
		'person_id' => 'old_person_id',
		'first_name' => 'firstname',
		'middle_name' => 'middlename',
		'last_name' => 'lastname',
		'address1' => 'address1',
		'address2' => 'address2',
		'address3' => 'address3',
		'city' => 'city',
		'state' => 'state',
		'country' => 'country',
		'zipcode' => 'zip',
		'phone' => 'phone',
		'email' => 'email',
		'course_no' => 'course',
		'course_start_date' => 'course_start_date',
		'cancellation' => 'cancelled',
		'order_date' => 'ordered_on'
	);

	//if desired, each field can be transformed and validated when it is imported from the file,
	//by adding private functions to the end of the file named <field>_transform and <field>_validate
	//where <field> is the VALUE in the $fields array (eg: course_transform, ordered_on_validate, etc...)
	//look at the end of the file for examples


	/*
	 * Constructor
	 */
	public function __construct($config = array()) {
		parent::__construct($config);
		$params = JComponentHelper::getParams('com_ittimport');
		$this->categories_to_skip = explode(',',str_replace(' ','',$params->get('skip_product_category','')));
	}


	/*
	 * Function to set the reporter for the class
	 * Includes error handling for if the given object is not a reporter
	 * This function must be called before the upload function
	 *
	 * Input: $object: The reporter object to set in this class
	 *
	 * Returns: True on success, false on failure.
	 *
	 */
	public function setReporter(&$object) {
		if(get_class($object) == 'IttImportModelReporter') {
			$this->reporter = $object;
			return true;
		} else {
			return false;
		}
	}

	/*
	 * Upload function. This is the main point of this model
	 * The reporter must be set before this function is called or else it will error
	 *
	 * Input: $filepath: the path of the file to import
	 * Input: $filename: the name of the file to import. Should not include the filepath
	 *
	 * Returns: True if there are no errors. False if there is an error that prevents the upload from continuing.
	 * The number of errors if there were errors that did not prevent the upload from continuing (ie: with specific records).
	 */
	public function upload($filepath, $filename) {
		$user = JFactory::getUser();
		$this->user_id = $user->id;

		// Double-check that the reporter has been set
		if(is_null($this->reporter) || get_class($this->reporter) != 'IttImportModelReporter') {
			JError::raiseError(500, JText::_('COM_ITTIMPORT_UPLOAD_REPORTER_NOT_SET'));
			return false;
		}

		//Make sure that we can actually access the file
		if(($handle = fopen($filepath, 'r')) === false) {
			JError::raiseWarning(404, JText::_('COM_ITTIMPORT_UPLOAD_FAILED_FILE_EMPTY'));
			return false;
		}

		//And make sure that the file appears to be a validly formatted CSV file
		$headers = fgetcsv($handle,1000,',');
		$numcols = count($headers);
		if($numcols < count($this->fields)) {
			JError::raiseWarning(404, JText::_('COM_ITTIMPORT_UPLOAD_FAILED_NOT_ENOUGH_COLUMNS'));
			return false;
		}

		// Now we can start recording the upload with the reporter
		$this->reporter->startUpload($this->user_id, $filename);
		$errored = 0;
		$this->row = 0;
		//and record that we are batch importing records/orders
		global $is_ittbooks_batch_import;
		$is_ittbooks_batch_import = true;

		//Iterate through each row in the spreadsheet
		while(($data = fgetcsv($handle, 1000, ',')) !== false) {
			$this->row++;
			if(count($data) != $numcols) {
				$this->reporter->recordEvent('','','errored',JText::_('COM_ITTIMPORT_UPLOAD_FAILED_WRONG_COLUMN_NUMBER'));
				$errored++;
				continue;
			}

			//convert the data into an object based on the header rows
			$record = new stdClass();
			foreach($headers as $idx => $field) {
				$field = strtolower($field);
				if(isset($this->fields[$field])) {
					$myfield = $this->fields[$field];
					$record->$myfield = $data[$idx];
				}
			}

			//Perform any necessary transformations, validations, and/or operations on the record
			$result = $this->prepareRecord($record);
			if(!$result || is_string($result)) {
				$person_id = isset($record->person_id)?$record->person_id:'';
				$course = isset($record->course)?$record->course:'';
				$this->reporter->recordEvent($person_id,$course,'errored',JText::sprintf('COM_ITTIMPORT_FAILED_PREPARATION', $result));
				$errored++;
				continue;
			} elseif(is_array($result)) {
				$person_id = isset($record->person_id)?$record->person_id:'';
				$course = isset($record->course)?$record->course:'';
				$details = isset($result['details'])?$result['details']:'';
				$this->reporter->recordEvent($person_id, $course, $result['status'], $details);
				$this->{$result['status']}++;
				continue;
			} else {
				$result->filename = $filename;
				$record = $result;
			}

			//Now the record is ready to process. This function is where orders should be created/modified from the record
			$result = $this->processRecord($record);
			//Let's log the result of our record processing
			if(!is_array($result)) {
				$this->reporter->recordEvent($record->person_id, $record->course, 'errored', $result);
				$errored++;
				continue;
			} else {
				$details = isset($result['details'])?$result['details']:'';
				$this->reporter->recordEvent($record->person_id, $record->course, $result['status'], $details);
				$this->{$result['status']}++;
			}
		}

		//We are done importing
		$this->reporter->finishUpload();
		$is_ittbooks_batch_import = false;

		// Return the number of errors encountered during the upload
		return ($errored == 0)?true:$errored;
	}


	/*
	 * prepareRecord function. Performs any modifications necessary to prepare
	 * the record for the database. Verifies that the elements are correct.
	 *
	 * Input: $record: The record to be modified/verified
	 *
	 * Returns: Basic PHP object containing the event on success, string containing an error message if any of the elements are not valid
	 *
	 */
	private function prepareRecord($record) {
		$newrecord = new stdClass();

		//iterate through the record fields, performing transformations as necessary, and validating the format
		foreach($this->fields as $value) {
			$transformfunction = $value.'_transform';
			$validatefunction = $value.'_validate';
			if(method_exists($this,$transformfunction)) {
				$newrecord->$value = $this->$transformfunction($record->$value);
			} else {
				$newrecord->$value = $record->$value;
			}
			if(method_exists($this,$validatefunction) && !empty($newrecord->value) && !$this->$validatefunction($newrecord->$value)) {
				return JText::sprintf('COM_ITTIMPORT_'.strtoupper($value).'_INVALID',$record->$value);
			}
		}

		//now add the elements that we can find but that are not in the XML file
		//starting with the relevant product IDs for the course
		$result = $this->getProductIds($newrecord->course);
		if(!is_array($result)) {
			return $result;
		} elseif(empty($result)) {
			//return array('status'=>'skipped', 'details'=> 'No products are associated with this course: '.$newrecord->course.'.');
			return 'No products are associated with this course: '.$newrecord->course.'.';
		}
		$newrecord->productids = $result;

		//next we need the user_id of the given student
		$result = $this->getStudentId($newrecord);
		if(!$result || is_string($result)) {
			return $result;
		}
		$newrecord->studentid = $result;
		//and the user's virtuemart user
		$result = $this->fetchVirtuemartUser($newrecord);
		if(!$result || is_string($result)) {
			return $result;
		} else {
			$newrecord->vmuser = $result;
		}

		//now that we have the virtuemart user, we need to update their address BEFORE we fetch or create any orders
		$result = $this->updateAddresses($newrecord);
		if(!$result || is_string($result)) {
			return 'Failed to update the user\'s address: '.$result;
		}


		//and we need to fetch the order for this user and course start date
		$result = $this->getOrder($newrecord);
		if(!is_array($result) && ($result !== false || !$newrecord->cancelled)) {
			return $result;
		}
		if(is_array($result)) {
			$result['courses'] = $this->getCourseIDs($result['details']['BT']->customer_note);
			$order_id = $result['details']['BT']->virtuemart_order_id;
		} else {
			$order_id = false;
		}
		$newrecord->order = $result;
		$newrecord->order_id = $order_id;

		//we're done. Let's return the prepared record
		return $newrecord;
	}

	/*
	 * getProductIds function: Fetches all of the product IDs for a given course.
	 *
	 * Input: $course (string): The course code for the ITT Books course.
	 *
	 * Returns: an array containing the IDs of all of the products for the given course - array may be empty.
	 */
	private function getProductIds($course) {
		//caching the course products for cheap improved performance
		if(!array_key_exists($course, $this->courses)) {
			$num_exclude_categories = count($this->categories_to_skip);
			if($num_exclude_categories == 0) {
				$category_exclude_subquery = '';
			} elseif($num_exclude_categories == 1) {
				$category_exclude_subquery = 'AND NOT EXISTS(SELECT 1 FROM #__virtuemart_product_categories pce WHERE pce.virtuemart_product_id=p.virtuemart_product_id AND pce.virtuemart_category_id='.$this->categories_to_skip[0].')';
			} else {
				$category_exclude_subquery = 'AND NOT EXISTS(SELECT 1 FROM #__virtuemart_product_categories pce WHERE pce.virtuemart_product_id=p.virtuemart_product_id AND pce.virtuemart_category_id IN('.implode(',',$this->categories_to_skip).'))';
			}

			$query = 'SELECT DISTINCT p.virtuemart_product_id '. /*, p.product_in_stock, p.product_ordered */
					  'FROM #__virtuemart_products p
					  JOIN #__virtuemart_products_en_gb l ON p.virtuemart_product_id=l.virtuemart_product_id
					  WHERE p.published=1 '.$category_exclude_subquery.'
					  AND l.product_name REGEXP '.$this->_db->quote('[[:<:]]'.$course.'[[:>:]]');
			$this->_db->setQuery($query);
			$this->_db->query();
			if($this->_db->getErrorMsg()) {
				return $this->_db->getErrorMsg();
			}
			$this->courses[$course] = $this->_db->loadResultArray();
		}
		return $this->courses[$course];
	}

	/*
	 * getStudentId function: Fetch the joomla user_id for a student, creating the user if they do not already exist. User is created with a random password.
	 *
	 * Input: $record: The record including the student's person_id, email address and name
	 *
	 * Returns: The user_id on success, string containing an error message on failure
	 *
	 */
	private function getStudentId($record) {
		if(!array_key_exists($record->person_id,$this->jusers)) {
			$query = 'SELECT id FROM #__users WHERE username='.$this->_db->quote($record->person_id);
			$this->_db->setQuery($query);
			$this->_db->query();
			$numrows = $this->_db->getAffectedRows();
			if($numrows == 1) {
				$this->jusers[$record->person_id] = (int) $this->_db->loadResult();
			} elseif ($numrows == 0) {
				//searching by GUID failed. Create a new user
				$nameparts = array();
				if(!empty($record->firstname)) $nameparts[] = $record->firstname;
				if(!empty($record->middlename)) $nameparts[] = $record->middlename;
				if(!empty($record->lastname)) $nameparts[] = $record->lastname;
				$data = array(
					'name' => implode(' ',$nameparts),
					'username' => $record->person_id,
					'usertype' => 'Registered',
					'email' => $record->email,
					'groups' => $this->getDefaultUserGroup(),
				);
				if(!empty($record->old_person_id)) $data['person_id'] = $record->old_person_id;
				$newuser = new JUser;
				if($newuser->bind($data) && $newuser->save()) {
					$this->jusers[$record->person_id] = $newuser->id;
				} else {
					$this->jusers[$record->person_id] = $newuser->getError()."\nAdditional Info: name=".$record->firstname.' '.$record->lastname."\nEmail=".$record->email;
				}
			} else {
				JError::raiseWarning(404, JText::_('COM_ITTIMPORT_LOAD_STUDENT_FAILED_TOO_MANY_ROWS'));
				$this->jusers[$record->person_id] = JText::_('COM_ITTIMPORT_LOAD_STUDENT_FAILED_TOO_MANY_ROWS');
			}
		}
		return $this->jusers[$record->person_id];
	}

	/*
	 * getDefaultUserGroup function: Helper function to fetch the default Joomla user group
	 *
	 * Returns: Array containing the default joomla usergroup (this is the format required by the JUser object)
	 *
	 */
	private function getDefaultUserGroup() {
		if(!isset($this->newusergroup)) {
			jimport('joomla.application.component.helper');
			$users_config = JComponentHelper::getParams('com_users');
			$this->newusergroup = array($users_config->get('new_usertype',2));
		}
		return $this->newusergroup;
	}

	/*
	 * getOrder function: fetch the relevant VirtueMart orders for the given record, optionally creating it if it does not exist.
	 *
	 * Input: $record: The record you are fetching orders for.
	 *
	 * Returns a virtuemart order object for the given record.
	 *  - The order should not have been shipped or returned, and should have the same course_start_date and user as the record
	 *  - If the order cannot be found or created, return false
	 * Return an error string if you encounter any errors.
	 */
	private function getOrder($record) {
		if(empty($record->studentid)) return 'no user';
		if(empty($record->productids)) return 'no products';
		if(empty($record->course_start_date)) return 'no course start date';

		//Only orders with the following statuses should be returned:
		//Waiting for approval, duplicate, pending, ready to be shipped, invalid address, and backordered
		$sql = 'SELECT virtuemart_order_id
			 FROM #__virtuemart_orders
			 WHERE order_status IN ("M","D","P","C","A","B")
			   AND virtuemart_user_id='.$this->_db->quote($record->studentid).'
			   AND customer_note LIKE "%[[SHIP_DATE: '.$record->course_start_date.']]%"
			 ORDER BY virtuemart_order_id';
		$this->_db->setQuery($sql);
		$this->_db->query();
		$numrows = $this->_db->getNumRows();
		if($numrows != 0) {
			$orderModel = VmModel::getModel('orders');
			$orders = $this->_db->loadResultArray();
			$order_to_return = false;
			$orders_with_this_course = array();
			foreach($orders as $order_id) {
				$order = $orderModel->getOrder($order_id);
				//we want to return the order if:
				//1. The order already contains this course
				// OR
				//2. The order is not backordered AND the course is not being cancelled
				$courses = $this->getCourseIDs($order['details']['BT']->customer_note);
				if(in_array($record->course, $courses)) {
					$orders_with_this_course[] = $order_id;
					$order_to_return = $order;
				} elseif(!$order_to_return && !$record->cancelled && $order['details']['BT']->order_status != 'B') {
					$order_to_return = $order;
				}
			}
			if(count($orders_with_this_course) > 1) {
				$detail_links = array();
				foreach($orders_with_this_course as $order_id) {
					$detail_links[] = '<a href="'.$this->getOrderUrl($order_id).'">'.$order_id.'</a>';
				}
				return 'There are multiple orders to update. Please update these orders manually if needed: '.implode(', and ', $detail_links);
			}
			if($order_to_return) return $order_to_return;
		}

		//if we haven't found an order yet and the record is cancelled, we need to check for an already-shipped order
		if($record->cancelled) {
			$sql = 'SELECT virtuemart_order_id
			 FROM #__virtuemart_orders
			 WHERE order_status="S"
			   AND virtuemart_user_id='.$this->_db->quote($record->studentid).'
			   AND customer_note LIKE "%[[SHIP_DATE: '.$record->course_start_date.']]%"
			 ORDER BY virtuemart_order_id';
			$this->_db->setQuery($sql);
			$this->_db->query();
			$shipped_orders = $this->_db->loadColumn();
			if(!empty($shipped_orders)) {
				$orderModel = VmModel::getModel('orders');
				foreach($shipped_orders as $order_id) {
					$order = $orderModel->getOrder($order_id);
					//check to see if the order included the course that is being cancelled
					$courses = $this->getCourseIDs($order['details']['BT']->customer_note);
					if(in_array($record->course,$courses)) {
						//if the order has already been shipped, send an email alerting someone that a shipped order has been cancelled
						$config = JFactory::getConfig();
						$params = JComponentHelper::getParams('com_ittimport');
						$fromemail = $config->getValue('config.mailfrom');
						$fromname = $config->getValue('config.fromname');
						$emailto = explode(',',$params->get('sent_cancelled_recipients'));
						$subject = $params->get('sent_cancelled_subject');
						$message = $params->get('sent_cancelled_message');
						if(!empty($emailto) && !empty($subject) && !empty($message)) {
							if(strpos($message, '{PERSON_ID}') !== false) {
								$vm_user = JUser::getInstance($order['details']['BT']->virtuemart_user_id);
								$message = str_replace('{PERSON_ID}',$vm_user->username, $message);
							}
							$searchfields = array('{ORDER_ID}','{STATUS}','{ORDER_LINK}','{COURSE_NUM}');
							$replacefields = array($order_id, 'Shippped', $config->getValue('live_site').'/administrator/index.php?option=com_virtuemart&view=orders&task=edit&virtuemart_order_id='.$order_id,$record->course);
							$message = str_replace($searchfields, $replacefields, $message);
							$mailer = JFactory::getMailer();
							$mailer->sendMail($fromemail, $fromname, $emailto, $subject, $message);
						}
					}
				}
			}
		}

		//if we were not able to find an order
		//we should create one now if the record isn't cancelled
		if(!$record->cancelled) {
			return $this->createOrder($record);
		}

		//the record is cancelled and we were unable to locate an existing order with this course. Return false.
		return false;
	}

	private function updateAddresses(&$record) {
		if(!is_object($record->vmuser)) return 'Error: Missing Virtuemart User for Record.';

		$storedata = array();
		$storedata['virtuemart_user_id'] = $record->studentid;
		$storedata['agreed'] = 1;

		$usercountry = $this->get_country_id_from_code($record->country);
		$userstate = $this->get_state_id_from_code($usercountry, $record->state);
		//zip code for the USA should be 0-padded to 5 digits
		if($usercountry == $this->vcountries['USA'] && !empty($record->zip) && strlen($record->zip) < 5) {
			$record->zip = str_pad($record->zip, 5, '0', STR_PAD_LEFT);
		}

		$addressparts = array();
		if(!empty($record->address2)) $addressparts[] = trim($record->address2);
		if(!empty($record->address3)) $addressparts[] = trim($record->address3);

		$storedata['username'] = $record->person_id;
		$storedata['email'] = empty($record->email)?'--none--':$record->email;
		$storedata['first_name'] = empty($record->firstname)?'--none--':$record->firstname;
		$storedata['middle_name'] = $record->middlename;
		$storedata['last_name'] = empty($record->lastname)?'--none--':$record->lastname;
		$storedata['address_1'] = empty($record->address1)?'--none--':$record->address1;
		$storedata['address_2'] = empty($addressparts)?'':implode(' ',$addressparts);
		$storedata['zip'] = empty($record->zip)?'--none--':$record->zip;
		$storedata['city'] = empty($record->city)?'--none--':$record->city;
		$storedata['virtuemart_country_id'] = $usercountry?$usercountry:0;
		$storedata['virtuemart_state_id'] = $userstate?$userstate:0;
		$storedata['address_type'] = 'BT';
		$storedata['phone_1'] = $record->phone;

		$userinfos = $record->vmuser->userInfo;
		if(empty($userinfos)) {
			$vmusermodel = new VirtuemartModelUser();
			$vmusermodel->_id = $record->studentid;
			$vmusermodel->storeAddress($storedata);
			$record->vmuser = $vmusermodel->getUser();
		} else {
			$olduserinfo = reset($userinfos);

			$updateinfo = false;
			foreach($storedata as $key => $value) {
				if($olduserinfo->$key != $value) {
					$updateinfo = true;
				}
			}

			if($updateinfo) {
				$vmusermodel = new VirtuemartModelUser();
				$vmusermodel->_id = $record->vmuser->virtuemart_user_id;
				$vmusermodel->storeAddress($storedata);
				$record->vmuser = $vmusermodel->getUser();

				JPluginHelper::importPlugin('vmpayment');
				$dispatcher = JDispatcher::getInstance();
				$results_array = $dispatcher->trigger('plgVmOnAfterUpdateUserAddress',array($record->vmuser));
			}
		}
		return true;
	}

	/*
	 * processRecord function: creates/updates the VirtueMart user (student), and creates/modifies VirtueMart orders for a given record.
	 * This function is the workhorse of the component.
	 *
	 * Input: $record: The record you are processing
	 *
	 * Returns an array containing the status and details of the processing, or a string containing an error message on failure.
	 *
	 */
	private function processRecord($record) {
		if(!is_object($record)) {
			return 'Failed to process record. Record is not an object: '.var_export($record,true);
		}
		if(!is_object($record->vmuser)) {
			return 'Failed to process record due to invalid virtuemart user.';
		}
		$statuses = array();
		$details = array();

		if(empty($record->productids)) {
			$statuses[] = 'skipped';
			$details[] = 'No products are associated with this course.';
		} elseif($record->cancelled) {
			$result = $this->cancelRecord($record);
			$statuses = $result['statuses'];
			$details = $result['details'];
		} else {
			if(!$record->order_id) {
				return 'Failed to process record. Order is invalid.';
			}
			$orderModel = VmModel::getModel('orders');

			//iterate through all of the products
			foreach($record->productids as $productid) {
				$has_product = false;
				foreach($record->order['items'] as $item) {
					if($item->virtuemart_product_id == $productid) {
						$has_product = true;
						break;
					}
				}

				if($has_product) {
					$statuses[] = 'updated';
					$details[] = 'Order <a href="'.$this->getOrderUrl($record->order_id).'">'.$record->order_id.'</a>';
				} else {
					//we have to add the product
					$productModel = VmModel::getModel('product');
					$product = $productModel->getProduct($productid);
					if(isset($product->virtuemart_order_item_id)) unset($product->virtuemart_order_item_id);
					$product->virtuemart_order_id = $record->order_id;
					$product->order_status = $record->order['details']['BT']->order_status;
					$product->product_quantity = $product->quantity;
					$product->order_item_sku = $product->product_sku;
					$product->order_item_name = $product->product_name;
					$result = $orderModel->saveOrderLineItem($product);
					if($result !== true) {
						$detailstring = 'Failed to add product '.$productid.' to order with ID <a href="'.$this->getOrderUrl($record->order_id).'">'.$record->order_id.'</a>';
						if(is_string($result)) $detailstring.': '.$result;
						$details[] = $detailstring;
						$statuses[] = 'errored';
					} else {
						$statuses[] = 'added';
						$details[] = 'Added product '.$productid.' to order wtih ID <a href="'.$this->getOrderUrl($record->order_id).'">'.$record->order_id.'</a>';
						$record->order['items'][] = $product;
					}
				}
			}

			$customer_note = $record->order['details']['BT']->customer_note;
			$this->saveCourseId($record->course, $customer_note);
			if(in_array('added',$statuses)) {
				$history_comment = 'Course '.$record->course.' added to order from file '.$record->filename;
			} else {
				$history_comment = 'Order updated from file '.$record->filename;
			}
			$orderModel->updateStatusForOneOrder($record->order_id, array('customer_note'=>$customer_note, 'customer_notified'=>0, 'comments'=>$history_comment));
		}

		if(empty($statuses)) {
			return 'Update did not return any useful feedback.';
		} elseif(in_array('errored',$statuses)) {
			return implode("\n",$details);
		} elseif (in_array('cancelled',$statuses)) {
			$recordstatus = 'cancelled';
		} elseif (in_array('added',$statuses)) {
			$recordstatus = 'added';
		} elseif(in_array('updated',$statuses)) {
			$recordstatus = 'updated';
		} else {
			$recordstatus = $statuses[0];
		}
		return array('status'=>$recordstatus, 'details'=>implode("\n",$details));
	}

	private function cancelRecord(&$record) {
		$details = array();
		$statuses = array();
		if(!$record->order_id) {
			$details[] = 'Did not find any existing order to cancel. Skipping';
			$statuses[] = 'skipped';
		} else {
			$orderModel = VmModel::getModel('orders');
			$record_was_cancelled = false;

			//iterate through all of the products
			foreach($record->productids as $productid) {
				//first, let's find out if the product is already included in the order or not
				$order_product = false;
				$order_cursor = -1;
				foreach($record->order['items'] as $i => $item) {
					if($item->virtuemart_product_id == $productid) {
						$order_product = $item;
						$order_cursor = $i;
					}
				}

				//if the product is in the order
				if($order_product) {
					//if the product is also connected to another course on the same order, we do not want to delete it
					$product_is_in_other_course = false;
					if(is_array($record->order['courses'])) {
						foreach($record->order['courses'] as $course) {
							if($course != $record->course) {
								$course_products = $this->getProductIds($course);
								if(in_array($productid, $course_products)) {
									$product_is_in_other_course = $course;
								}
							}
						}
					}
					if($product_is_in_other_course) {
						$statuses[] = 'skipped';
						$details[] = 'Product '.$productid.' is also required for course '.$product_is_in_other_course.' which is also in this order. Skipping its removal.';
						$record_was_cancelled = true;
					} else {
						if($orderModel->removeOrderLineItem($order_product->virtuemart_order_item_id)) {
							$orderModel->handleStockAfterStatusChangedPerProduct('X', $order_product->order_status,$order_product, $order_product->product_quantity);
							unset($record->order['items'][$order_cursor]);
							$statuses[] = 'cancelled';
							$details[] = 'Removed product '.$productid.' from order <a href="'.$this->getOrderUrl($record->order_id).'">'.$record->order_id.'</a>';
							$record_was_cancelled = true;
						} else {
							$statuses[] = 'errored';
							$details[] = 'Failed to remove product '.$productid.' from order <a href="'.$this->getOrderUrl($record->order_id).'">'.$record->order_id.'</a>';
						}
					}
				} else {
					//the product is already not in the order.
					$statuses[] = 'skipped';
					$details[] = 'Order <a href="'.$this->getOrderUrl($record->order_id).'">'.$record->order_id.'</a> did not contain product '.$productid.' to cancel. Skipping.';
				}
			}
			if($record_was_cancelled) {
				$customer_note = $record->order['details']['BT']->customer_note;
				$this->removeCourseId($record->course, $customer_note);
				$history_comment = 'Course '.$record->course.' removed from order by file '.$record->filename;
				$data = array('customer_note'=>$customer_note, 'customer_notified' => 0, 'comments' => $history_comment);
				if(empty($record->order['items'])) {
					//if we have removed all of the order items, change the order status
					$data['order_status'] = ($record->order['details']['BT']->order_status=='B')?'X':'P';
				}
				$orderModel->updateStatusForOneOrder($record->order_id, $data);
			}
		}
		return array('statuses'=>$statuses, 'details'=>$details);
	}


	private function saveShipDate($shipdate, &$string) {
		$ship_date_string = '[[SHIP_DATE: '.date('m/d/Y',strtotime($shipdate)).']]';
		if(strpos($string, $ship_date_string) === false) {
			if(empty($string)) {
				$string = $ship_date_string;
			} else {
				$string .= "\n$ship_date_string";
			}
		}
	}
	private function getShipDate($string) {
		$has_ship_date = preg_match('/\[\[SHIP_DATE\: ((0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])\/(20[1-2][0-9]))\]/',$string,$matches);
		return $has_ship_date?$matches[1]:'';
	}
	private function getCourseIDs($string) {
		$has_course = preg_match_all('/\[\[COURSE_NUMBER\: ([A-Za-z0-9 ]*)\]/',$string,$matches);
		return ($has_course)?$matches[1]:array();
	}
	private function removeCourseId($course, &$string) {
		$string = preg_replace('/\[\[COURSE_NUMBER\: '.$course.'\](\[COURSE_START_DATE\: ((0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])\/(20[1-2][0-9]))\])?\]/','',$string);
	}
	private function saveCourseId($course, &$string) {
		if(strpos($string, "[[COURSE_NUMBER: $course]") === false) {
			if(empty($string)) {
				$string = "[[COURSE_NUMBER: $course]]";
			} else {
				$string .= "\n[[COURSE_NUMBER: $course]]";
			}
		}
	}

	/*
	 * fetchVirtuemartUser function: returns the virtuemart user for the given record.
	 * Creates the VM user if they do not exist.
	 * Updates the VM user if they do exist and their details have changed
	 *
	 * Input: $record: The record containing the user information, including the joomla user_id
	 * Of particular interest for this function:
	 * 		$record->studentid should contain the Joomla user_id
	 * 		$record->firstname
	 * 		$record->middlename
	 * 		$record->lastname
	 * 		$record->address1
	 * 		$record->address2
	 * 		$record->address3
	 * 		$record->city
	 * 		$record->state
	 * 		$record->zip
	 * 		$record->country
	 * 		$record->phone
	 * 		$record->email
	 *
	 * Return the Virtuemart user on success, a string containing an error message on failure
	 */
	private function fetchVirtuemartUser($record) {
		//first we get the user, creating them if they do not already exist
		$vmusermodel = new VirtuemartModelUser();
		$vmusermodel->_id = $record->studentid;
		$vmuser = $vmusermodel->getUser();
		if(empty($vmuser->customer_number)){
			$storedata = get_object_vars(JFactory::getUser($record->studentid));
			$storedata['virtuemart_user_id'] = $record->studentid;
			$storedata['agreed'] = 1;
			if(!$vmusermodel->saveUserData($storedata)) {
				return JText::sprintf('COM_ITTIMPORT_VIRTUEMART_USER_SAVE_FAILURE', $vmusermodel->getError());
			}
			$vmuser = $vmusermodel->getUser();
		}

		/*
		$usercountry = $this->get_country_id_from_code($record->country);
		$userstate = $this->get_state_id_from_code($usercountry, $record->state);

		$addressparts = array();
		if(!empty($record->address2)) $addressparts[] = trim($record->address2);
		if(!empty($record->address3)) $addressparts[] = trim($record->address3);
		if($record->person_id) $storedata['username'] = $record->person_id;
		if($record->email) $storedata['email'] = $record->email;
		if($record->firstname) $storedata['first_name'] = $record->firstname;
		if($record->middlename) $storedata['middle_name'] = $record->middlename;
		if($record->lastname) $storedata['last_name'] = $record->lastname;
		if($record->address1) $storedata['address_1'] = $record->address1;
		if(!empty($addressparts)) $storedata['address_2'] = implode(' ',$addressparts);
		if($record->zip) $storedata['zip'] = $record->zip;
		if($record->city) $storedata['city'] = $record->city;
		if($usercountry) $storedata['virtuemart_country_id'] = $usercountry;
		if($userstate) $storedata['virtuemart_state_id'] = $userstate;
		$storedata['address_type'] = 'BT';
		if($record->phone) $storedata['phone_1'] = $record->phone;

		$vmusermodel->storeAddress($storedata);
		$vmuser = $vmusermodel->getUser();

		JPluginHelper::importPlugin('vmpayment');
		$dispatcher = JDispatcher::getInstance();
		$results_array = $dispatcher->trigger('plgVmOnAfterUpdateAddress',array($vmuser, $storedata));
		*/
		return $vmuser;
	}

	private function get_country_id_from_code($code) {
		if(empty($this->vcountries)) {
			$this->_db->setQuery('SELECT virtuemart_country_id, country_3_code, country_2_code, country_name FROM #__virtuemart_countries WHERE published=1');
			$rows = $this->_db->loadAssocList();
			foreach($rows as $row) {
				$this->vcountries[strtoupper($row['country_name'])] = $row['virtuemart_country_id'];
				$this->vcountries[$row['country_2_code']] = $row['virtuemart_country_id'];
				$this->vcountries[$row['country_3_code']] = $row['virtuemart_country_id'];
			}
			//force the United States to be the default country (if no country is specified)
			$this->vcountries[''] = $this->vcountries['USA'];
		}
		if(!isset($this->vcountries[$code])) {
			$this->vcountries[$code] = array_key_exists('--INVALID--',$this->vcountries)?$this->vcountries['--INVALID--']:(array_key_exists('--NONE--',$this->vcountries)?$this->vcountries['--NONE--']:false);
		}
		return $this->vcountries[$code];
	}

	private function get_state_id_from_code($country, $code) {
		if(!$country) return false;
		if(empty($this->vstates)) {
			$this->_db->setQuery('SELECT virtuemart_state_id, virtuemart_country_id, state_2_code, state_3_code, state_name FROM #__virtuemart_states WHERE published=1');
			$rows = $this->_db->loadAssocList();
			foreach($rows as $row) {
				$this->vstates[$row['virtuemart_country_id']][strtoupper($row['state_name'])] = $row['virtuemart_state_id'];
				$this->vstates[$row['virtuemart_country_id']][$row['state_2_code']] = $row['virtuemart_state_id'];
				$this->vstates[$row['virtuemart_country_id']][$row['state_3_code']] = $row['virtuemart_state_id'];
			}
		}
		if(!isset($this->vstates[$country]) || !isset($this->vstates[$country][$code])) {
			$this->vstates[$country][$code] = false;
		}
		return $this->vstates[$country][$code];
	}

	private function getOrderUrl($order_id) {
		return rtrim(JFactory::getConfig()->getValue('live_site'),'/').'/administrator/index.php?option=com_virtuemart&view=orders&task=edit&virtuemart_order_id='.$order_id;
	}

	/*
	 * createOrder function: Creates an empty order for the given record.
	 * We do not need to worry about the order status or about calling any plugin methods since those will be called later.
	 *
	 * Input: $record: The record from which to fetch information about the order to be created
	 *
	 * Return the new order object on success, or a string containing an error message on failure
	 */
	private function createOrder($record, $status = null) {
		if(empty($record->vmuser)) return 'Virtuemart User needed';

		$session = JFactory::getSession();
		$session->set('user',JFactory::getUser($record->studentid));
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		$cart->BT=0;
		$cart->BTaddress = 0;

		foreach($record->vmuser->userInfo as $address) {
			if ($address->address_type == 'BT') {
				$cart->saveAddressInCart((array) $address, $address->address_type,false);
			}
		}
		$cart->prepareAddressDataInCart('BT',true);

		$cart->tosAccepted = 1;
		$cart->cartData = $cart->prepareCartData(true);
		$cart_prices = $cart->getCartPrices(true);
		$cart->CheckAutomaticSelectedPayment($cart_prices,true);
		//$cart->virtuemart_shipmentmethod_id=1;	//$cart->CheckAutomaticSelectedShipment($cart_prices,true);
		$cart->setCartIntoSession();
		$cart->customer_comment = '';
		$this->saveShipDate($record->course_start_date, $cart->customer_comment);
		$this->saveCourseId($record->course, $cart->customer_comment);

		$orderModel = VmModel::getModel('orders');
		if (($orderID = $orderModel->createOrderFromCart($cart)) === false) {
			$session->set('user',JFactory::getUser($this->user_id)); //reset the user in the session to the correct user
			VirtueMartCart::removeCartFromSession();
			JError::raiseWarning(500, 'No order created '.$orderModel->getError());
			return 'No order created '.$orderModel->getError();
		}

		//If we are instructed to do so, let's update the order status
		if(!is_null($status)) {
			$history_comment = 'Status was automatically updated to '.$status;
			$orderModel->updateStatusForOneOrder($orderID,array('order_status'=>$status,'customer_notified'=>0,'comments'=>$history_comment),false);
		}
		$order= $orderModel->getOrder($orderID);

		$dispatcher = JDispatcher::getInstance();
		JPluginHelper::importPlugin('vmpayment');
		$dispatcher->trigger('plgVmConfirmedOrder', array($cart, $order));

		/*$dispatcher = JDispatcher::getInstance();
		JPluginHelper::importPlugin('vmshipment');
		JPluginHelper::importPlugin('vmcustom');
		JPluginHelper::importPlugin('vmpayment');
		JPluginHelper::importPlugin('vmcalculation');

		ob_start();
		$returnValues = $dispatcher->trigger('plgVmConfirmedOrder', array($cart, $order));
		ob_end_clean();
		*/

		VirtueMartCart::removeCartFromSession();
		$session->set('user',JFactory::getUser($this->user_id));
		//Set the created_by and modified_by fields on the orders to the current user for reporting purposes
		$this->_db->setQuery('UPDATE #__virtuemart_orders SET created_by='.$this->_db->quote($this->user_id).', modified_by='.$this->_db->quote($this->user_id).', created_on='.$this->_db->quote($record->ordered_on).' WHERE virtuemart_order_id='.$this->_db->quote($orderID));
		$this->_db->query();

		return $order;
	}

	/*
	 * updateOrder function: Updates the given order in Virtuemart. DO NOT update the order status as that is taken care of in the payment plugin
	 *
	 * Input: $order: The order to be updated
	 * Input: $record: The record containing the new information with which to update the order (will not have to change the product on the order)
	 *
	 * Return true on success or a string containing an error message on failure
	 */
/*
	private function updateOrder($order, $record) {
		if(empty($order['details']['BT']->virtuemart_order_id)) return 'Invalid Order';

		$virtuemart_country_id = $this->get_country_id_from_code($record->country);
		$virtuemart_state_id = $this->get_state_id_from_code($virtuemart_country_id, $record->state);

		$addressparts = array();
		if(!empty($record->address2)) $addressparts[] = trim($record->address2);
		if(!empty($record->address3)) $addressparts[] = trim($record->address3);

		$updatefields = array();
		if(!empty($record->email)) $updatefields['email'] = $record->email;
		if(!empty($record->firstname)) $updatefields['first_name'] = $record->firstname;
		if(!empty($record->middlename)) $updatefields['middle_name'] = $record->middlename;
		if(!empty($record->lastname)) $updatefields['last_name'] = $record->lastname;
		$updatefields['address_1'] = $record->address1;
		if(!empty($addressparts)) $updatefields['address_2'] = implode(' ',$addressparts);
		$updatefields['zip'] = $record->zip;
		$updatefields['city'] = $record->city;
		if(!empty($record->phone)) $updatefields['phone_1'] = $record->phone;
		if($virtuemart_country_id) $updatefields['virtuemart_country_id'] = $virtuemart_country_id;
		if($virtuemart_state_id) $updatefields['virtuemart_state_id'] = $virtuemart_state_id;

		$updateparts = array();
		foreach($updatefields as $key => $value) {
			$updateparts[] = $key.' = '.$this->_db->quote($value);
		}

		$sql = 'UPDATE #__virtuemart_order_userinfos SET '.implode(', ',$updateparts)
			  .' WHERE virtuemart_order_id='.$order['details']['BT']->virtuemart_order_id.' AND address_type="BT"';//printrx($sql);
		$this->_db->setQuery($sql);
		$this->_db->query();
		if($error = $this->_db->getErrorMsg()) {
			return $error;
		}

		return true;
	}

*/


	/*
	 * Begin the field transformation and validation functions
	 *
	 * If no transformation function is present for a field, it will not be transformed
	 * If no validation function is present for a field, it will not be validated
	 *
	 * For transform functions, return the transformed value
	 * For validate functions, return true if the value is valid or false if it is invalid
	 *
	 */

	private function old_person_id_transform($value) {
		return str_pad(trim($value), 8, '0', STR_PAD_LEFT);
	}
	private function person_id_transform($value) {
		return str_pad(trim($value), 8, '0', STR_PAD_LEFT);
	}
	private function person_id_validate($value) {
		return preg_match('/^[0-9]{6,8}$/', $value);
	}
	private function firstname_transform($value) {
		return trim($value);
	}
	private function middlename_transform($value) {
		return trim($value);
	}
	private function lastname_transform($value) {
		return trim($value);
	}
	private function address1_transform($value) {
		return trim($value);
	}
	private function address2_transform($value) {
		return trim($value);
	}
	private function address3_transform($value) {
		return trim($value);
	}
	private function city_transform($value) {
		return trim($value);
	}
	private function state_transform($value) {
		return strtoupper(trim($value));
	}
	private function state_validate($value) {
		return preg_match('/^([A-Z]{2,3}|[A-Z]{3,15}( [A-Z]{2,15}){0,6})$/',$value);
	}
	private function country_transform($value) {
		return strtoupper(trim($value));
	}
	private function country_validate($value) {
		return preg_match('/^([A-Z]{2,3}|[A-Z]{3,15}( [A-Z]{2,15}){0,6})$/',$value);
	}
	private function zip_transform($value) {
		return trim($value);
	}
	private function phone_transform($value) {
		return str_replace(array(' ','-','/','\\',')','('), '', $value);
	}
	private function phone_validate($value) {
		return preg_match('/^[0-9]{10,11}$/', $value);
	}
	private function email_validate($value) {
		return (filter_var($value, FILTER_VALIDATE_EMAIL) !== FALSE);
	}
	private function email_transform($value) {
		return trim($value);
	}
	private function course_transform($value) {
		return trim($value);
	}
	private function course_validate($value) {
		return preg_match('/^[0-9a-zA-Z]{3,8}$/', $value);
	}
	private function course_start_date_transform($value) {
		return $this->itt_date_transform($value);
	}
	private function course_start_date_validate($value) {
		return preg_match('/^(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])\/201[3-9]$/', $value);
	}
	private function cancelled_transform($value) {
		return (trim($value)==false)?'0':'1';
	}
	private function ordered_on_transform($value) {
		//return $this->itt_date_transform($value); //format to MYsql style date
		return date('Y-m-d 00:00:00',strtotime($value));
	}
	private function ordered_on_validate($value) {
		return preg_match('/^(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])\/201[3-9]$/', $value);
	}

	//Transform dates from the format '1-APR-13' to '04/01/2013'
	private function itt_date_transform($value) {
		return date('m/d/Y',strtotime($value));
	}
}
