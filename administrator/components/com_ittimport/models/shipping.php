<?php defined('_JEXEC') or die('Restricted access');

//import dependencies
jimport('joomla.application.component.model');

if(!class_exists( 'VmConfig' )) require JPATH_ADMINISTRATOR.'/components/com_virtuemart/helpers/config.php';
if(!class_exists('VirtueMartModelOrders')) require(JPATH_VM_ADMINISTRATOR.'/models/orders.php');
if(!class_exists('VirtueMartCart')) require_once(JPATH_VM_SITE.DS.'helpers'.DS.'cart.php');


/*
 * IttImport Shipping model
 *
 */
class IttImportModelShipping extends JModel {
	//cache variable. For each start date it stores whether or not it is within our shipping date range
	private $startdates = array();
	private $ship_cutoff;
	private $virtual_categories;
	private $user_id;

	/*
	 * Constructor
	 */
	public function __construct($config = array()) {
		parent::__construct($config);

		$params = JComponentHelper::getParams('com_ittimport');
		$ship_x_days_before_course_starts = $params->get('ship_x_days_before_course_starts');
		$this->ship_cutoff = strtotime("+$ship_x_days_before_course_starts days");
		$this->virtual_categories = explode(',',str_replace(' ','',$params->get('virtual_product_category','')));

		$user = JFactory::getUser();
		$this->user_id = $user->id;
	}

	/*
	 * ProcessOrders function. This is the main point of this model
	 *
	 * Returns: False if there is an error that prevents the process from continuing.
	 * An array containing the number of shipped and errored orders, as well as any additional details that may have been returned when changing the orders statuses
	 */
	public function processOrders() {
		//first let's assert that the operation is being performed by a logged in user (even from a CLI script)
		$user = JFactory::getUser();
		if(!$user->id) {
			return false;
		}

		$results = array(
			'shipped' => 0,
			'empty' => 0,
			'errored' => 0,
			'cancelled' => 0,
			'backordered' => 0,
			'details' => array(),
		);

		//record that we are mass shipping orders
		global $is_ittbooks_auto_shipping;
		$is_ittbooks_auto_shipping = true;

		//now let's get the orders and process them.
		$orders_to_process = $this->_get_pending_order_list();
		foreach($orders_to_process as $order) {
			if($this->_order_past_ship_date($order)) {
				$result = $this->_remove_products_to_skip($order->virtuemart_order_id);
				if($result === false) {
					$results['errored']++;
					continue;
				} elseif($result !== true) {
					$results['details'][] = $result;
					continue;
				}
				if($this->_order_has_items($order)) {
					$backorders = array();
					$result = $this->_split_backorders($order, $backorders);
					if($result === true) {
						if(!empty($backorders)) {
							$results['backordered'] += count($backorders);
						}
						$result = $this->_ship_order($order->virtuemart_order_id);
						if($result === true) {
							$results['shipped']++;
						} elseif($result === false) {
							$results['errored']++;
							continue;
						} else {
							$results['details'][] = $result;
							continue;
						}
					} elseif($result === false) {
						$results['backordered']++;
					} else {
						$results['details'][] = $result;
						continue;
					}
				} else {
					$result = $this->_mark_order_as_shipped($order->virtuemart_order_id, 'Order is empty and past its ship date.');
					if($result === true) {
						$results['empty']++;
					} elseif($result === false) {
						$results['errored']++;
						continue;
					} else {
						$results['details'][] = $result;
						continue;
					}
				}
			}
		}

		$backorders = $this->_get_backorders_list();
		if(!empty($backorders)) {
			$inventory = $this->_getInventoryLevels();

			foreach($backorders as $order_id => $products) {
				if($products === false) {
					$result = $this->_cancel_order($order_id, 'Automatically cancelled backorder because it is empty');
					if($result === true) {
						$results['cancelled']++;
					} elseif($result === false) {
						$results['errored']++;
					} else {
						$results['details'][] = $result;
					}
				} else {
					$ready_to_ship = true;
					foreach($products as $product_id => $quantity) {
						if(empty($inventory) || !isset($inventory[$product_id]) || $quantity > $inventory[$product_id]) {
							$ready_to_ship = false;
						}
					}
					if($ready_to_ship) {
						$result = $this->_ship_order($order_id);
						if($result === true) {
							$results['shipped']++;
							foreach($products as $product_id => $quantity) {
								$inventory[$product_id] -= $quantity;
							}
						} elseif($result === false) {
							$results['errored']++;
						} else {
							$results['details'][] = $result;
						}
					}
				}
			}
		}

		//we are done with the mass shipment
		$is_ittbooks_auto_shipping = false;

		return $results;
	}

	private function _get_pending_order_list() {
		$this->_db->setQuery('SELECT * FROM #__virtuemart_orders WHERE order_status="P"');
		return $this->_db->loadObjectList();
	}
	private function _get_backorders_list() {
		$this->_db->setQuery('SELECT o.virtuemart_order_id, i.virtuemart_product_id, i.product_quantity FROM #__virtuemart_orders o LEFT JOIN #__virtuemart_order_items i ON o.virtuemart_order_id=i.virtuemart_order_id WHERE o.order_status="B"');
		$result = $this->_db->loadObjectList();
		$backorders = array();
		if(!empty($result)) {
			foreach($result as $order_item) {
				if(empty($order_item->virtuemart_product_id)) {
					$backorders[$order_item->virtuemart_order_id] = false;
				} else {
					$backorders[$order_item->virtuemart_order_id][$order_item->virtuemart_product_id] = $order_item->product_quantity;
				}
			}
		}
		return $backorders;
	}
	private function _getInventoryLevels() {
		$this->_db->setQuery('SELECT p.virtuemart_product_id, p.product_in_stock,
									COUNT(CASE WHEN o.order_status="C" AND i.order_status<>"X" AND i.order_status<>"S" THEN 1 ELSE NULL END) as product_ready_to_ship
								FROM #__virtuemart_products p
									LEFT JOIN #__virtuemart_order_items i ON i.virtuemart_product_id = p.virtuemart_product_id
									LEFT JOIN #__virtuemart_orders o ON i.virtuemart_order_id = o.virtuemart_order_id
								WHERE p.published=1
								GROUP BY p.virtuemart_product_id, p.product_in_stock');
		$result = $this->_db->loadAssocList();

		$inventory = array();
		foreach($result as $product) {
			$inventory[$product['virtuemart_product_id']] = $product['product_in_stock'] - $product['product_ready_to_ship'];
		}
		return $inventory;
	}

	private function _order_past_ship_date($order) {
		$ship_date = $this->_getShipDate($order->customer_note);
		if(empty($ship_date)) {
			return true;
		}
		if(!array_key_exists($ship_date, $this->startdates)) {
			$this->startdates[$ship_date] = (strtotime($ship_date) <= $this->ship_cutoff);
		}
		return $this->startdates[$ship_date];
	}

	private function _remove_products_to_skip($order_id) {
		if(!is_numeric($order_id)) return false;
		if(empty($this->virtual_categories)) return true;

		$this->_db->setQuery('SELECT DISTINCT i.virtuemart_order_item_id, i.order_item_name
			FROM #__virtuemart_order_items i
			JOIN #__virtuemart_product_categories pce ON pce.virtuemart_product_id=i.virtuemart_product_id AND pce.virtuemart_category_id IN('.implode(',',$this->virtual_categories).')
			LEFT JOIN #__virtuemart_products p ON p.virtuemart_product_id=i.virtuemart_product_id
			WHERE i.virtuemart_order_id='.$order_id);
		$products_to_remove = $this->_db->loadAssocList('virtuemart_order_item_id');
		if(!empty($products_to_remove)) {
			$orderModel = VmModel::getModel('orders');
			$this->_db->setQuery('UPDATE #__virtuemart_order_items SET order_status="S" WHERE virtuemart_order_item_id IN('.implode(',',array_keys($products_to_remove)).')');
			$this->_db->query();
			if($msg = $this->_db->getErrorMsg()) {
				return $msg;
			} else {
				$item_comments = array();
				foreach($products_to_remove as $product) {
					$item_comments[] = $product['order_item_name'];
				}
				$history_comment = 'The following products are not physical products and will not be shipped: '.implode('; ',$item_comments);
				$result = $orderModel->updateStatusForOneOrder($order_id,array('order_status'=>'P','customer_notified'=>0,'comments'=>$history_comment),false);
				if($result === false) {
					return 'Error while updating history for order '.$order_id;
				} elseif($result !== true) {
					return $result;
				}
			}
		}
		return true;
	}

	private function _split_backorders($order, &$backorder_list) {
		if(!is_numeric($order->virtuemart_order_id)) return false;

		$acceptable_statuses=array('"P"','"B"','"A"','"D"','"C"','"M"');
		$this->_db->setQuery('SELECT
				i.virtuemart_order_item_id,
				i.virtuemart_product_id,
				i.order_item_name,
				i.product_quantity,
				i.product_attribute,
				i.order_status,
				p.product_in_stock,
				(SELECT count(*) FROM qsafg_virtuemart_order_items i2 LEFT JOIN qsafg_virtuemart_orders o ON o.virtuemart_order_id = i2.virtuemart_order_id WHERE (o.order_status = "C") AND i2.order_status <> "X" AND i2.order_status <> "S" AND i2.virtuemart_product_id = i.virtuemart_product_id) as product_ordered
			FROM #__virtuemart_order_items i
   			LEFT JOIN #__virtuemart_products p ON p.virtuemart_product_id = i.virtuemart_product_id
   			WHERE virtuemart_order_id='.$order->virtuemart_order_id.'
   			AND i.order_status IN('.implode(',',$acceptable_statuses).')');
		$items_in_stock = $this->_db->loadObjectList('virtuemart_order_item_id');
		$out_of_stock = array();
		foreach($items_in_stock as $id => $item) {
			if($item->product_quantity > $item->product_in_stock - $item->product_ordered) {
				$out_of_stock[$id] = $item;
				unset($items_in_stock[$id]);
			}
		}
		if(empty($items_in_stock)) {
			$orderModel = VmModel::getModel('orders');
			$history_comment = 'Not enough inventory to ship any items on this order.';
			$result = $orderModel->updateStatusForOneOrder($order->virtuemart_order_id,array('order_status'=>'B','customer_notified'=>0,'comments'=>$history_comment));
			if($result === true) {
				return false;
			} elseif($result === false) {
				return 'Error while processing shipment for order '.$order->virtuemart_order_id.'. Failed to change status to "Backordered"';
			} else {
				return $result;
			}
		}
		if(!empty($out_of_stock)) {
			$orderModel = VmModel::getModel('orders');
			$returnvals = array();
			foreach($out_of_stock as $id => $item) {
				//Step 1. Create a new order with the item.
				$result = $this->_createBackorderForItem($order, $item);
				if($result === false) {
					$returnvals[] = 'Error while handling out of stock items on order <a href="'.$this->getOrderUrl($order->virtuemart_order_id).'">'.$order->virtuemart_order_id.'</a>. This order needs to be fixed immediately.';
					continue;
				} elseif(!is_numeric($result)) {
					$returnvals[] = $result;
					continue;
				} else {
					$new_order_id = $result;
				}

				//Step 2. Remove the item from the old order
				if($orderModel->removeOrderLineItem($id)) {
					$orderModel->handleStockAfterStatusChangedPerProduct('X', $order->order_status, $item, $item->product_quantity);
					$history_comment = $item->order_item_name.' is out of stock and has been moved into a new backorder.';
					$result = $orderModel->updateStatusForOneOrder($order->virtuemart_order_id,array('order_status'=>'P','customer_notified'=>0,'comments'=>$history_comment),false);
					if($result === false) {
						$returnvals[] = 'Error while updating history for order '.$order->virtuemart_order_id.'.';
					} elseif($result !== true) {
						$returnvals[] = $result;
					}
				} else {
					$returnvals[] = 'Failed to remove product '.$id.' from order <a href="'.$this->getOrderUrl($order->virtuemart_order_id).'">'.$order->virtuemart_order_id.'</a>. This product is not on two different order. This must be corrected immediately.';
				}

				//Step 3. Add the new order ID to $backorder_list
				$backorder_list[] = $new_order_id;
			}
			if(!empty($returnvals)) return implode('<br />',$returnvals);
		}
		return true;
	}

	private function _createBackorderForItem($order, $order_item) {
		$ship_date = $this->_getShipDate($order->customer_note);
		$course = '';
		$order_courses = $this->getCourseIDs($order->customer_note);
		$matched_courses = 0;
		foreach($order_courses as $name) {
			if(strpos($order_item->order_item_name,$name) !== FALSE) {
				$course = $name;
				$matched_courses++;
			}
		}
		if($matched_courses > 1) $course = '';

		$session = JFactory::getSession();
		$session->set('user',JFactory::getUser($order->virtuemart_user_id));
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		$cart->BT=0;
		$cart->BTaddress = 0;

		$err = '';
		$_REQUEST['quantity'][0] = 1;
		if (!$cart->add(array($order_item->virtuemart_product_id),$err)) {
			$session->set('user',JFactory::getUser($this->user_id)); //reset the user in the session to the correct user
			return $err;
		}

		$query = 'SELECT * FROM #__virtuemart_order_userinfos WHERE virtuemart_order_id='.$order->virtuemart_order_id.' AND address_type="BT"';
		$this->_db->setQuery($query,0,1);
		$address = $this->_db->loadAssoc();
		if(!empty($address)) {
			$cart->saveAddressInCart($address, 'BT', false);
		}
		$cart->prepareAddressDataInCart('BT',true);

		$cart->tosAccepted = 1;
		$cart->cartData = $cart->prepareCartData(true);
		$cart_prices = $cart->getCartPrices(true);
		$cart->CheckAutomaticSelectedPayment($cart_prices,true);
		$cart->setCartIntoSession();

		$cart->customer_comment = '';
		if(!empty($ship_date)) $this->saveShipDate($ship_date, $cart->customer_comment);
		if(!empty($course)) $this->saveCourseId($course, $cart->customer_comment);
		$cart->customer_comment .= "\nThis order was created as a backorder from order <a href=\"".$this->getOrderUrl($order->virtuemart_order_id).'">'.$this->getOrderUrl($order->virtuemart_order_id).'</a>';

		$orderModel = VmModel::getModel('orders');
		if (($orderID = $orderModel->createOrderFromCart($cart)) === false) {
			$session->set('user',JFactory::getUser($this->user_id)); //reset the user in the session to the correct user
			VirtueMartCart::removeCartFromSession();
			return 'Failed to create the order: '.$orderModel->getError();
		}

		$history_comment = 'Order was created as a backorder.';
		$orderModel->updateStatusForOneOrder($orderID,array('order_status'=>'B','customer_notified'=>0,'comments'=>$history_comment),false);

		VirtueMartCart::removeCartFromSession();
		$session->set('user',JFactory::getUser($this->user_id));
		//Set the created_by and modified_by fields on the orders to the current user for reporting purposes
		$this->_db->setQuery('UPDATE #__virtuemart_orders SET created_by='.$this->_db->quote($this->user_id).', modified_by='.$this->_db->quote($this->user_id).', created_on='.$this->_db->quote($order->created_on).' WHERE virtuemart_order_id='.$this->_db->quote($orderID));
		$this->_db->query();

		return $orderID;
	}

	private function _order_has_items($order) {
		$acceptable_statuses=array('"P"','"B"','"A"','"D"','"C"','"M"');
		$this->_db->setQuery('SELECT count(*) FROM #__virtuemart_order_items WHERE virtuemart_order_id='.$order->virtuemart_order_id.' AND order_status IN('.implode(',',$acceptable_statuses).')');
		return ($this->_db->loadResult() > 0);
	}

	private function _getShipDate($string) {
		$has_csd = preg_match('/\[\[SHIP_DATE\: (\d{2}\/\d{2}\/\d{4})\]\]/',$string,$match);
		return ($has_csd)?$match[1]:'';
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
	private function getCourseIDs($string) {
		$has_course = preg_match_all('/\[\[COURSE_NUMBER\: ([A-Za-z0-9 ]*)\](?:\[COURSE_START_DATE: (\d{2}\/\d{2}\/\d{4})?\])?/',$string,$matches);
		if($has_course) {
			return $matches[1];
		} else {
			return array();
		}
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

	private function getOrderUrl($order_id) {
		return rtrim(JFactory::getConfig()->getValue('live_site'),'/').'/administrator/index.php?option=com_virtuemart&view=orders&task=edit&virtuemart_order_id='.$order_id;
	}

	private function _ship_order($order_id) {
		if(!is_numeric($order_id)) return false;
		$orderModel = VmModel::getModel('orders');
		$history_comment = 'Automatically changed order status to "Ready to Ship".';
		return $orderModel->updateStatusForOneOrder($order_id,array('order_status'=>'C','customer_notified'=>0,'comments'=>$history_comment));
	}
	private function _mark_order_as_shipped($order_id, $comment) {
		if(!is_numeric($order_id)) return false;
		$orderModel = VmModel::getModel('orders');
		return $orderModel->updateStatusForOneOrder($order_id,array('order_status'=>'S','customer_notified'=>0,'comments'=>$comment),false);
	}
	private function _cancel_order($order_id, $comment) {
		if(!is_numeric($order_id)) return false;
		$orderModel = VmModel::getModel('orders');
		return $orderModel->updateStatusForOneOrder($order_id,array('order_status'=>'X','customer_notified'=>0,'comments'=>$comment));
	}
}
