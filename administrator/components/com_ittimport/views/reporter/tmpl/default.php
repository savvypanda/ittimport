<?php
defined('_JEXEC') or die('Restricted access');

//load tooltip behavior
JHtml::_('behavior.tooltip');
?>
<form action="<?php echo JRoute::_('index.php?option=com_ittimport&view=reporter'); ?>" method="post" name="adminForm" id="adminForm">
	<table class="adminlist">
    	<thead><tr>
			<th><?php echo JText::_('COM_ITTIMPORT_UPLOAD_ID'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_USERNAME'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_FILENAME'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_TIMESTAMP'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_NUM_ADDED'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_NUM_UPDATED'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_NUM_SKIPPED'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_NUM_CANCELLED'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_NUM_ERRORED'); ?></th>
			<th><?php echo JText::_('COM_ITTIMPORT_NUM_TOTAL'); ?></th>
		</tr></thead>
        <tfoot><tr>
        	<td colspan="9"><?php echo $this->pagination->getListFooter(); ?> &nbsp; <?php /* echo $this->pagination->getLimitBox(); */ ?></td>
        </tr></tfoot>
        <tbody><?php foreach($this->items as $i => $item): ?><tr class="row<?php echo $i % 2; ?>">
	        <?php $item->link = 'index.php?option=com_ittimport&view=details&id='.$item->upload_id; ?>
			<?php $item->userlink = 'index.php?option=com_users&task=user.edit&id='.$item->user_id; ?>
        	<td><a href="<?php echo $item->link; ?>"><?php echo $item->upload_id; ?></a></td>
            <td><a href="<?php echo $item->userlink; ?>"><?php echo $item->username; ?></a></td>
            <td><a href="<?php echo $item->link; ?>"><?php echo $item->filename; ?></a></td>
            <td><a href="<?php echo $item->link; ?>"><?php echo $item->timestamp; ?></a></td>
            <td><a href="<?php echo $item->link.'&status=added'; ?>"><?php echo $item->added; ?></a></td>
            <td><a href="<?php echo $item->link.'&status=updated'; ?>"><?php echo $item->updated; ?></a></td>
			<td><a href="<?php echo $item->link.'&status=skipped'; ?>"><?php echo $item->skipped; ?></a></td>
            <td><a href="<?php echo $item->link.'&status=cancelled'; ?>"><?php echo $item->cancelled; ?></a></td>
            <td><a href="<?php echo $item->link.'&status=errored'; ?>"><?php echo $item->errored; ?></a></td>
            <td><a href="<?php echo $item->link; ?>"><?php echo $item->total; ?></a></td>
        </tr><?php endforeach; ?></tbody>
    </table>
    <input type="hidden" name="task" value="" />
</form>
