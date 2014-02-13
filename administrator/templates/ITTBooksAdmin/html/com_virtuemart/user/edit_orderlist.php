<?php
/**
*
* User details, Orderlist
*
* @package	VirtueMart
* @subpackage User
* @author Oscar van Eijk
* @link http://www.virtuemart.net
* @copyright Copyright (c) 2004 - 2010 VirtueMart Team. All rights reserved.
* @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
* VirtueMart is free software. This version may have been modified pursuant
* to the GNU General Public License, and as distributed it includes or
* is derivative of works licensed under the GNU General Public License or
* other free or open source software licenses.
* @version $Id: edit_orderlist.php 5928 2012-04-20 11:58:15Z alatak $
*/

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die('Restricted access');
$db = JFactory::getDBO();
?>

<div id="editcell">
	<table class="adminlist" cellspacing="0" cellpadding="0">
	<thead>
	<tr>
		<th>
			Order ID
		</th>
        <th>
        	View
        </th>
		<th>
			Courses/Books
		</th>
		<th>
			<?php echo JText::_('COM_VIRTUEMART_STATUS'); ?>
		</th>
		<th>
			<?php echo JText::_('COM_VIRTUEMART_ORDER_CDATE'); ?>
		</th>
		<th>
			Comments/Notes
		</th>
    </tr>
	</thead>
	<?php
		$k = 0;
		$n = 1;
		foreach ($this->orderlist as $i => $row) {
			
			
			
			// Get the order items
			$q = 'SELECT virtuemart_order_item_id, product_quantity, order_item_name,
				order_item_sku, i.virtuemart_product_id, product_item_price,
				product_final_price, product_basePriceWithTax, product_subtotal_with_tax, product_subtotal_discount, product_tax, product_attribute, order_status,
				intnotes, virtuemart_category_id
			   FROM (#__virtuemart_order_items i
			   LEFT JOIN #__virtuemart_products p
			   ON p.virtuemart_product_id = i.virtuemart_product_id)
									LEFT JOIN #__virtuemart_product_categories c
									ON p.virtuemart_product_id = c.virtuemart_product_id
			   WHERE `virtuemart_order_id`="'.$row->virtuemart_order_id.'" group by `virtuemart_order_item_id`';
				$db->setQuery($q);
				$order_items = $db->loadObjectList();
				
			// Get the order history
			$q = "SELECT *
				FROM #__virtuemart_order_histories
				WHERE virtuemart_order_id=".$row->virtuemart_order_id."
				ORDER BY virtuemart_order_history_id ASC";
			$db->setQuery($q);
			$order_histories = $db->loadObjectList();				
				
			$editlink = JROUTE::_('index.php?option=com_virtuemart&view=orders&task=edit&virtuemart_order_id=' . $row->virtuemart_order_id);

			//OrderPrint is deprecated
// 			$print_url = JURI::base().'?option=com_virtuemart&view=orders&task=orderPrint&virtuemart_order_id='.$row->virtuemart_order_id.'&format=raw';
// 			$print_link = "&nbsp;<a href=\"javascript:void window.open('$print_url', 'win2', 'status=no,toolbar=no,scrollbars=yes,titlebar=no,menubar=no,resizable=yes,width=640,height=480,directories=no,location=no');\">"
// 				. JHTML::_('image.site', 'printButton.png', ((JVM_VERSION===1) ? '/images/M_images/' : '/images/system/'), null, null, JText::_('COM_VIRTUEMART_PRINT'), array('align' => 'center', 'height'=> '16',  'width' => '16', 'border' => '0')).'</a>';

			?>
			<tr class="row<?php echo $k ; ?>">
				 
				<td align="left">
					<?php echo $row->virtuemart_order_id; ?>
				</td>
                <td>
                	<a href="<?php echo $editlink; ?>">View Details</a>
                </td>
				<td align="left">
					<?php 
					foreach ($order_items as $item) {
						echo $item->order_item_name."<br />";
					}
					?>
				</td>
				<td align="left">
					<?php echo ShopFunctions::getOrderStatusName($row->order_status); ?>
				</td>
                <td align="left">
					<?php echo vmJsApi::date($row->created_on,'LC2',true); ?>
				</td>
                <td align="left">
					<?php echo $row->customer_note; ?><br />
					<?php 
					$i=0;
					foreach ($order_histories as $item) {
						$i++;
						/* BEGIN Code added by Levi@SavvyPanda.com to link to FedEx shipping info */
						$commentcode = preg_replace('@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?)?)@', '<a href="$1" target="_blank">$1</a>', $item->comments);
						if(preg_match('/(?:FedEx .*|(?:Priority|Standard) Overnight) - (\d{12,22})/',$commentcode, $matches)) {
							$commentcode = str_replace($matches[1],'<a href="https://www.fedex.com/fedextrack/?tracknumbers='.$matches[1].'" target="_blank">'.$matches[1].'</a>',$commentcode);
						}
						if ($i>1)echo vmJsApi::date($item->created_on,'LC2',true).": ". $commentcode."<br />";
						/* END Levi@SavvyPanda.com edit */
					}
					?>                    
				</td>
                
			</tr>
	<?php
			$k = 1 - $k;
		}
	?>
	</table>
</div>
