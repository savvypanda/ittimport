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

	defined('_JEXEC') or die('Restrict Access');
	//Mod for ITT Books
	//get display creds
	$user = JFactory::getUser();
	$isadmin = false;
	if(in_array(6, $user->getAuthorisedViewLevels())): // 6 is the view level for ITT admins
		$isadmin = true;
	endif;
	//Get filter to pass it along to detail page so it can be returned to
	$filter_order_status = JRequest::getVar('filter_order_status', NULL);
	$return_filter = '';
	if(is_array($filter_order_status)):
		foreach($filter_order_status as $status) :
			$return_filter .= "&filter_order_status[]=".$status;
		endforeach; else:
		$return_filter = "&filter_order_status=".$filter_order_status;
	endif;

	//get user
	$filter_created_by = JRequest::getCmd('filter_created_by', NULL);
	$return_user = "&filter_created_by=".$filter_created_by;

	JHTML::_('behavior.tooltip');
	JHtml::_('behavior.calendar');
	global $mainframe;
	$delivery_note = $this->delivery_note;

	//build string with neccesay statuses for invice cration (from invoice config)
	$orderStatuses = (array) InvoiceHelper::getParams()->get('order_status');
	foreach($orderStatuses as &$orderStatus) {
		$orderStatus = isset($this->statuses[$orderStatus]) ? $this->statuses[$orderStatus]->name : $orderStatus;
	}

	if(count($orderStatuses) == 1) {
		$sendStatuses = $orderStatuses[0];
	} elseif(count($orderStatuses) > 1) {
		$sendStatuses = ' '.JText::_('COM_VMINVOICE_OR').' '.array_pop($orderStatuses);
		$sendStatuses = implode(', ', $orderStatuses).$sendStatuses;
	}

?>
<script language="javascript" defer="defer">
	function show_change(div_id) {
		var div = document.getElementById(div_id);
		if (div.style.display == 'none')
			div.style.display = 'block';
		else
			div.style.display = 'none';
	}

	function show_change_date(order_id) {
		var div = document.getElementById('change_invoice_date_' + order_id);
		if (div.style.display == 'none') {
			div.style.display = 'block';
			div.getElement('img.calendar').fireEvent('click');
		} else {
			div.style.display = 'none';
			calendar.hide();
		}
	}

	function reset_search() {
		$('filter_orders').getElements('input[type=text]').set('value', '');
		$('filter_orders').getElements('input[type=checkbox]').set('checked', false);
		$('filter_orders').getElements('option').set('selected', false);
	}

	//before batch sending
	function clicked_batch() {
		if ($('batch_select_selected_list').checked && document.adminForm.boxchecked.value == 0) {
			alert('<?php echo JText::_('COM_VMINVOICE_CHECK_AT_LEAST_ONE_ORDER')?>');
			document.adminForm.task.value = '';
			return false;
		}
		document.adminForm.target = '';
		document.adminForm.task.value = 'batch';

		//download pdfs - open form target in new window
		if ($('batch_download').checked) { //generator_order_by.value
			newwindow = window.open('index.php?option=com_vminvoice&controller=invoices', 'win2', 'status=yes,toolbar=yes,scrollbars=yes,titlebar=no,menubar=no,resizable=yes,width=640,height=480,directories=no,location=no');
			document.adminForm.target = 'win2';
			if (window.focus) newwindow.focus()
			return true;
		}

		return true;
	}

	//before batch sending
	function clicked_filter() {
		document.adminForm.task.value = '';
		document.adminForm.target = '';
		return true;
	}
</script>
<?php
	$total = $this->get('Total');
	JHTML::_('behavior.calendar');
	$params = InvoiceHelper::getParams();
	$options = array();
	$options[] = JHTML::_('select.option', 'invoice', JText::_('COM_VMINVOICE_INVOICES'));
	$options[] = JHTML::_('select.option', 'dn', JText::_('COM_VMINVOICE_DELIVERY_NOTES'));
	$starting_order = $params->get('starting_order', 0);
?>
<form action="index.php" method="post" name="adminForm">
<table class="adminheading" width="100%">
	<tr>
		<td valign="top">
			<fieldset id="filter_orders">
				<legend><?php echo JText::_('COM_VMINVOICE_FILTER_ORDERS') ?></legend>
				<table style="width:100%" cellpadding="0" cellspacing="0" class="admintable">
					<tr>
						<td style="width:120px">
							<label for="start_date"> <?php echo JText::_('COM_VMINVOICE_DATE_FROM'); ?>: </label></td>
						<td><?php echo JHTML::calendar(JRequest::getVar('filter_start_date'), 'filter_start_date', 'start_date', '%d-%m-%Y'); ?>
							<label for="filter_end_date"><?php echo JText::_('COM_VMINVOICE_DATE_TO'); ?>: </label>
							<?php echo JHTML::calendar(JRequest::getVar('filter_end_date'), 'filter_end_date', 'end_date', '%d-%m-%Y'); ?>
						</td>
					</tr>
					<tr>
						<td><label for="order_status"> <?php echo JText::_('COM_VMINVOICE_STATUS') ?></label></td>
						<td><?php echo JHTML::_('select.genericlist', $this->statuses, 'filter_order_status[]', 'multiple="multiple" size="5"', 'id', 'name', JRequest::getVar('filter_order_status', array())); ?></td>
					</tr>
					<tr>
						<td><label for="filter_id"> <?php echo JText::_('COM_VMINVOICE_ORDER_ID') ?></label></td>
						<td>
							<input type="text" name="filter_id" id="filter_id" value="<?php echo JRequest::getVar('filter_id') ?>" />
						</td>
					</tr>
					<tr>
						<td><label for="filter_id"> Course</label></td>
						<td>
							<input type="text" name="filter_courseno" id="filter_courseno" value="<?php echo JRequest::getVar('filter_courseno') ?>" />
						</td>
					</tr>
					<tr>
						<td><label for="filter_name"> <?php echo JText::_('COM_VMINVOICE_NAME'); ?>: </label></td>
						<td>
							<input type="text" name="filter_name" id="filter_name" value="<?php echo JRequest::getVar('filter_name') ?>" />
						</td>
					</tr>
					<tr>
						<td><label for="filter_email"> <?php echo JText::_('COM_VMINVOICE_MAIL'); ?>: </label></td>
						<td>
							<input type="button" value="<?php echo JText::_('COM_VMINVOICE_CLEAR'); ?>" style="float:right!important;margin-left:1px" onclick="reset_search();this.form.submit();">
							<input type="submit" value="<?php echo JText::_('COM_VMINVOICE_FILTER_ORDERS'); ?>" style="float:right!important" onclick="clicked_filter();">
							<input type="text" name="filter_email" id="filter_email" value="<?php echo JRequest::getVar('filter_email') ?>" />
						</td>
					</tr>
				</table>
			</fieldset>
		</td>
		<td valign="top">
			<fieldset <?php echo $isadmin ? '' : 'style="display:none"'; ?>>
				<legend><?php echo JText::_('COM_VMINVOICE_PROCESS_ORDERS') ?></legend>
				<table width="100%" cellpadding="0" cellspacing="0" class="admintable">
					<tr>
						<td><label>
								<input type="radio" id="batch_select_selected_list" name="batch_select" value="selected_list"<?php if(JRequest::getVar('batch_select', 'selected_list') == 'selected_list') {
									echo ' checked';
								} ?>>
								<?php echo JText::_('COM_VMINVOICE_ORDERS_CHECKED_IN_LIST') ?></label></td>
					</tr>
					<tr>
						<td><label>
								<input type="radio" name="batch_select" value="all_filtered"<?php if(JRequest::getVar('batch_select') == 'all_filtered') {
									echo ' checked';
								} ?>>
								<?php echo JText::_('COM_VMINVOICE_ORDERS_MATCHING_FILTER') ?></label></td>
					</tr>
				</table>
			</fieldset>
			<fieldset <?php echo $isadmin ? '' : 'style="display:none"'; ?>>
				<legend><?php echo JText::_('COM_VMINVOICE_BATCH_ACTION') ?></legend>
				<table width="100%" cellpadding="0" cellspacing="0" class="admintable">
					<tr style="display:none">
						<td><label> <input type="radio" id="batch_download" name="batch" value="download">
								<?php echo JText::_('COM_VMINVOICE_DOWNLOAD') ?></label>
							<?php if($params->get('delivery_note') == 1) {
								echo JHTML::_('select.genericlist', $options, 'batch_download_option', NULL, 'value', 'text', JRequest::getVar('batch_download_option'));
							} ?>
						</td>
					</tr>
					<tr style="display:none">
						<td><label> <input type="radio" name="batch" value="mail">
								<?php echo JText::_('COM_VMINVOICE_SEND_EMAIL') ?></label>
							<?php
								if($params->get('delivery_note') && !$params->get('send_both', 1)) {
									echo JHTML::_('select.genericlist', $options, 'batch_mail_option', NULL, 'value', 'text', JRequest::getVar('batch_mail_option'));
								} elseif($params->get('delivery_note') && $params->get('send_both', 1)) {
									echo '<label>&nbsp; '.JString::strtolower(JText::_('COM_VMINVOICE_INVOICES')).' & '.JString::strtolower(JText::_('COM_VMINVOICE_DELIVERY_NOTES')).'</label>';
								} else {
									echo '<label>&nbsp;  '.JString::strtolower(JText::_('COM_VMINVOICE_INVOICES')).'</label>';
								}
							?>
							<label>
								<input type="checkbox" name="batch_mail_force" value="1"<?php if(JRequest::getVar('batch_mail_force', '0') == 1) {
									echo ' checked';
								} ?>>
								<?php echo JText::_('COM_VMINVOICE_ALSO_ALREADY_SENT') ?></label></td>
					</tr>
					<?php if($params->get('invoice_number') == 'own') { ?>
						<tr style="display:none">
							<td><label>
									<input type="radio" name="batch" value="create_invoice"<?php if(JRequest::getVar('batch') == 'create_invoice') {
										echo ' checked';
									} ?>>
									<?php echo JText::_('COM_VMINVOICE_CREATE_INVOICE_NUMBERS') ?></label></td>
						</tr>
					<?php } ?>
					<?php if($params->get('cache_pdf')) { ?>
						<tr style="display:none">
							<td><label>
									<input type="radio" name="batch" value="generate"<?php if(JRequest::getVar('batch') == 'generate') {
										echo ' checked';
									} ?>>
									<?php echo JText::_('COM_VMINVOICE_PRE-GENERATE_PDFS') ?></label> <label>
									<input type="checkbox" name="batch_generate_force" value="1"<?php if(JRequest::getVar('batch_generate_force', '0') == 1) {
										echo ' checked';
									} ?>>
									<?php echo JText::_('COM_VMINVOICE_ALSO_ALREADY_GENERATED') ?></label></td>
						</tr>
					<?php } ?>
					<tr>
						<td><label> <input type="radio" name="batch" value="change_status" checked>
								<?php echo JText::_('COM_VMINVOICE_CHANGE_STATUS') ?></label>
							<?php echo JHTML::_('select.genericlist', $this->statuses, 'batch_status', NULL, 'id', 'name', JRequest::getVar('batch_status')); ?>
							<label style="display:none !important;">
								<input type="checkbox" name="batch_notify_customer" value="Y"<?php if(JRequest::getVar('batch_notify_customer') == 1) {
									echo ' checked';
								} ?>>
								<?php echo JText::_('COM_VMINVOICE_NOTIFY_CUSTOMER') ?></label></td>
					</tr>
					<tr>
						<td>
							<input type="submit" value="<?php echo JText::_('COM_VMINVOICE_PROCESS') ?>" style="" onclick="return clicked_batch();">
						</td>
					</tr>
				</table>
			</fieldset>
		</td>
	</tr>
</table>
<div id="editcell">
	<table class="adminlist">
		<thead>
			<tr>
				<?php
					//build header array to pass it to plugin
					$header = array();
					$header['id'] = '<th width="5">'.JText::_('ID').'</th>';
					$header['check'] = '<th width="20"><input type="checkbox" name="toggle" value="" onclick="checkAll('.count($this->invoices).');" /></th>';
					$header['order_id'] = '<th width="60">'.JText::_('COM_VMINVOICE_ORDER_ID').'</th>';
					$header['edit'] = '<th width="1%">'.JText::_('COM_VMINVOICE_EDIT_ORDER').'</th>';
					$header['name'] = '<th>Student Name</th>';
					$header['email'] = '<th>'.JText::_('COM_VMINVOICE_MAIL').'</th>';
					$header['status'] = '<th width="180">'.JText::_('COM_VMINVOICE_STATUS').'</th>';
					$header['created_date'] = '<th width="80">'.JText::_('COM_VMINVOICE_CREATED_DATE').'</th>';
					$header['modified_date'] = '<th width="80">'.JText::_('COM_VMINVOICE_LAST_MODIFIED').'</th>';

					$this->dispatcher->trigger('onInvoicesListHeader', array(&$header, $this));
					echo implode(PHP_EOL, $header);
				?>
			</tr>
		</thead>
		<?php
			$k = 0;
			for($i = 0, $n = count($this->invoices); $i < $n; $i++) {
				$row = $this->invoices[$i];
				$checked = JHTML::_('grid.id', $i, $row->order_id);
				$editOrder_url = "index.php?option=com_vminvoice&controller=invoices&task=editOrder&cid=".$row->order_id.$return_filter.$return_user;
				$pdf_url = "index.php?option=com_vminvoice&controller=invoices&task=pdf&cid=".$row->order_id;
				$pdf_dn_url = "index.php?option=com_vminvoice&controller=invoices&task=pdf_dn&cid=".$row->order_id;
				$pdf_link = "&nbsp;<a href=\"javascript:void window.open('$pdf_url', 'win2', 'status=no,toolbar=no,scrollbars=yes,titlebar=no,menubar=no,resizable=yes,width=640,height=480,directories=no,location=no');\">";
				$pdf_dn_link = "&nbsp;<a href=\"javascript:void window.open('$pdf_dn_url', 'win2', 'status=no,toolbar=no,scrollbars=yes,titlebar=no,menubar=no,resizable=yes,width=640,height=480,directories=no,location=no');\">";
				$mail_url = "index.php?option=com_vminvoice&controller=invoices&task=send_mail&cid=".$row->order_id;
				$mail_dn_url = "index.php?option=com_vminvoice&controller=invoices&task=send_delivery_note&cid=".$row->order_id;

				$item = array();
				$item['id'] = '<td>'.($i + 1).'</td>';
				$item['check'] = '<td>'.$checked.'</td>';
				$item['order_id'] = '<td>'.$row->order_id.'</td>';
				$item['edit'] = '<td align="center"><a class="editOrder" href="'.$editOrder_url.'" title="'.JText::_("Edit order").'"><span class="unseen">'.JText::_("Edit order").'</span></a></td>';
				$item['name'] = '<td>'.stripslashes($row->last_name).' '.stripslashes($row->first_name).'</td>';
				$item['email'] = '<td>'.$row->email.'</td>';
				$item['status'] = '<td>'.JHTML::_('select.genericlist', $this->statuses, 'status['.$row->order_id.']', NULL, 'id', 'name', $row->order_status).'
	    <input type="submit" name="update['.$row->order_id.']" value="'.JText::_('COM_VMINVOICE_UPDATE').'">
		<!--<span style="white-space: nowrap;"><input type="checkbox" name="notify['.$row->order_id.']" value="YF">'.JText::_('COM_VMINVOICE_NOTIFY_CUSTOMER').'</span>--></td>';
				$item['created_date'] = '<td>'.JHTML::_('date', $row->cdate, JText::_('DATE_FORMAT_LC3')).'</td>';
				$item['modified_date'] = '<td>'.JHTML::_('date', $row->mdate, JText::_('DATE_FORMAT_LC3')).'</td>';

				$results = $this->dispatcher->trigger('onInvoicesListItem', array(&$item, $i, $row, $this));
				/* foreach($results as $result) {
					if($result === false) //false = not display row
					{
						continue;
					}
				} */
				//display row
				?>
				<tr class="<?php echo "row$k"; ?>">
					<?php echo implode(PHP_EOL, $item); ?>
				</tr>
				<?php
				$k = 1 - $k;
			}
		?>
		<tr>
			<td colspan="20"><?php echo $this->pagination->getListFooter(); ?></td>
		</tr>
	</table>
</div>
<input type="hidden" name="total" id="total" value="<?php echo $n; ?>" />
<input type="hidden" name="option" value="com_vminvoice" />
<input type="hidden" name="task" value="" autocomplete="off" /> <input type="hidden" name="boxchecked" value="0" />
<input type="hidden" name="controller" value="invoices" />
</form>
