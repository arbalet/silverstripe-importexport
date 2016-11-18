<?php

/**
 * A button you can add to a GridField to export that GridField as a CSV. Should work with any sized GridField,
 * as the export is done using a cronjob in the background.
 */
class GridFieldExporter implements GridField_HTMLProvider, GridField_ActionProvider, GridField_URLHandler {

    /**
     * @var array Map of a property name on the exported objects, with values being the column title in the CSV file.
     * Note that titles are only used when {@link $csvHasHeader} is set to TRUE.
     */
    protected $exportColumns;

    /**
     * @var string
     */
    protected $csvSeparator = ",";

    /**
     * @var boolean
     */
    protected $csvHasHeader = true;

    /**
     * Fragment to write the button to
     */
    protected $targetFragment;

    /**
     * @param string $targetFragment The HTML fragment to write the button into
     * @param array $exportColumns The columns to include in the export
     */
    public function __construct($targetFragment = "after", $exportColumns = null) {
        $this->targetFragment = $targetFragment;
        $this->exportColumns = $exportColumns;
    }

    /**
     * Place the export button in a <p> tag below the field
     */
    public function getHTMLFragments($gridField) {
        $button = GridField_FormAction::create(
            $gridField,
            'export',
            _t('TableListField.CSVEXPORT', 'Export to CSV'),
            'export',
            null
        )
        	->setAttribute('data-icon', 'download-csv')
        	->addExtraClass('action_batch_export')
        	->setForm($gridField->getForm());

        return array(
            $this->targetFragment => '<p class="grid-csv-button">' . $button->Field() . '</p>',
        );
    }

    /**
     * This class is an action button
     */
    public function getActions($gridField) {
        return array('export', 'findgridfield');
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data) {
        if($actionName == 'export')
            return $this->startExport($gridField);
        else if ($actionName == 'findgridfield')
            return new GridFieldExporter_Response($gridField);
    }

    function startExport($gridField) {
        $job = ExportQueue::create();

        // Set the parameters that allow re-discovering this gridfield during background execution
        $job->setGridField($gridField);
        $job->setSession(Controller::curr()->getSession()->get_all());
		$job->TotalSteps = $job->getGridField()->getManipulatedList()->count();

        // Set the parameters that control CSV exporting
        $job->Separator = $this->csvSeparator;
        $job->IncludeHeader = $this->csvHasHeader;
        if($this->exportColumns) 
        	$job->Columns = json_encode($this->exportColumns);

        // Queue the job
        $job->write();
		$job->AddAction('Register');

        // Redirect to the status update page
        return Controller::curr()->redirect($gridField->Link('/export/' . $job->Signature));
    }

    /**
     * This class is also a URL handler
     */
    public function getURLHandlers($gridField) {
        return array(
            'export/$ID'          => 'checkExport',
            'export_download/$ID' => 'downloadExport'
        );
    }

    public static function getExportPath($id) {
        return ASSETS_PATH."/.exports/$id/$id.csv";
    }

    /**
     * Handle the export, for both the action button and the URL
     */
    public function checkExport($gridField, $request = null) {
        $id = $request->param('ID');
		
        $job = ExportQueue::get()->filter('Signature', $id)->first();

        if((int)$job->MemberID !== (int)Member::currentUserID())
            return Security::permissionFailure();

        $controller = $gridField->getForm()->getController();

        $breadcrumbs = $controller->Breadcrumbs(false);
        $breadcrumbs->push(new ArrayData(array(
            'Title' => _t('TableListField.CSVEXPORT', 'Export to CSV'),
            'Link'  => false
        )));

        $parents = $controller->Breadcrumbs(false)->items;
        $backlink = array_pop($parents)->Link;

        $data = new ArrayData(array(
            'ID'          => $id,
            'Link'        => Controller::join_links($gridField->Link(), 'export', $job->Signature),
            'Backlink'    => $backlink,
            'Breadcrumbs' => $breadcrumbs,
            'GridName'    => $gridField->getname()
        ));

        if ($job->Status == 'Finished') {
            if (file_exists($this->getExportPath($id)))
                $data->DownloadLink = $gridField->Link('/export_download/' . $job->Signature);
            else
                $data->ErrorMessage = _t(
                    'GridFieldExporter.ERROR_REMOVED',
                    'This export has already been downloaded. For security reasons each export can only be downloaded once.'
                );
        } else if ($job->Status == 'Rejected') {
            $data->ErrorMessage = _t(
                'GridFieldExporter.CANCELLED',
                'This export job was cancelled'
            );
        } else {
            $data->Count = $job->StepsProcessed;
            $data->Total = $job->TotalSteps;
			
			$data->RemainingTimeSeconds = 0;
			if($startAction = $job->TaskActions()->filter('Detail', 'Start processing')->first()) {
				$iTimeElapsed = strtotime($job->LastEdited) - strtotime($startAction->Created);
				$iLeftToProcess = $job->TotalSteps - $job->StepsProcessed;
				
				$data->ElaspedTimeSeconds = $iTimeElapsed;
				$data->RemainingTimeSeconds = round(($iLeftToProcess / $job->StepsProcessed) * $iTimeElapsed);
			}
        }

        Requirements::javascript('importexport/javascript/GridFieldExporter.js');
        Requirements::css('importexport/css/GridFieldExporter.css');

        $return = $data->renderWith('GridFieldExporter');
		
		return $request->isAjax() ? $return : $controller->customise(array('Content' => $return));
    }

    public function downloadExport($gridField, $request = null) {
        $id = $request->param('ID');
        $job = ExportQueue::get()->filter('Signature', $id)->first();

        if((int)$job->MemberID !== (int)Member::currentUserID())
            return Security::permissionFailure();

        $now = Date("d-m-Y-H-i");
        $servedName = "export-$now.csv";

        $path = $this->getExportPath($id);
        $content = file_get_contents($path);

        unlink($path);
        rmdir(dirname($path));

        $response = SS_HTTPRequest::send_file($content, $servedName, 'text/csv');
        $response->addHeader('Set-Cookie', 'downloaded_'.$id.'=true; Path=/');
		
		$job->AddAction('Downloaded');

        return $response;
    }

    /**
     * @return array
     */
    public function getExportColumns() {
        return $this->exportColumns;
    }

    /**
     * @param array
     */
    public function setExportColumns($cols) {
        $this->exportColumns = $cols;
        return $this;
    }

    /**
     * @return string
     */
    public function getCsvSeparator() {
        return $this->csvSeparator;
    }

    /**
     * @param string
     */
    public function setCsvSeparator($separator) {
        $this->csvSeparator = $separator;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getCsvHasHeader() {
        return $this->csvHasHeader;
    }

    /**
     * @param boolean
     */
    public function setCsvHasHeader($bool) {
        $this->csvHasHeader = $bool;
        return $this;
    }
}

/**
 * A special type of SS_HTTPResponse that GridFieldExporter returns in response to the "findgridfield"
 * action, which includes a reference to the gridfield
 */
class GridFieldExporter_Response extends SS_HTTPResponse {
    private $gridField;

    public function __construct(GridField $gridField) {
        $this->gridField = $gridField;
        parent::__construct('', 500);
    }

    public function getGridField() {
        return $this->gridField;
    }
}
