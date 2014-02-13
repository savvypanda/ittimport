<?php defined ('_JEXEC') or die('Restricted access');

if(!class_exists ('vmPSPlugin')) require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
if(!class_exists( 'VmConfig' )) require(JPATH_ADMINISTRATOR.'/components/com_virtuemart/helpers/config.php');
if(!class_exists('VirtueMartModelOrders')) require(JPATH_VM_ADMINISTRATOR.'/models/orders.php');

class plgVmPaymentIttbooks extends vmPSPlugin {
	private $newnote = '';
	//private $cron_username, $cron_shipper;
	private $badcountries = array();
	//private $virtual_categories = array();
	private $products_to_ignore = array();

	function __construct(&$subject, $config) {
		parent::__construct($subject, $config);
		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = $this->getVarsToPush();
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

		$db = JFactory::getDbo();

		//getting the cron users
		$ittimport_params = JComponentHelper::getParams('com_ittimport');
		$virtual_categories = explode(',',str_replace(' ','',$ittimport_params->get('virtual_product_category','')));
		$num_virtual_categories = count($virtual_categories);
		if($num_virtual_categories > 0) {
			$query = 'SELECT DISTINCT virtuemart_product_id FROM #__virtuemart_product_categories pce WHERE pce.virtuemart_category_id ';
			if($num_virtual_categories == 1) {
				$query .= '='.$db->quote($virtual_categories[0]);
			} else {
				foreach($virtual_categories as &$cat) $cat = $db->quote($cat);
				$query .= 'IN('.implode(',',$virtual_categories).')';
			}
			$db->setQuery($query);
			$this->products_to_ignore = $db->loadColumn();
		}

		//getting the invalid country IDs
		$query = 'SELECT virtuemart_country_id FROM #__virtuemart_countries
				  WHERE published<>1 OR country_name="--none--" OR country_name="--invalid--"';
		$db->setQuery($query);
		$this->badcountries = $db->loadColumn();
	}

	//functions that need to be overwritten for this to work.
	public function getVmPluginCreateTableSQL(){
		return $this->createTableSQL('ITT Books Fake Payment Table');
	}
	function getTableSQLFields(){
		return array(
			'id'                          => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(11) UNSIGNED',
			'order_number'                => 'char(64)',
			'virtuemart_paymentmethod_id' => 'mediumint(11) UNSIGNED',
			'payment_name'                => 'varchar(50)'
		);
	}
	function plgVmDeclarePluginParamsPayment($name,$id,&$data){
		//for displaying the plugin parameters in VirtueMart
		return $this->declarePluginParams('payment', $name, $id, $data);
	}
	function plgVmSetOnTablePluginParamsPayment($name,$id,&$table){
		//for saving the plugin parameters in VirtueMart
		return $this->setOnTablePluginParams ($name, $id, $table);
	}
	function plgVmOnCheckAutomaticSelectedPayment($cart,$prices=array(),&$counter){
		//so that we can automatically apply this payment method to orders.
		return $this->onCheckAutomaticSelected($cart,$prices,$counter);
	}
	protected function checkConditions($cart, $method, $cart_prices){
		//this payment method may always be applied.
		return true;
	}
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id){
		//to create the fake payment table
		return $this->onStoreInstallPluginTable($jplugin_id);
	}
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
		//since we are not taking payment, the payment details are always valid.
		return true;
	}
	/* public function plgVmonSelectedCalculatePricePayment($cart, $cart_prices, $cart_prices_name) {
		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	} */

	//function for when an order is created/confirmed.
	function plgVmConfirmedOrder ($cart, $order) {
		if(!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}

		$orderid = $order['details']['BT']->virtuemart_order_id;
		$orderstatus = $order['details']['BT']->order_status;

		$setstatus = $this->_checkOrderStatus($orderstatus, $orderid, $method);
		if($setstatus != $order['details']['BT']->order_status) {
			if(!$this->_updateOrderStatus($setstatus,$orderid)) {
				return false;
			}
		}

		$this->_testSendEmail($setstatus, $method, $order['details']['BT']->virtuemart_user_id, $orderid);

		$cart->emptyCart();
		return true;
	}

	//function for when an order status changes
	function plgVmOnUpdateOrderPayment($data, $old_order_status) {
		if(!($method = $this->getVmPluginMethod($data->virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if(!$this->selectedThisElement($method->payment_element)) {
			return FALSE;
		}

		//using this variable to update the customer_note field if needed
		$this->newnote = '';
		$setstatus = $this->_checkOrderStatus($data->order_status, $data->virtuemart_order_id, $method);
		if($setstatus != $data->order_status) {
			if(!$this->_updateOrderStatus($setstatus, $data->virtuemart_order_id)) {
				return false;
			}
		}
		$data->order_status = $setstatus;
		//if the order is a duplicate, set the customer_note here.
		if(!empty($this->newnote)) {
			$data->customer_note = $this->newnote;
		}

		//send an email if the order status has changed and we are configured to send and email for the new status.
		if($setstatus != $old_order_status) {
			$this->_testSendEmail($setstatus, $method, $data->virtuemart_user_id, $data->virtuemart_order_id);
		}
	}

	function plgVmOnUpdateSingleItem($old_data, $new_data) {
		//if(isset($new_data->virtuemart_paymentmethod_id)) return NULL; //we do not want to run this code if the entire order is being updated, since that will happen in the plgVmOnUpdateOrderPayment method
		if($new_data->order_status == $old_data->order_status) return NULL; //we do not want to do anything if the status is not changing
		//if($new_data->order_status != 'T' && $old_data->order_status != 'X' && $old_data->order_status != 'M') return NULL; //we are only interested in orders that need to be returned, are cancelled, or require manager approval

		$db = JFactory::getDbo();
		$query = 'SELECT virtuemart_paymentmethod_id, virtuemart_user_id FROM #__virtuemart_orders WHERE virtuemart_order_id='.$db->quote($new_data->virtuemart_order_id);
		$db->setQuery($query);
		$order = $db->loadObject();

		if(!($method = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if(!$this->selectedThisElement($method->payment_element)) {
			return FALSE;
		}

		$updating_single_item = !isset($new_data->virtuemart_paymentmethod_id); //helper variable

		//if the order item was cancelled or required manager approval, only allow a manager to change the status, and only by updating this item specifically
		if($old_data->order_status == 'X' || $old_data->order_status == 'M') {
			if(!$updating_single_item && $old_data->order_status == 'X') return false;
			$user = JFactory::getUser();
			$is_manager = $this->_testIfUserIsManager($user, $method);
			if(!$is_manager) return false;
		}
		//only allow managers to 'regress' the orders status, and only when updating this item specifically
		//From: Shipped, Irretrievable Delivery, Return Label Sent, Return Requested by SSC, or Returned
		//To: Backordered, Invalid Address, Duplicate, Cancelled, Ready to Ship, Requires Manager Approval, or Pending
		if(in_array($old_data->order_status,array('S','I','W','T','R')) && in_array($new_data->order_status,array('B','A','D','X','C','M','P'))) {
			if(!$updating_single_item) return false;
			$user = JFactory::getUser();
			$is_manager = $this->_testIfUserIsManager($user, $method);
			if(!$is_manager) return false;
		}

		//unless we are updating the status on the entire order, let's send an email if appropriate
		//(eg: if the order item needs to be returned)
		//global $skip_email_on_order_item;
		//if(!isset($skip_email_on_order_item) || !$skip_email_on_order_item) {
		if($updating_single_item) {
			$this->_testSendEmail($new_data->order_status, $method, $order->virtuemart_user_id, $new_data->virtuemart_order_id);
		}
	}

	function plgVmOnAfterUpdateUserAddress($vmuser) {
		$db = JFactory::getDbo();
		$address = get_object_vars(reset($vmuser->userInfo));
		$address_is_invalid = $this->_testForInvalidAddress($address, false);

		if(isset($address['virtuemart_order_userinfo_id'])) unset($address['virtuemart_order_userinfo_id']);
		if(isset($address['virtuemart_user_id'])) unset($address['virtuemart_user_id']);
		if(isset($address['virtuemart_order_id'])) unset($address['virtuemart_order_id']);
		if(isset($address['address_type'])) unset($address['address_type']);
		if(isset($address['address_type_name'])) unset($address['address_type_name']);

		//we want to update the address on all other "current" orders for this user.
		//Waiting for approval, duplicate, pending, ready to be shipped, invalid address, and backordered
		$updatable_statuses = array('M'=>'"M"','D'=>'"D"','P'=>'"P"','C'=>'"C"','A'=>'"A"','B'=>'"B"');
		$query = 'SELECT u.*, o.*
			 FROM #__virtuemart_orders o
			 JOIN #__virtuemart_order_userinfos u ON u.virtuemart_order_id=o.virtuemart_order_id
			 WHERE o.order_status IN ('.implode(',',$updatable_statuses).')
			   AND o.virtuemart_user_id='.$db->quote($vmuser->virtuemart_user_id).'
			   AND u.address_type = "BT"
			 ORDER BY o.virtuemart_order_id';

		$db->setQuery($query);
		$orders_to_update = $db->loadAssocList();
		if(!empty($orders_to_update)) {
			foreach($orders_to_update as $order) {
				$newaddress = array_merge($order, $address);

				//update the address
				$ordermodel = VmModel::getModel('orders');
				$userinfotable = $ordermodel->getTable('order_userinfos');
				$userinfotable->bindChecknStore($newaddress);

				//and update the status on the order, if necessary
				if(!$address_is_invalid && $order['order_status'] == 'A') {
					//set the status to 'P' (pending)
					$this->_updateOrderStatus('P',$order['virtuemart_order_id']);
				} elseif($address_is_invalid && $order['order_status'] != 'A' && $order['order_status'] != 'D' && $order['order_status'] != 'M') {
					//set the status to 'A' and send an email about it (invalid address)
					$method = $this->getVmPluginMethod($order['virtuemart_paymentmethod_id']);
					$this->_updateOrderStatus('A',$order['virtuemart_order_id']);
					$this->_testSendEmail('A',$method,$order['virtuemart_user_id'],$order['virtuemart_order_id']);
				}
			}
		}
	}

	private function _updateOrderStatus($status, $order_id, $history_comment='') {
		if(!$status) return false;
		$orderModel = VmModel::getModel('orders');
		if(empty($history_comment)) $history_comment = 'Status was automatically updated to '.$status;
		$returval = $orderModel->updateStatusForOneOrder($order_id,array('order_status'=>$status,'customer_notified'=>0,'comments'=>$history_comment),false);
		return $returval;
	}

	private function _checkOrderStatus($status, $order_id, $method) {
		//start by fetching our common variables
		$user = JFactory::getUser();
		$is_manager = $this->_testIfUserIsManager($user, $method);
		$is_batch_import = $this->_testIfBatchImport();
		$is_auto_shipping = $this->_testIfAutoShipping();

		if(!class_exists('VmConfig')) require JPATH_ADMINISTRATOR.'/components/com_virtuemart/helpers/config.php';
		if(!class_exists('VirtueMartModelOrders')) require(JPATH_VM_ADMINISTRATOR.'/models/orders.php');
		if(!class_exists('VirtueMartModelProduct')) require(JPATH_VM_ADMINISTRATOR.'/models/product.php');
		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($order_id);

		//in all cases, if the previous status of this order was M (Requires Mgr. Approval), do not allow the status to be changed unless the user is a manager
		$oldstatus = $order['details']['BT']->order_status;
		if($oldstatus == 'M' && !$is_manager) {
			return 'M';
		}

		//other than when the old status was 'M', we only need to double-check the status if it is being set to pending, ready to be shipped, or backordered
		if($status != 'P' && $status != 'C' && $status != 'B') {
			return $status;
		}

		//1. check for duplicate
		//Skip if it is a manager or the cron shipper (managers are allowed to submit duplicates)
		if(!$is_manager && !$is_auto_shipping) {
			//cancel the item and do not update the order status if it is the batch import.
			//otherwise do not cancel the item but do update the order status
			if($is_batch_import) {
				$this->_testForDuplicateItems($order, true);
			} else {
				if($this->_testForDuplicateItems($order, false)) return 'D';
			}
		}

		//2. check for invalid address
		//Even managers cannot mail a package to an invalid address -- logic update 09/12/2013 -> managers can now mail to an invalid address
		if(!$is_manager && $this->_testForInvalidAddress($order)) return 'A';

		//3. check for if it needs manager approval
		//Only required for SSCs (skip if this is a batch import, manager, or cron shipper)
		if(!$is_batch_import && !$is_manager && !$is_auto_shipping) {
			if($this->_testForManagerApproval($order, $method)) return 'M';
		}

		//it passed all of our checks, it has the right status
		return $status;
	}

	private function _testIfUserIsManager($user, $method) {
		$is_manager = false;
		$manager_groups = $method->admingroups;
		$usergroups = $user->getAuthorisedGroups();
		foreach($usergroups as $ug) {
			if(in_array($ug,$manager_groups)) {
				$is_manager = true;
			}
		}
		return $is_manager;
	}

	private function _testIfBatchImport() {
		global $is_ittbooks_batch_import;
		return (isset($is_ittbooks_batch_import) && $is_ittbooks_batch_import);
	}
	private function _testIfAutoShipping() {
		global $is_ittbooks_auto_shipping;
		return (isset($is_ittbooks_auto_shipping) && $is_ittbooks_auto_shipping);
	}

	private function _testForDuplicateItems($order, $cancel_item = false) {
		$db = JFactory::getDbo();

		$productids = array();
		$statuses_to_ignore = array('W'=>'"W"','T'=>'"T"','R'=>'"R"','X'=>'"X"');
		$is_duplicate = false;
		foreach($order['items'] as $i) if($i->virtuemart_product_id && !in_array($i->order_status, $statuses_to_ignore) && !in_array($i->virtuemart_product_id, $this->products_to_ignore)) $productids[] = $i->virtuemart_product_id;
		if(empty($productids) || !is_object($order['details']['BT'])) return false;

		//preg_match('/\[\[SHIP_DATE\: (\d{2}\/\d{2}\/\d{4})\]\]/', $order['details']['BT']->customer_note, $match);
		//$ship_date = !empty($match[1])?$match[1]:'';
		$sql = 'SELECT DISTINCT o.*, i.virtuemart_product_id, i.order_item_name
			  FROM #__virtuemart_orders o
			  JOIN #__virtuemart_order_items i ON i.virtuemart_order_id=o.virtuemart_order_id
			 WHERE o.order_status NOT IN ('.implode(',',$statuses_to_ignore).')
			   AND i.order_status NOT IN ('.implode(',',$statuses_to_ignore).')
			   AND o.virtuemart_user_id='.$db->quote($order['details']['BT']->virtuemart_user_id).'
			   AND i.virtuemart_product_id IN ('.implode(',',$productids).')
			   AND o.virtuemart_order_id<>'.$db->quote($order['details']['BT']->virtuemart_order_id).'
			 ORDER BY o.virtuemart_order_id DESC';
		$db->setQuery($sql);
		$rows = $db->loadObjectList();
		foreach($rows as $row) {
			//For duplicate orders, we need to reference the order that it duplicates.
			$duplicate_description='Item '.$row->order_item_name.' was already shipped to user on order '.$row->virtuemart_order_id.', which was created on '.substr($row->created_on,0,10).": ".rtrim(JFactory::getConfig()->getValue('live_site'),'/').'/administrator/index.php?option=com_virtuemart&view=orders&task=edit&virtuemart_order_id='.$row->virtuemart_order_id;
			$this->_updateOrderStatus($order['details']['BT']->order_status, $order['details']['BT']->virtuemart_order_id, $duplicate_description);

			if($cancel_item) {
				$sql = 'UPDATE #__virtuemart_order_items SET order_status="X" WHERE virtuemart_order_id='.$db->quote($order['details']['BT']->virtuemart_order_id).' AND virtuemart_product_id='.$row->virtuemart_product_id;
				$db->setQuery($sql);
				$db->query();
			}
			$is_duplicate = true; //it's a duplicate order
		}

		return $is_duplicate;
	}

	private function _testForInvalidAddress($data, $is_order = true) {
		if($is_order) {
			if(!is_object($data['details']['BT'])) return true;
			$address = get_object_vars($data['details']['BT']);
		} else {
			if(is_array($data)) {
				$address = $data;
			} else {
				$address = get_object_vars($data);
			}
		}

		if(empty($address['virtuemart_country_id']) || in_array($address['virtuemart_country_id'], $this->badcountries)
		   || empty($address['first_name']) || $address['first_name'] == '--none--'
		   || empty($address['last_name']) || $address['last_name'] == '--none--'
		   || empty($address['address_1']) || $address['address_1'] == '--none--'
		   || empty($address['zip']) || $address['zip'] == '--none--'
		   || empty($address['city']) || $address['city'] == '--none--'
		   || empty($address['email']) || $address['email'] == '--none--'
		   || preg_match('/\bbox\b/i',$address['address_1'].' '.$address['address_2'])) {
			return true;
		}
		return false;
	}

	private function _testForManagerApproval($order, $method) {
		$productids = array();
		foreach($order['items'] as $i) if($i->virtuemart_product_id) $productids[] = $i->virtuemart_product_id;
		$approvalcategory = $method->approvalcategory;
		if(!empty($approvalcategory) && !empty($productids)) {
			$productModel = VmModel::getModel('product');
			foreach($productids as $product_id) {
				$categories = $productModel->getProductCategories($product_id);
				if(in_array($approvalcategory,$categories)) {
					return true;
				}
			}
		}
		return false;
	}

	private function _testSendEmail($status, $method, $vm_user_id, $order_id) {
		for($i=1; $i<=5; $i++) {
			if($method->{'enabled'.$i}=='1' && $method->{'status'.$i}==$status) {
				$config = JFactory::getConfig();
				$fromemail = $config->getValue('config.mailfrom');
				$fromname = $config->getValue('config.fromname');
				$emailto = explode(',',$method->{'email'.$i});
				$subject = $method->{'subject'.$i};
				$message = $method->{'message'.$i};
				if(strpos($message, '{PERSON_ID}') !== false) {
					$vm_user = JUser::getInstance($vm_user_id);
					$message = str_replace('{PERSON_ID}',$vm_user->username, $message);
				}
				$searchfields = array('{ORDER_ID}','{STATUS}','{ORDER_LINK}');
				$replacefields = array($order_id, $status, $config->getValue('live_site').'/administrator/index.php?option=com_virtuemart&view=orders&task=edit&virtuemart_order_id='.$order_id);
				$message = str_replace($searchfields, $replacefields, $message);
				$mailer = JFactory::getMailer();
				$mailer->sendMail($fromemail, $fromname, $emailto, $subject, $message);
			}
		}
	}
}