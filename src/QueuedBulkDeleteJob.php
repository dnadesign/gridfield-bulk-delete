<?php

namespace DNADesign\GridFieldBulkDelete;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use SilverStripe\Subsites\Model\Subsite;

class QueuedBulkDeleteJob extends AbstractQueuedJob implements QueuedJob
{

    /*
    * Requires to pass a DataList of objects to be deleted
    * @param Datalist
    * @param String
    * @param Member
    */
    public function __construct()
    {
        $args = array_filter(func_get_args());

        if (isset($args[0])) {
            $list = $args[0];

            $this->ids = $list->column('ID');
            $this->dataClass = $list->dataClass();
        }

        if (isset($args[1])) {
            $this->Title = $args[1];
        }

        if (isset($args[2])) {
            $this->User = $args[2];
        }
    }

    /**
     * Defines the title of the job
     *
     * @return string
     */
    public function getTitle()
    {
        $title = ($this->Title) ? $this->Title : sprintf('Delete %s %s', count($this->ids), $this->dataClass);
        
        if ($this->User) {
            return $title .= sprintf(' (Initiated by %s)', $this->User->getTitle());
        }

        return $title;
    }
    
    /**
     * Indicate to the system which queue we think we should be in based
     * on how many objects we're going to touch on while processing.
     *
     * We want to make sure we also set how many steps we think we might need to take to
     * process everything - note that this does not need to be 100% accurate, but it's nice
     * to give a reasonable approximation
     *
     * @return int
     */
    public function getJobType()
    {
        $this->totalSteps = count($this->ids);
        return QueuedJob::QUEUED;
    }

    /**
    *
    */
    public function setup()
    {
        $remainingChildren = $this->ids;
        $this->remainingChildren = $remainingChildren;
    }

    /**
     * Lets process one ID at the time
     */
    public function process()
    {
        $remainingChildren = $this->remainingChildren;

        // if there's no more, we're done!
        if (!count($remainingChildren)) {
            $this->isComplete = true;
            $this->handleCompletion();
            return;
        }

        $class = $this->dataClass;
        $ID = array_shift($remainingChildren);

        $record = null;

        if (class_exists('Subsite')) {
            $records = Subsite::get_from_all_subsites($class, ['ID' => $ID]);
            if ($records && $records->exists()) {
                $record = $records->First();
            }
        } else {
            $record = $class::get()->byID($ID);
        }

        if (!$record || !$record->exists()) {
            $message = sprintf('%s ID %s not found!', $class, $ID);
            $this->addMessage($message, 'WARNING');
            Injector::inst()->get(LoggerInterface::class)->warning($message);
        } else {
            $created = $record->dbObject('Created')->Nice();
            $record->delete();
            $this->addMessage(sprintf('Deleted %s ID %s (%s)', $class, $ID, $created), 'WARNING');
        }

        // Update counter
        $this->currentStep++;
        $this->remainingChildren = $remainingChildren;
    }

    /**
    * Send an email to the supplied user
    * upon completion
    */
    public function handleCompletion()
    {
        $member = $this->User;

        if ($member && $member->exists()) {
            $email = new Email();
            $email->setTo($member->Email);
            $email->setSubject('A deletion task requested by you has completed.');

            $message = sprintf('<p>Hi, %s</p>', $member->getTitle());
            $message .= sprintf('<p>Job %s has completed.</p>', $this->Title);
            $message .= sprintf('<p>%s records have been deleted.</p>', $this->totalSteps);
            $email->setBody($message);

            return $email->send();
        }
    }
}
