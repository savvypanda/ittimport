<?php
defined('_JEXEC') or die('Restricted access');

//load tooltip behavior
JHtml::_('behavior.tooltip');
?>
<form action="<?php echo JRoute::_('index.php?option=com_ittimport&view=details&id='.$this->upload_id); ?>" method="post" name="adminForm" id="adminForm">
	<div style="float:right;padding-left:30px;">
		<label for="status"><?php echo JText::_('COM_ITTIMPORT_FILTER_STATUS'); ?>: </label>
		<select name="status" onchange="document.location=('index.php?option=com_ittimport&view=details&id=<?php echo $this->upload_id; ?>&status='+this.value);">
			<?php $status = JRequest::getVar('status',''); ?>
			<option value="">-- <?php echo JText::_('COM_ITTIMPORT_SELECT_A_STATUS'); ?> --</option>
			<option value="added" <?php if($status=='added') echo 'selected="selected"'; ?>><?php echo JText::_('COM_ITTIMPORT_ADDED'); ?></option>
			<option value="updated" <?php if($status=='updated') echo 'selected="selected"'; ?>><?php echo JText::_('COM_ITTIMPORT_UPDATED'); ?></option>
			<option value="skipped" <?php if($status=='skipped') echo 'selected="selected"'; ?>><?php echo JText::_('COM_ITTIMPORT_SKIPPED'); ?></option>
			<option value="cancelled" <?php if($status=='cancelled') echo 'selected="selected"'; ?>><?php echo JText::_('COM_ITTIMPORT_CANCELLED'); ?></option>
			<option value="errored" <?php if($status=='errored') echo 'selected="selected"'; ?>><?php echo JText::_('COM_ITTIMPORT_ERRORED'); ?></option>
		</select>
	</div>
	<h3 style="float:left;"><?php echo JText::_('COM_ITTIMPORT_IMPORT');?> <?php echo $this->filename;?>: <?php echo $this->timestamp; ?></h3>
	<div style="clear:both;"></div>
	<table class="adminlist">
    	<thead><tr>
			<th><?php echo JText::_('COM_ITTIMPORT_DETAILS_ID'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_PERSON_ID'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_COURSE_NUMBER'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_EVENT_STATUS'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_EVENT_DETAILS'); ?></th>
		</tr></thead>
        <tfoot><tr>
        	<td colspan="5"><?php echo $this->pagination->getListFooter(); ?></td>
        </tr></tfoot>
        <tbody><?php foreach($this->items as $i => $item): ?><tr class="row<?php echo $i % 2; ?>">
        	<td><?php echo $item->details_id; ?></td>
            <td><?php echo $item->person_id; ?></td>
			<td><?php echo $item->course_no; ?></td>
			<td><?php echo JText::_('COM_ITTIMPORT_'.strtoupper($item->status)); ?></td>
            <td><?php echo $item->details; ?></td>
        </tr><?php endforeach; ?></tbody>
    </table>
    <input type="hidden" name="task" value="" />
</form>