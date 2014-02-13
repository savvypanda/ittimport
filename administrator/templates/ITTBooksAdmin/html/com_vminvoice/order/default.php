<?php




/**
 * PDF Invoicing Solution for VirtueMart & Joomla!
 * 
 * @package   VM Invoice
 * @version   2.0.22
 * @author    ARTIO http://www.artio.net
 * @copyright Copyright (C) 2011 ARTIO s.r.o. 
 * @license   GNU/GPLv3 http://www.artio.net/license/gnu-general-public-license
 */



defined('_JEXEC') or ('Restrict Access');


/************ MODS FOR ITT BOOKS ******************/
$default_order_status = 'P'; //Pending
$default_order_shipment = 2; //fedex
//$default_order_payment = 1; //no cost
//ITT Books view levels
$isadmin = false;
$isssc = false;
$current_user = JFactory::getUser();
if (in_array(6,$current_user->getAuthorisedViewLevels())): // 6 is the view level for ITT admins
	$isadmin = true;
endif;


/* @var $this VMInvoiceViewOrder */



JHTML::_('behavior.mootools');



//add files with version query (to refresh when file changes). 

//because of stupid bug in J!>1.6, when calling JHTML::script with some ? query parameter, script gets discareted. use directly addScript

//but not always(?) based on server settings

$files = array(

	'administrator/components/com_vminvoice/assets/js/ajaxcontent.js', 

	'administrator/components/com_vminvoice/assets/js/autility.js');

if (COM_VMINVOICE_ISVM2)

	$files[] = 'administrator/components/com_vminvoice/assets/js/rows.js';

	

$document = JFactory::getDocument();

foreach ($files as $file){

	$version = ($mtime = filemtime(JPATH_SITE.'/'.$file)) ? $mtime : time();

	$document->addScript(JURI::root().$file.'?v='.$version);}



JHTML::_('behavior.tooltip');

JHTML::_('behavior.calendar');



JToolBarHelper::title('Manual Student Order');

JToolBarHelper::save('save','Submit/Save Order');

//JToolBarHelper::apply('apply','Submit');

JToolBarHelper::cancel();



JFilterOutput::objectHTMLSafe($this->orderData);



$document = JFactory::getDocument();

/* @var $document JDocumentHTML */



$js  = '		var AddProduct = \'' . JText::_('COM_VMINVOICE_SELECT_PRODUCT', TRUE) . '\';' . "\n";

$js .= '		var AreYouSure = \'' . JTEXT::_('COM_VMINVOICE_ARE_YOU_SURE', TRUE) . '\';' . "\n";

$js .= '		function submitbutton (pressbutton) {' . "\n";

$js .= '			var form = document.adminForm;' . "\n";

$js .= '			if (pressbutton == \'cancel\') {' . "\n";

$js .= '		  		if (typeof Joomla != "undefined") Joomla.submitform(pressbutton); else submitform(pressbutton);' . "\n";

$js .= '				return;' . "\n";

$js .= '			}' . "\n";

$js .= '			if (form.status.value == \'\')' . "\n";

$js .= '				alert(\'' . JText::_('COM_VMINVOICE_SELECT_STATUS', TRUE) . '\');' . "\n";

$js .= '			else if (form.vendor.value == \'\')' . "\n";

$js .= '				alert(\'' . JText::_('COM_VMINVOICE_SELECT_VENDOR', TRUE) . '\');' . "\n";

$js .= '			else if (form.order_currency.value == \'\')' . "\n";

$js .= '				alert(\'' . JText::_('COM_VMINVOICE_SELECT_CURRENCY', TRUE) . '\');' . "\n";

$js .= '			else if (form.payment_method_id.value == \'\')' . "\n";

$js .= '				alert(\'' . JText::_('COM_VMINVOICE_SELECT_PAYMENT', TRUE) . '\');' . "\n";

$js .= '			else if ($("orderInfo").getElements("select[name^=order_status]").some(function(el){if (!el.options[el.selectedIndex].value){el.focus(); return true;} return false;}))' . "\n";

$js .= '				alert(\'' . JText::_('COM_VMINVOICE_SELECT_ITEM_STATUS', TRUE) . '\');' . "\n";

$js .= '			else {' . "\n";

$js .= '				if ($("user_id").value==""){' . "\n";

$js .= '					$("update_userinfo").value=1;' . "\n";

$js .= '					if (form.B_first_name.value.trim() == \'\'){' . "\n";

$js .= '						alert(\'' . JText::_('COM_VMINVOICE_FILL_IN_FIRST_NAME', TRUE) . '\');' . "\n";

$js .= '						form.B_first_name.focus();}' . "\n";

$js .= '					else if (form.B_last_name.value.trim() == \'\'){' . "\n";

$js .= '						alert(\'' . JText::_('COM_VMINVOICE_FILL_IN_LAST_NAME', TRUE) . '\');' . "\n";

$js .= '						form.B_last_name.focus();}' . "\n";

$js .= '					else if (form.B_email.value.trim() == \'\'){' . "\n";

$js .= '						alert(\'' . JText::_('COM_VMINVOICE_FILL_IN_E-MAIL', TRUE) . '\');' . "\n";

$js .= '						form.B_email.focus();}' . "\n";

$js .= '					else {' . "\n";

$js .= '						alert("' . JText::_('COM_VMINVOICE_NEW_CUSTOMER_DESC', TRUE) . '");' . "\n";

$js .= '						submitform( pressbutton );}' . "\n";

$js .= '				} else {' . "\n";

$js .= '					if (changed_userinfo==true && $("B_user_info_id").value>0){' . "\n";

$js .= '						if (confirm("' . JText::_('COM_VMINVOICE_UPDATE_ALSO_DEFAULT_VALUES', TRUE) . '"))' . "\n";

$js .= '							$("update_userinfo").value=1;' . "\n";

$js .= '					} ' . "\n";

$js .= '					if (typeof Joomla != "undefined") Joomla.submitform( pressbutton ); else submitform( pressbutton );}' . "\n";

$js .= '				}' . "\n";

$js .= '		}' . "\n";

$js .= '	if (typeof Joomla != "undefined")

				Joomla.submitbutton = submitbutton;' . "\n";





//initialize "user info change watcher"

$js .= 'var changed_userinfo=false;



	function addUserInfoCheck()

	{

			//for 1.5 without mootools upgrade

			userInputs = $("billing_address").getElements("input[name!=user]");

			userInputs.concat($("billing_address").getElements("textarea"));

			userInputs.concat($("billing_address").getElements("select"));



			userInputs.concat($("shipping_address").getElements("input"));

			userInputs.concat($("shipping_address").getElements("textarea"));

			userInputs.concat($("shipping_address").getElements("select"));

	

			$each(userInputs,function (input){

			

				if (typeof input.type != "undefined")

				{

					if (input.type=="text")

						input.addEvent("keyup", function(event){changed_userinfo=true;});

						

					if (input.type=="checkbox" || input.type=="radio")

						input.addEvent("click", function(event){changed_userinfo=true;});

				}

				

				input.addEvent("change", function(event){changed_userinfo=true;});

			});

	}



	window.addEvent(\'domready\', function() {

		addUserInfoCheck();

	});'; 



$document->addScriptDeclaration($js);
ob_start();
?>
<style>
table.vm_order td {
	padding: 5px;
}
table.admintable.vm_order td.key {
	font-weight: bold;
	width: 100px;
	text-align: right;
	padding-right: 10px !important;
	height: 20px;
}
.hide {
	display: none;
}
</style>
<?php
$style = ob_get_clean();
$document->addStyleDeclaration( $style );


?>

<form action="index.php" method="post" name="adminForm" id="adminForm">
  <input type="hidden" id="baseurl" name="baseurl" value="<?php echo addslashes(JURI::base()); ?>" />
  <div class="purchase-order left">
    <fieldset class="adminform">
      <legend><?php echo JText::_('COM_VMINVOICE_GENERAL') ?></legend>
      <table style="float: left; margin-right: 10px;" cellspacing="0" class="admintable vm_order">
        <tbody>
          <tr>
            <td class="key" nowrap="nowrap"><?php echo JText::_('ID'); ?></td>
            <td><?php echo $this->orderData->order_id ? $this->orderData->order_id : JText::_('COM_VMINVOICE_NEW'); ?>
              <input type="hidden" id="cid" name="cid" value="<?php echo $this->orderData->order_id; ?>" />
              <input type="hidden" id="order_id" name="order_id" value="<?php echo $this->orderData->order_id; ?>" /></td>
          </tr>
          <tr>
            <td class="key" nowrap="nowrap"><?php echo JText::_('COM_VMINVOICE_CREATE_DATE'); ?></td>
            <td><?php echo $this->orderData->cdate ? strftime(JText::_('COM_VMINVOICE_DATETIME_FORMAT'),$this->orderData->cdate) : '<em>automatically assigned at order creation</em>'; ?></td>
          </tr>
          <tr>
            <td class="key" nowrap="nowrap"><?php echo JText::_('COM_VMINVOICE_MODIFIED_DATE'); ?></td>
            <td><?php echo $this->orderData->mdate ? strftime(JText::_('COM_VMINVOICE_DATETIME_FORMAT'),$this->orderData->mdate) : ''; ?></td>
          </tr>
          
          <tr class="<?php echo $isadmin ? '' : 'hide';?>">
            <td class="key" nowrap="nowrap"><?php echo JText::_('COM_VMINVOICE_STATUS'); ?></td>
            <td><?php

							
							
    						array_unshift($this->orderStatus, JHTML::_('select.option', '', JText::_('COM_VMINVOICE_SELECT'), 'id', 'name')); 

    						echo JHTML::_('select.genericlist', $this->orderStatus, 'status', null, 'id', 'name',  $this->orderData->order_id ? $this->orderData->order_status : 'P'); 

    					?>
                <div class="hide">        
              <label class="">
                <input type="checkbox" name="notify" value="YF">
                <?php echo JText::_('COM_VMINVOICE_NOTIFY_CUSTOMER'); ?></label>
                </div>
              <?php if (false /* disabled*/ && COM_VMINVOICE_ISVM2) {?>
              <label class="hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_APPLY_TO_ALL_ITEMS')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_STATUS_APPLY_ITEMS_DESC')); ?>">
                <input type="checkbox" name="apply_status_to_all_items" value="1" checked />
                <?php echo JText::_('COM_VMINVOICE_APPLY_TO_ALL_ITEMS'); ?></label>
              <?php }  else {?>
              <div class="hide">
              <input type="button" class="hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_APPLY_TO_ALL_ITEMS')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_STATUS_APPLY_ITEMS_DESC')); ?>" value="<?php echo JText::_('COM_VMINVOICE_APPLY_TO_ALL_ITEMS'); ?> &raquo;" onclick="applyStatus();">
              </div>
              <?php } ?></td>
          </tr>
          
          <tr class="hide">
            <td class="key" nowrap="nowrap"><span class="compulsory"><?php echo JText::_('COM_VMINVOICE_VENDOR'); ?></span></td>
            <td><?php

    						array_unshift($this->vendors, JHTML::_('select.option', '', JText::_('COM_VMINVOICE_SELECT'), 'id', 'name')); 

    						echo JHTML::_('select.genericlist', $this->vendors, 'vendor', null, 'id', 'name', $this->orderData->vendor_id); 

    					?></td>
          </tr>
          <tr class="hide">
            <td class="key" nowrap="nowrap"></td>
            <td><?php

    						foreach ($this->currencies as $currency)

    							$currency->name = JText::sprintf('COM_VMINVOICE_CURRENCY_SHORT_INFO', $currency->name, COM_VMINVOICE_ISVM2 ? $currency->symbol : $currency->id);

    						array_unshift($this->currencies, JHTML::_('select.option', '', JText::_('COM_VMINVOICE_SELECT'), 'id', 'name'));

    						echo JHTML::_('select.genericlist', $this->currencies, 'order_currency', null, 'id', 'name', $this->orderData->order_currency); 

    					?></td>
          </tr>
        </tbody>
      </table>
    </fieldset>
    <fieldset class="adminform">
      <legend><?php echo JText::_('COM_VMINVOICE_ADDITIONAL') ?></legend>
      <table style="float: left;width:100%" cellspacing="0" class="admintable vm_order">
        <tbody>
          <tr>
            <td class="key" nowrap="nowrap" valign="top"><span>SSC Note</span></td>
            <td><textarea name="customer_note" id="customer_note" cols="40" rows="4" style="width:97%"><?php echo $this->orderData->customer_note; ?></textarea></td>
          </tr>
          <tr class="hide">
            <td class="key" nowrap="nowrap" valign="top"><span ><?php echo JText::_('COM_VMINVOICE_COUPON_CODE'); ?></span></td>
            <td><input type="text" name="coupon_code" id="coupon_code" size="15" onchange="getCouponInfo(this.value,'<?php echo $this->orderData->order_currency?>');" onkeyup="getCouponInfo(this.value,'<?php echo $this->orderData->order_currency?>');"  value="<?php echo $this->orderData->coupon_code; ?>" />
              <span id="coupon_info"></span> 
              <script type="text/javascript">getCouponInfo($('coupon_code').value, '<?php echo $this->orderData->order_currency?>');</script></td>
          </tr>
          <?php ?>
        </tbody>
      </table>
    </fieldset>
  </div>
  <div class="purchase-order right">
   <fieldset class="adminform">
   	<legend>Order Status History</legend>
    <table class="adminlist">
    	<tr>
    		<th>Date Added</th>
            <th>Status</th>
            <th>Comment</th>
         </tr>
    <?php //prep history
		$sql  = "SELECT h.created_on as history_date, s.order_status_name as status,h.comments ".
		"FROM qsafg_virtuemart_order_histories h ".
		"LEFT JOIN qsafg_virtuemart_orderstates s ".
		"ON h.order_status_code = s.order_status_code ".
		"WHERE virtuemart_order_id = ".$this->orderData->order_id." ".
		"ORDER BY history_date ASC";
		$db = JFactory::getDBO();
		$db->setQuery($sql);
		$histories = $db->loadObjectList();
		
		if (count($histories)) :
			foreach ($histories as $history) {
				$commentcode = preg_replace('@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?)?)@', '<a href="$1" target="_blank">$1</a>', $history->comments);
				if(preg_match('/(?:FedEx .*|(?:Priority|Standard) Overnight) - (\d{12,22})/',$commentcode, $matches)) {
					$commentcode = str_replace($matches[1],'<a href="https://www.fedex.com/fedextrack/?tracknumbers='.$matches[1].'" target="_blank">'.$matches[1].'</a>',$commentcode);
				} ?>
				<tr>
					<td><?php echo $history->history_date;?></td>
					<td><?php echo $history->status;?></td>
					<td><?php echo $commentcode;?></td>
				</tr>
			<?php }
		endif;
    ?>
	</table>
   </fieldset>
    <fieldset class="adminform hide">
      <legend><?php echo JText::_('COM_VMINVOICE_SHIPPING') ?></legend>
      <?php if (COM_VMINVOICE_ISVM2) {?>
      <?php 

      array_unshift($this->shippings, JHTML::_('select.option', '', JText::_('COM_VMINVOICE_SELECT'), 'shipping_rate_id', 'name'));

      echo JHTML::_('select.genericlist', $this->shippings, 'shipment_method_id', "", 'shipping_rate_id', 'name', $this->orderData->shipment_method_id); 						

      ?>
      <input type="button" class="hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_APPLY')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_APPLY_SHIPMENT_DESC')); ?>" value="<?php echo JText::_('COM_VMINVOICE_APPLY'); ?> &raquo;" onclick="showOrderData(null,false,true,false);">
      <?php } ?>
      <?php if (COM_VMINVOICE_ISVM1) {?>
      <table style="float: left; margin-right: 10px;" cellspacing="0" class="admintable">
        <tbody>
          <tr>
            <td class="key" nowrap="nowrap"><span class="hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_SHIPPING_PATTERN')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_SHIPPING_PATTERN_DESC')); ?>"><?php echo JText::_('COM_VMINVOICE_SHIPPING_PATTERN'); ?></span></td>
            <td><?php



    						$shippingSelected=$this->orderData->custom_shipping_class.'|'.$this->orderData->custom_shipping_carrier.'|'.$this->orderData->custom_shipping_ratename.'|'.$this->orderData->custom_shipping_costs.'|'.$this->orderData->custom_shipping_id.'|'.$this->orderData->order_shipping_taxrate;

    						

	    					foreach ($this->shippings as $shipping) {

	    						$shipping->shipping_rate_id = htmlspecialchars($shipping->shipping_rate_id).'|'.$shipping->tax_rate;

	    						$shipping->name = htmlspecialchars(JText::sprintf('COM_VMINVOICE_SHIPPING_SHORT_INFO', $shipping->name[0], $shipping->name[1], $shipping->name[2], $shipping->name[3], $shipping->name[4]));

	    					}

    						

    						array_unshift($this->shippings, JHTML::_('select.option', '', JText::_('COM_VMINVOICE_SELECT'), 'shipping_rate_id', 'name'));

    						echo JHTML::_('select.genericlist', $this->shippings, 'ship_method_id', "onchange='processShippingChange();'", 'shipping_rate_id', 'name', $shippingSelected); 						

    						?>
              <input type="button" class="hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_APPLY')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_APPLY_SHIPMENT_DESC')); ?>" value="<?php echo JText::_('COM_VMINVOICE_APPLY'); ?> &raquo;" onclick="applyShipping();"></td>
          </tr>
          <tr>
            <td class="key" nowrap="nowrap"><span class="compulsory hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_SHIPPING_CLASS')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_SHIPPING_CLASS_DESC')); ?>"><?php echo JText::_('COM_VMINVOICE_SHIPPING_CLASS'); ?></span></td>
            <td><input type="text" name="custom_shipping_class" id="custom_shipping_class" size="30"  value="<?php echo $this->orderData->custom_shipping_class; ?>" /></td>
          </tr>
          <tr>
            <td class="key" nowrap="nowrap"><span class="compulsory"><?php echo JText::_('COM_VMINVOICE_CARRIER'); ?></span></td>
            <td><input type="text" name="custom_shipping_carrier" id="custom_shipping_carrier" size="30"  value="<?php echo $this->orderData->custom_shipping_carrier; ?>" /></td>
          </tr>
          <tr>
            <td class="key" nowrap="nowrap"><span class="compulsory"><?php echo JText::_('COM_VMINVOICE_RATE_NAME'); ?></span></td>
            <td><input type="text" name="custom_shipping_ratename" id="custom_shipping_ratename" size="30" value="<?php echo $this->orderData->custom_shipping_ratename; ?>" /></td>
          </tr>
          <tr>
            <td class="key" nowrap="nowrap"><span class="compulsory hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_SHIPPING_COSTS')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_SHIPPING_COSTS_DESC')); ?>"><?php echo JText::_('COM_VMINVOICE_SHIPPING_COSTS'); ?></span></td>
            <td><input type="text" name="custom_shipping_costs" id="custom_shipping_costs" size="30"  value="<?php echo $this->orderData->custom_shipping_costs; ?>" /></td>
          </tr>
          <tr>
            <td class="key" nowrap="nowrap"><span class="compulsory hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_SHIPPING_TAX')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_SHIPPING_TAX_DESC')); ?>"><?php echo JText::_('COM_VMINVOICE_SHIPPING_TAX'); ?></span></td>
            <td><!--  <input type="text" name="custom_shipping_taxrate" id="custom_shipping_taxrate" size="30" value="<?php echo $this->orderData->custom_shipping_taxrate; ?>" />--> 
              
              <?php echo JHTML::_('select.genericlist', $this->taxRates, 'custom_shipping_taxrate', null, 'value', 'name', $this->orderData->custom_shipping_taxrate);  ?></td>
          </tr>
          <tr>
            <td class="key" nowrap="nowrap"><span class="hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_RATE_ID')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_RATE_ID_DESC')); ?>"><?php echo JText::_('COM_VMINVOICE_RATE_ID'); ?></span></td>
            <td><input type="text" name="custom_shipping_id" id="custom_shipping_id" size="30"  value="<?php echo $this->orderData->custom_shipping_id; ?>" />
              
              <!--  <input type="button" class="hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_CALCULATE_TAX')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_COMPUTE_TAX_DESC')); ?>" value="<?php echo JText::_('COM_VMINVOICE_COMPUTE_TAX'); ?> &raquo;" onclick="getShippingTax($('custom_shipping_class').value,$('custom_shipping_id').value);">  --></td>
          </tr>
        </tbody>
      </table>
      <?php } ?>
    </fieldset>
    <fieldset class="adminform hide">
      <legend><?php echo JText::_('COM_VMINVOICE_PAYMENT') ?></legend>
      <table style="float: left; margin-right: 10px;" cellspacing="0" class="admintable vm_order">
        <tbody>
          <tr>
            <td class="key">ITT No Cost
              <input type="hidden" id="payment_method_id" name="payment_method_id" value="1" /></td>
            <td><?php /*

    						if (COM_VMINVOICE_ISVM1)

	    						foreach ($this->payments as $payment) {

	    							if ($payment->payment_method_discount != 0.00)

	    								$payment->name = JText::sprintf($payment->payment_method_discount_is_percent == 1 ? 'COM_VMINVOICE_PAYMENT_SHORT_INFO_PERCENT' : 'COM_VMINVOICE_PAYMENT_SHORT_INFO', $payment->name, - round($payment->payment_method_discount, 2));

	    							else

	    								$payment->name = $payment->name;

	    						} 

    						array_unshift($this->payments, JHTML::_('select.option', '', JText::_('COM_VMINVOICE_SELECT'), 'id', 'name'));

    						echo JHTML::_('select.genericlist', $this->payments, 'payment_method_id', null, 'id', 'name', $this->orderData->payment_method_id); 

    					*/?>
              <?php if (COM_VMINVOICE_ISVM2){ ?>
              <input type="button" class="hasTip" title="<?php echo $this->escape(JText::_('COM_VMINVOICE_APPLY')); ?>::<?php echo $this->escape(JText::_('COM_VMINVOICE_APPLY_PAYMENT_DESC')); ?>" value="<?php echo JText::_('COM_VMINVOICE_APPLY'); ?> &raquo;" onclick="showOrderData(null,false,false,true);">
              <?php } ?></td>
          </tr>
        </tbody>
      </table>
    </fieldset>
  </div>
  <div class="clr"></div>
  <?php require_once 'userinfo.php'; ?>
  <?php require_once 'products.php'; ?>

  <input type="hidden" value="com_vminvoice" name="option" />
  <input type="hidden" name="task" value="" />
  <input type="hidden" name="controller" value="order" />
  
  <?php
  //itt books hack to pass return value back to save function
  
	//Get filter to pass it along to detail page so it can be returned to
	$filter_order_status = JRequest::getVar('filter_order_status',NULL);
	
	if (is_array($filter_order_status)):
		foreach ($filter_order_status as $status) :
			$return_filter .= "&filter_order_status[]=".$status;
		endforeach;
	else:
		$return_filter = "&filter_order_status=".$filter_order_status;	
	endif;
	
	//get order id if we are returning to an order view screen
	$edit_vm_order = JRequest::getInt('edit_vm_order',0);
	
	//get user
	$filter_created_by=  JRequest::getCmd('filter_created_by',NULL);
	$return_user= "&filter_created_by=".$filter_created_by;  
	
	if (!empty($filter_order_status) || !empty($filter_created_by)) :
		$return = "index.php?option=com_vminvoice&controller=invoices".$return_filter.$return_user;
	elseif ($edit_vm_order):
		$return = "index.php?option=com_virtuemart&view=orders&task=edit&virtuemart_order_id=".$this->orderData->order_id;
	else:
		$return = "index.php?option=com_users&filter_group_id=";
	endif;
  
  ?>
  <input type="hidden" name="return" value="<?php echo base64_encode($return);?>" /><!-- user search page -->
  
</form>
