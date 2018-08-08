<?php
/**
* Adds a dropdown and a button
* Allowig users to delete records in the gridfield in bulk
* with the option to delete only records older than 1,2,3 or 6 months.
* 
* Also, if available, a queued job will be used if too many record need to be deleted.
* Both this task and queuedjob loop trhough the record and invoke "delete"
* so any dependant record may be deleted as well.
*
*/
class GridFieldBulkDeleteForm implements GridField_HTMLProvider, GridField_ActionProvider {

	protected $targetFragment;

	protected $message;
	protected $status = 'good';

	// Set to less than 0 to never use queuedjob
	protected $use_queued_threshold = 50;

	public function __construct($targetFragment = 'before', $threshold = null) {
		$this->targetFragment = $targetFragment;

		if ($threshold && $threshold > 0) {
			$this->use_queued_threshold = $threshold;
		}
	}

	public function getHTMLFragments($gridField)
    {
        $records = $gridField->getManipulatedList();
        if (!$records->exists()) {
        	// If a message exists, but no record
        	// it means we have deleted them all
        	// so display the message anyway
        	if ($this->message) {
        		$fragment = sprintf('<p class="message %s">%s</p>', $this->status, $this->message);
        		return array(
	            	$this->targetFragment => $fragment
	            );
        	}
        	// Otherwise hide the form
        	return array();
        }

        $singleton = singleton($gridField->getModelClass());
        if (!$singleton->canDelete()) {
            return array();
        }

        // Always give the option to delete everything
        $default_option = 'now';
        $default_option_label = sprintf('Delete all %s records', $records->Count());

        $options = [$default_option => $default_option_label];

        // Fetch other interval from config
        $up_to = Config::inst()->get(get_class($this), 'delete_up_to');
        if ($up_to) {
        	if (!is_array($up_to)) {
	        	user_error('Time intervals need to be supplied as an array.');
	        	exit();
	        }

	        foreach($up_to as $interval => $label) {
	        	$date = new DateTime();
	        	$date->modify('-'.$interval);

	        	if ($date !== false) {
		        	$toDelete = $records->filter('Created:LessThan', $date->format('Y-m-d 00:00:00'))->Count();
		        	$options[$interval] = sprintf($label, $toDelete);
		        }
	        }
        }
        
        if (count($options) > 1) {
        	$optionsField = DropdownField::create('BulkDeleteUntil', '', $options);
        } else {
        	$optionsField = HiddenField::create('BulkDeleteUntil', $default_option_label, $default_option);
        }
        
		$optionsField->setForm($gridField->getForm());

		$buttonTitle = (count($options) > 1) ? 'Go' : $default_option_label;

		$button = new GridField_FormAction(
			$gridField,
			'bulkdelete',
			$buttonTitle,
			'bulkdelete',
			null
		);

		$button->setForm($gridField->getForm());
		$button->addExtraClass('bulkdelete_button');

		// Set message
		if ($this->message) {
			if (count($options) > 1) {
				$optionsField->setError($this->message, $this->status);
			}
		}

		$template = '<div><table><tr><td style="vertical-align:top;">%s</td><td style="vertical-align:top;"><div style="margin-left: 7px;">%s</div></td></tr></table></div>';

		$fragment = sprintf($template, $optionsField->FieldHolder(), $button->Field());

		return array(
			$this->targetFragment => $fragment
		);
    }

	/**
	 * export is an action button
	 */
	public function getActions($gridField) 
	{
		return array('bulkdelete');
	}

	public function handleAction(GridField $gridField, $actionName, $arguments, $data) 
	{
		if($actionName == 'bulkdelete') {
			return $this->handleBulkDelete($gridField);
		}
	}

	/**
	 * Handle the export, for both the action button and the URL
 	 */
	public function handleBulkDelete($gridField, $request = null) 
	{
		$controller = $gridField->getForm()->Controller();
		$request = $controller->getRequest();
		$records = $gridField->getManipulatedList();
		$parent = $controller->currentPage();

		$until = $request->postVar('BulkDeleteUntil');
		if (!empty($until) && $until !== 'now') {
			$date = new DateTime();
			$date->modify('-'.$until);

			if ($date !== false) {
				$records = $records->filter('Created:LessThan', $date->format('Y-m-d 00:00:00'));
			}
		}

		// If more than 50 record are to be deleted
		// Use a QueuedJob
		if (class_exists('QueuedJobService')
			&& $this->use_queued_threshold >= 0
			&& $records->Count() > $this->use_queued_threshold
		){
			$from = ($parent && $parent->hasMethod('getTitle')) ? $parent->getTitle() : $parent->Name;
			$title = sprintf('Delete %s record (%s) from %s', $records->Count(), FormField::name_to_label($records->dataClass()), $from);
			
			$job = new QueuedBulkDeleteJob($records, $title, Member::currentUser());
			singleton('QueuedJobService')->queueJob($job);

			$this->message = sprintf('As more than %s records have to be deleted, a job as been queued in the background. You will get an email when the task is complete.', $this->use_queued_threshold);
			$this->status = 'warning';

			Controller::curr()->getResponse()->setStatusCode(
                200,
                $this->message
            );

			return;
		} 

		// Otherwise start deleting straight away (may time out)
		$ids = array();
		
		foreach ($records as $record)	{						
			array_push($ids, $record->ID);
			$record->delete();
		}

		$this->message = sprintf('%s records have been successfully deleted.', count($ids));
		$this->status = 'good';

		Controller::curr()->getResponse()->setStatusCode(
            200,
            $this->message
        );

		return;
	}

}
