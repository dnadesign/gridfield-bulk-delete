# GridField Bulk Delete

## Introduction

Add a `GridFieldBulkDeleteForm` component to a gridfield config to add an option to delete the records within that list.
By default, it allows to delete every records in the list.
If there is more than 50 records (can be configured) and if QueuedJobs are available, a ququedjob will be created to process the deletion.
The component can be configured to allow deleting records older than a certain period of time (eg: older tha 30 days). 

## Requirements
* [silverstripe/framework](https://github.com/silverstripe/framework) ^4.0

## Install
Add the following to your composer.json file

```

    "require"          : {
		"dnadesign/gridfield-bulk-delete": "0.1"
	}

```

## Configuration

### Add to gridfield config

	$config->addComponent(new GridFieldBulkDeleteForm('buttons-after-right'));

Optionally, pass the number of records above which a queuedjob should be used (Defaults is 50).

	$config->addComponent(new GridFieldBulkDeleteForm('buttons-after-right', 100));

### Use queued jobs

In your `mysite/_config/config.yml` file:

	GridFieldBulkDeleteForm:
	  use_queued_threshold: 100

NOTE: Set the value to 0 or -1 to never use queued jobs.

### Enable time interval

In your `mysite/_config/config.yml` file:

	GridFieldBulkDeleteForm:
	  delete_up_to:
	    '30 days' : 'Delete records created over a month ago (%s)'

NOTE: Value must be valid for use with `DateTime` modify().
Also, supply a label for `sprintf()` where the number of record affected will be supplied as param.