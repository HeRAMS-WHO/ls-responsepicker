<?php
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
}

/**
 * Add conditional classloader.
 *
 */
if (($_GET['test'] ?? '' === 'ResponsePicker') && file_exists(__DIR__ . '/test/ResponsePicker.php')) {
    require_once __DIR__ . '/test/ResponsePicker.php';
} else {


    class ResponsePicker extends \ls\pluginmanager\PluginBase
    {

        static protected $description = 'This plugins allows a user to pick which response to work on if multiple candidate responses exist.';
        static protected $name = 'ResponsePicker';

        protected $storage = 'DbStorage';

        private function createEnabled(int $surveyId): bool
        {
            return (bool) $this->get('create', 'Survey', $surveyId);
        }

        private function deleteEnabled(int $surveyId): bool
        {
            return (bool) $this->get('delete', 'Survey', $surveyId);
        }

        private function viewEnabled(int $surveyId): bool
        {
            return (bool) $this->get('view', 'Survey', $surveyId);
        }

        private function updateEnabled(int $surveyId): bool
        {
            return (bool) $this->get('update', 'Survey', $surveyId);
        }

        private function repeatEnabled(int $surveyId): bool
        {
            return (bool) $this->get('repeat', 'Survey', $surveyId);
        }

        public function init()
        {
            die('Test version!');
            $this->subscribe('beforeLoadResponse');
            // Provides survey specific settings.
            $this->subscribe('beforeSurveySettings');

            // Saves survey specific settings.
            $this->subscribe('newSurveySettings');
            
            $this->subscribe('newDirectRequest');
        }

        /**
         * This function renders a response in a table.
         * It uses LS internals which is not according to best practices.
         * @param $response
         * @param $surveyId
         * @throws CException
         * @throws Exception
         */
        protected function viewResponse($response, $surveyId)
        {
            $aFields = array_keys(createFieldMap($surveyId,'full',true,false,'en'));

            App()->loadHelper('admin.exportresults');
            App()->loadHelper('export');
            \Yii::import('application.helpers.viewHelper');
            App()->controller->__set('action', App()->controller->getAction());
            $oExport = new \ExportSurveyResultsService();
            $oFormattingOptions = new FormattingOptions();

            $oFormattingOptions->responseMinRecord = $response['id'];
            $oFormattingOptions->responseMaxRecord = $response['id'];

            $oFormattingOptions->selectedColumns=$aFields;
            $oFormattingOptions->responseCompletionState= 'all';
            $oFormattingOptions->headingFormat = 'full';
            $oFormattingOptions->answerFormat = 'long';
            $oFormattingOptions->output = 'file';



            $sTempFile = $oExport->exportSurvey($surveyId, 'en', 'json' ,$oFormattingOptions, '');
            $data = array_values(json_decode(file_get_contents($sTempFile), true)['responses'][0])[0];

            $out = '<html><title></title><body><table>';
            foreach($data as $key => $value) {
                $row = "";
                if (!empty($value)) {
                    $row .= "<tr>";
                    if (preg_match('/^\{.*\}$/', $key)) {
                        $row .= "<td style='width: 40%;'><span title='$key'>Computed</span></td>";
                    } else {
                        $row .= "<td style='width: 40%;'>$key</td>";
                    }

                    if (is_numeric($value)) {
                        if (abs(intval($value) - floatval($value)) < 0.001) {
                            $value = intval($value);
                        } else {
                            $value = floatval($value);
                        }
                    }

                    $row .= "<td>$value</td></tr>";
                }
                $out .= $row;
            }
            $out .= "</table>";
            $out .= CHtml::link("Back to list", $this->api->createUrl('survey/index', ['sid' => $surveyId, 'token' => $response['token'], 'lang' => 'en', 'newtest' => 'Y']));
            $out .= '</body><style>body { width: 1070px; margin-left: auto; margin-right: auto; } tr:nth-child(even) {background: #f1f1f1}</style></html>';
            Yii::app()->getClientScript()->render($out);
            echo $out;
        }
        public function newDirectRequest() {
            if ($this->event->get('target') == __CLASS__) {
                /** @var CHttpRequest $request */
                $request = $this->event->get('request');
                $surveyId = $request->getParam('surveyId');
                $responseId = $request->getParam('responseId');
                $token = $request->getParam('token');
                switch($this->event->get("function")) {
                    case 'delete':
                        if (!$this->deleteEnabled($surveyId)) {
                            throw new \CHttpException(403, "Deleting not enabled for this survey");
                        }
                        if (!$request->isDeleteRequest) {
                            throw new \CHttpException(405, "This endpoint only supports DELETE");
                        }
                        /** @var \Response $response */
                        $response = Response::model($surveyId)->findByAttributes([
                            'id' => $responseId,
                            'token' => $token
                        ]);
                        if (isset($response)) {
                            $response->delete();
                        }
                        http_response_code(202);
                        die();
                        break;
                    case 'read':
                        if (!$this->viewEnabled($surveyId)) {
                            throw new \CHttpException(403, "Viewing not enabled for this survey");
                        }
                        $response = $this->api->getResponse($surveyId, $responseId);
                        if (isset($response)) {
                            $this->viewResponse($response, $surveyId);
                        } else {
                            throw new \CHttpException(404, "Response not found.");
                        }
                        break;
                    case 'copy':
                        if (!$this->repeatEnabled($surveyId)) {
                            throw new \CHttpException(403, "Repeating not enabled for this survey");
                        }

                        if (!$request->isPostRequest) {
                            throw new \CHttpException(405, "This endpoint only supports POST");
                        }
                        $this->createCopy($surveyId, $responseId);
                        break;
                    default:
                        echo "Unknown action.";
                }
            }
        }


        /**
         * Create a copy of the given response and direct the user to that response.
         * @param $surveyId
         * @param $responseId
         */
        protected function createCopy($surveyId, $responseId)
        {
            if (null === $response = \Response::model($surveyId)->findByPk($responseId)) {
                throw new \CHttpException(404, "Response not found.");
            }

            $response->id = null;
            $response->isNewRecord = true;
            $response->submitdate = null;
            $response->lastpage = 1;
            $skipColumns = explode("\r\n", $this->get('skipColumns', 'Survey', $surveyId, ""));
            $questions = Question::model()->findAllByAttributes([
                'sid' => $surveyId,
                'parent_qid' => 0,
                'title' => $skipColumns
            ]);

            $attributePrefixes = array_map(function(Question $question) {
                return "{$question->sid}X{$question->gid}X{$question->qid}";
            }, $questions);

            /** @var Question $question */
            foreach($attributePrefixes as $prefix) {
                foreach ($response->attributeNames() as $attributeName) {
                    // Test prefix match
                    if (strpos($attributeName, $prefix) === 0) {
                        $response->{$attributeName} = null;
                    }
                }
            }
            $response->save(false);
            $location = $this->api->createUrl('survey/index', [
                'ResponsePicker' => $response->id,
                'sid' => $surveyId,
                'token' => $response->token
            ]);
            header('Location: ' . $location);
            http_response_code(201);
            die();
        }
        public function beforeLoadResponse()
        {
            $surveyId = $this->event->get('surveyId');
            if ($this->get('enabled', 'Survey', $surveyId) == false) {
                return;
            }
            // Responses to choose from.
            $responses = $this->event->get('responses');
            /**
             * @var LSHttpRequest
             */
            $request = $this->api->getRequest();

            // Only handle get requests.
            if ($request->requestType == 'GET')
            {
                $choice = $request->getParam('ResponsePicker');
                if (isset($choice)) {
                    if ($choice == 'new') {
                        if (!$this->createEnabled($surveyId)) {
                            throw new \CHttpException(403, "Creation not enabled for this survey");
                        }
                        $this->event->set('response', false);
                    } else {
                        foreach ($responses as $response) {
                            if ($response->id == $choice) {
                                $this->event->set('response', $response);
                                break;
                            }
                        }
                    }
                    /*
                     *  Save the choice in the session; if the survey has a
                     * welcome page, it is displayed and the response is "chosen"
                     * in the next request (which is a post)
                     */
                    $_SESSION['ResponsePicker'] = isset($response) ? $response->id : $choice;
                } else {
                    $this->renderOptions($request, $responses);
                }
            }
            else
            {
                if (isset($_SESSION['ResponsePicker']))
                {

                    $choice = $_SESSION['ResponsePicker'];
                    unset($_SESSION['ResponsePicker']);
                    if ($choice == 'new')
                    {
                        $this->event->set('response', false);
                    }
                    else
                    {
                        foreach ($responses as $response)
                        {
                            if ($response->id == $choice)
                            {
                                
                                $this->event->set('response', $response);
                                break;
                            }
                        }
                    }
                }
            }
        }

        public function beforeSurveySettings()
        {
            $event = $this->event;
            $settings = [
                'name' => get_class($this),
                'settings' => [
                    'enabled' => [
                        'type' => 'boolean',
                        'label' => 'Use ResponsePicker for this survey: ',
                        'current' => $this->get('enabled', 'Survey', $event->get('survey'), 0)
                    ],
                    'update' => [
                        'type' => 'boolean',
                        'label' => 'Enable update button: ',
                        'current' => $this->get('update', 'Survey', $event->get('survey'), 0)
                    ],
                    'repeat' => [
                        'type' => 'boolean',
                        'label' => 'Enable repeat button: ',
                        'current' => $this->get('repeat', 'Survey', $event->get('survey'), 0)
                    ],
                    'view' => [
                        'type' => 'boolean',
                        'label' => 'Enable view butfton: ',
                        'current' => $this->get('view', 'Survey', $event->get('survey'), 0)
                    ],
                    'delete' => [
                        'type' => 'boolean',
                        'label' => 'Enable delete button: ',
                        'current' => $this->get('delete', 'Survey', $event->get('survey'), 0)
                    ],
                    'create' => [
                        'type' => 'boolean',
                        'label' => 'Enable create button: ',
                        'current' => $this->get('create', 'Survey', $event->get('survey'), 0)
                    ],
                    'columns' => [
                        'type' => 'text',
                        'label' => 'Show these columns (One question code per line):',
                        'help' => 'Enable filtering by adding : and the type of filter (text,select,none), add a title override by appending another :',
                        'current' => $this->get('columns', 'Survey', $event->get('survey'), "")
                    ],
                    'skipColumns' => [
                        'type' => 'text',
                        'label' => 'Skip these columns during the repeat action',
                        'current' => $this->get('skipColumns', 'Survey', $event->get('survey'), "")
                    ],
                    'newheader' => [
                        'type' => 'string',
                        'label' => 'Header for new response button:',
                        'current' => $this->get('newheader', 'Survey', $event->get('survey'), "New response")
                    ],
                    'updateConfirmation' => [
                        'type' => 'string',
                        'label' => 'Update confirmation message',
                        'current' => $this->get('updateConfirmation', 'Survey', $event->get('survey')),
                        'help' => 'Leave empty for no confirmation'
                    ],
                    'repeatConfirmation' => [
                        'type' => 'string',
                        'label' => 'Default repeat confirmation message',
                        'current' => $this->get('repeatConfirmation', 'Survey', $event->get('survey')),
                        'help' => 'Leave empty for no confirmation'
                    ],
                    'deleteConfirmation' => [
                        'type' => 'string',
                        'label' => 'Default delete confirmation message',
                        'current' => $this->get('deleteConfirmation', 'Survey', $event->get('survey')),
                        'help' => 'Leave empty for no confirmation'
                    ]



                ],
            ];
            $event->set("surveysettings.{$this->id}", $settings);

        }

        /**
         * Renders the table containing responses to available for the current token.
         * @param \CHttpRequest $request
         * @param $responses
         */
        protected function renderOptions($request, $responses)
        {
            $sid = $request->getParam('sid');
            $token  = $request->getParam('token');
            $lang = $request->getParam('lang');
            $newtest = $request->getParam('newtest');
            $params = [
                'ResponsePicker' => 'new',
            ];
            if (isset($sid))
            {
                $params['sid'] = $sid;
            }
            if (isset($token))
            {
                $params['token'] = $token;
            }
            if (isset($lang))
            {
                $params['lang'] = $lang;
            }
            if (isset($newtest))
            {
                $params['newtest'] = $newtest;
            }

            $result = [];
            foreach ($responses as $response)
            {
                $result[] = [
                    'data' => $this->api->getResponse($response->surveyId, $response->id),
                    'urls' => [
                        'delete' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'delete', 'surveyId' => $response->surveyId, 'responseId' => $response->id, 'token' => $response->token]),
                        'read' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'read', 'surveyId' => $response->surveyId, 'responseId' => $response->id, 'token' => $response->token]),
                        'update' => $this->api->createUrl('survey/index', array_merge($params, ['ResponsePicker' => $response->id])),
                        'copy' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'copy', 'surveyId' => $response->surveyId, 'responseId' => $response->id]),
                        
                    ],
                ];
            }
            $result[] = [
                'id' => 'new',
                'url' => $this->api->createUrl('survey/index', $params)
            ];
            $this->renderHtml($result, $sid, $request);
        }

        protected function renderJson($result) {
            header('Content-Type: application/json');
            ob_end_clean();
            echo json_encode($result, JSON_PRETTY_PRINT);
            die();
        }


        /**
         * Render an array of responses as an HTML table.
         * @param $result
         * @param $sid
         * @throws CException
         */
        protected function renderHtml($result, $sid, \CHttpRequest $request)
        {
            Yii::app()->clientScript->reset();
            /** @var CAssetManager $am */
            $am = \Yii::app()->assetManager;
            \Yii::app()->params['bower-asset'] = $am->publish(__DIR__ . '/vendor/bower-asset', false, -1);

            $new = array_pop($result);
            $columns = [];
            if (isset($result[0]['data'])) {
                foreach($result[0]['data'] as $key => $value) {
                    $columns[$key] = true;
                }
            }

            $template = [];
            if ($this->viewEnabled($sid)) {
                $template[] = '{view}';
            }

            if ($this->updateEnabled($sid)) {
                $template[] = '{update}';
            }

            if ($this->repeatEnabled($sid)) {
                $template[] = '{repeat}';
            }

            if ($this->deleteEnabled($sid)) {
                $template[] = '{delete}';
            }

            if (!empty($template)) {
                $gridColumns['actions'] = [
                    'header' => 'Actions',
                    'htmlOptions' => [
                        'width' => '100px',
                    ],
                    'class' => \CButtonColumn::class,
                    'template' => implode(' ', $template),
                    'buttons' => [
                        'view' => [
                            'label' => '<span class="oi oi-eye"></span>',
                            'options' => [
                                'title' => 'View response',
                            ],
                            'imageUrl' => false,
                            'url' => function ($data) {
                                return $data['urls']['read'];
                            }
                        ],
                        'update' => [
                            'label' => '<i class="oi oi-pencil"></i>',
                            'imageUrl' => false,
                            'options' => [
                                'title' => 'Edit response',
                                'data-confirm' => $this->get('updateConfirmation', 'Survey', $sid)
                            ],
                            'url' => function ($data) {
                                return $data['urls']['update'];
                            }
                        ],
                        'repeat' => [
                            'label' => '<i class="oi oi-plus"></i>',
                            'imageUrl' => false,
                            'options' => [
                                'title' => 'Update response',
                                'data-method' => 'post',
                                'data-body' => json_encode([
                                    $request->csrfTokenName => $request->csrfToken
                                ]),
                                'data-confirm' => $this->get('repeatConfirmation', 'Survey', $sid)
                            ],
                            'url' => function ($data) {
                                return $data['urls']['copy'];
                            }
                        ],
                        'delete' => [
                            'click' => 'js:function() {}',
                            'label' => '<i class="oi oi-trash"></i>',
                            'imageUrl' => false,
                            'options' => [
                                'title' => 'Delete data',
                                'data-confirm' => $this->get('deleteConfirmation', 'Survey', $sid),
                                'data-method' => 'delete',
                                'data-body' => json_encode([
                                    $request->csrfTokenName => $request->csrfToken
                                ])
                            ],

                            'url' => function ($data) {
                                return $data['urls']['delete'];
                            }
                        ]
                    ]
                ];
            }
            foreach($result as $row) {
                $uoid = $row['data']['UOID'];
                $row['uoid'] = $uoid;
                if (!isset($series[$uoid])) {
                    $series[$uoid] = $row;
                } else if($series[$uoid]['data']['id'] < $row['data']['id'])
                    $series[$uoid] = $row;
            }
            
            foreach($result as &$row) {
                $uoid = $row['data']['UOID'];
                $row['final'] = ($series[$uoid]['data']['id'] === $row['data']['id']) ? "True" : "False";
                if($row['final'] === "False") {
                    $series[$uoid]['child'][] = $row;
                } else {
                    $series[$uoid]['final'] = "True";
                }
            }

            $result = $series;

            $gridColumns['uoid'] = [
                'name' => 'uoid',
                'header' => 'uoid',
                'filter' => 'select-strict'
            ];

            $configuredColumns = explode("\r\n", $this->get('columns', 'Survey', $sid, ""));
            foreach($configuredColumns as $column) {
                $parts = explode(':', $column);
                list($name, $filter, $title) = array_pad($parts, 3, null);
                $question = Question::model()->findByAttributes([
                    'sid' => $sid,
                    'title' => $name
                ]);
                if (isset($question)) {
                    $answers = [];
                    foreach (Answer::model()->findAllByAttributes([
                        'qid' => $question->qid,
                    ]) as $answer) {
                        $answers[$answer->code] = $answer;
                    }

                    $gridColumns[$name] = [
                        'name' => "data.$name",
                        'header' => $title ?? $question->question,
                        'filter' => empty($filter) ? false : $filter,
                    ];
                    if (isset($answers) && !empty($answers)) {
                        $gridColumns[$name]['value'] = function ($row) use ($answers, $name) {
                            if (isset($answers[$row['data'][$name]])) {
                                return $answers[$row['data'][$name]]->answer;
                            } else {
                                return "No text found for: $name";
                            }
                        };
                    }
                } elseif (isset($row['data'][$name])) {
                    // Direct property
                    $gridColumns[$name] = [
                        'name' => "data.$name",
                        'header' => $title ?? "Title not configured",
                        'filter' => empty($filter) ? false : $filter,
                    ];
                }
            }


            foreach ($columns as $column => $dummy) {
                if (substr($column, 0, 4) == 'DISP') {
                    $gridColumns[$column] = [
                        'name' => "data.$column",
                        'header' => ucfirst($column),
                        'filter'=> 'select-strict'
                    ];
                }
            }
            header('Content-Type: text/html; charset=utf-8');

            echo '<html><title></title>';

            echo '<body style="padding: 20px;">';
            if ($this->get('create', 'Survey', $sid)) {
                echo \CHtml::link($this->get('newheader', 'Survey', $sid, "New response"), $new['url'],
                    ['class' => 'btn btn-primary']);
            }
            \Yii::import('zii.widgets.grid.CGridView');

            echo Yii::app()->controller->widget(SamIT\Yii1\DataTables\DataTable::class, [
                'dataProvider' => new CArrayDataProvider($result, [
                    'pagination' => [
                        'pageSize' => 10,
                    ],
                    'keyField' => false,
                    'sort' => [
                        'defaultOrder' => [
                            'data.id' => CSort::SORT_DESC
                        ]
                    ],
                ]),

                'itemsCssClass' => 'table-bordered table table-striped dataTable',
                'pageSizeOptions' => [-1, 10, 25],
                'filter' => true,
                'columns' => $gridColumns
                
            ], true);
            $this->registerClientScript(Yii::app()->clientScript);
            echo '<script>';
                echo 'let actions = '.json_encode($gridColumns['actions']['buttons']).';';
                echo 'let json = '.json_encode($series).';';
            echo '</script>';
            echo '</body></html>';
            die();
        }

        /**
         * Updates survey settings
         */
        public function newSurveySettings()
        {
            $surveyId = $this->event->get('survey');
            foreach ($this->event->get('settings') as $name => $value)
            {
                $this->set($name, $value, 'Survey', $surveyId);
            }
        }

        protected function registerClientScript(\CClientScript $clientScript)
        {
            $bowerPath = \Yii::app()->params['bower-asset'];
            // Bootstrap 4
            $clientScript->registerScriptFile("$bowerPath/bootstrap/dist/js/bootstrap.min.js");
            $clientScript->registerCssFile("$bowerPath/bootstrap/dist/css/bootstrap.min.css");
            // Iconic
            $clientScript->registerCssFile("$bowerPath/open-iconic/font/css/open-iconic-bootstrap.min.css");
            // Bootbox
            $clientScript->registerScriptFile("$bowerPath/bootbox/bootbox.js");

            $clientScript->registerCss('select', implode("\n", [
                '.datatable-view {
                    padding-top: 16px;
                }

                table {
                    -webkit-border-horizontal-spacing: 0px;
                    -webkit-border-vertical-spacing: 0px;
                }

                html {
                    border: none;
                }

                body {
                    border: none;
                    --primary-button-background-color: #4177c1;
                    --primary-button-color: white;
                    --main-background-color: #e0e0e0;
                    background-color: var(--main-background-color);
                }

                a {
                    color: #5791e1;
                }

                .btn {
                    cursor: pointer;
                    padding: 8px 12px;
                    border-radius: 6px;
                    font-size: 14px;
                    box-shadow: none;
                    background-image: none;
                    border: none;
                    text-decoration: none;
                    outline: none;
                    text-align: center;
                }

                .btn-primary {
                    background-color: var(--primary-button-background-color);
                    color: var(--primary-button-color);
                    border: 1px solid var(--primary-button-background-color);
                    transition: color 0.2s, background 0.2s, border 0.2s;
                    text-shadow: none;
                }

                .btn.new-facility {
                    float: right;
                    margin-top: 20px;
                }

                table,
                table.dataTable {
                    background-color: white;
                    padding: 0;
                    overflow: auto;
                    border-collapse: collapse;
                }

                .datatable-view {
                    background: white;
                    border-radius: 10px;
                    padding: 20px 25px;
                    margin-top: 20px;
                }

                .page-link {
                    color: var(--primary-button-background-color);
                    font-size: 13px;
                }

                .form-control {
                    font-size: 12px;
                    height: 38px;
                }

                div.dataTables_wrapper div.dataTables_length label,
                div.dataTables_wrapper div.dataTables_info {
                    font-size: 12px;
                }

                .dataTables_wrapper.container-fluid {
                    margin: 0;
                    padding: 0;
                }

                table thead tr th,
                .table thead tr th {
                    background: #f6f6f6;
                    color: #9d9d9d;
                    font-family: "Source Sans Pro";
                    font-weight: 400;
                    text-transform: uppercase;
                    font-size: 11px;
                    padding: 10px;
                }

                .table tbody tr.group {
                    cursor: pointer;
                    background-color: rgba(0, 0, 0, .05) !important;
                }

                .table tbody tr.group:hover {
                    background-color: #ddd;
                }

                .table tbody tr.group td {
                    color: #222222 !important;
                    padding: 10px 5px 10px 10px;
                    font-family: "Source Sans Pro";
                    vertical-align: middle;
                    font-size: 14px;
                }


                table.dataTable {
                    padding: 0;
                    margin: 0 !important;
                    width: 100%;
                    border: none;
                    border-collapse: collapse;
                    border-spacing: 0px;
                    font-size: 14px;
                }

                .table td,
                .table th {
                    padding: 0;
                }

                table tbody tr.odd td,
                table tbody tr.even td {
                    padding: 10px;
                }


                table.child {
                    width: 100%;
                    table-layout: fixed;
                }

                table.child tbody tr {
                    background-color: #42424a !important;
                    color: white;
                    cursor: default;
                }

                table.child tbody tr:hover {
                    background-color: #42424a;
                }

                table.child tbody tr td,
                table.child tbody tr.odd td,
                table.child tbody tr.even td {
                    color: white !important;
                    border: none;
                    font-size: 14px;
                    padding: 10px;
                }

                table tbody tr td a .oi {
                    display: none;
                }
                table.child tbody tr td a .oi {
                    display:inline-block;
                }
                table.child tbody tr td a {
                    color: white;
                    padding-right: 5px;
                }

                table.child tbody tr td a:hover {
                    color: grey;
                }

                table.child tbody tr td table {
                    padding: 0;
                }

                table.child tbody tr td.button-column {
                    width: 93px;
                    padding-left: 20px;
                }

                div.adding {
                    background-color: #42424a;
                    cursor: pointer;
                    padding: 15px 10px;
                }

                div.adding a {
                    color: white !important;
                    font-size: 12px;
                    text-decoration: none;
                    background-color: #5791e1;
                    padding: 5px 10px !important;
                    border-radius: 10px;
                    height: 50px;
                }'
            ]));
            
            $clientScript->registerScript('confirm', <<<JS
                $(document).on('click', 'a[data-confirm], a[data-method]', function(e) {
                    e.preventDefault();
                    var url = $(this).attr('href');
                    var method = $(this).data('method') || "GET";
                    var body = $(this).data('body');
                    
                    // Send request with correct method
                    var handler = function() {
                        if (method === "GET") {
                            window.location.href = url;
                        } else {
                            $.ajax({
                                "type": method,
                                "url": url,
                                "data": body
                            }).done(function(data, status, xhr) {
                                if (xhr.getResponseHeader('Location')) {
                                    window.location.href = xhr.getResponseHeader('Location'); 
                                } else {
                                    window.location.reload();
                                }
                            })
                        }
                    };
                    
                    if ($(this).data('confirm')) {
                        bootbox.confirm($(this).data('confirm'), function(result) {
                            !result || handler();
                        });
                    } else {
                        handler();
                    }
                    
                    
                })

                function format(data, update, urls) {
                    return "<tr><td class='button-column'>" +
                        "<a title='"+actions.view.options.title+"' href='"+urls.read+"'>"+actions.view.label+"</a>"+
                        "<a title='"+actions.update.options.title+"' data-confirm='"+actions.update.options['data-confirm']+"' href='"+urls.update+"'>"+actions.update.label+"</a>"+
                        "<a title='"+actions.delete.options.title+"' data-confirm='"+actions.delete.options['data-confirm']+"' data-method='"+actions.delete.options['data-method']+"' data-body='"+actions.delete.options['data-method']+"' class='delete' href='"+urls.delete+"'>"+actions.delete.label+"</a>"+
                    '</td>' +
                    '<td rowspan="1" colspan="1" class="sorting">' + data.uoid + '</td>' +
                    '<td>' + update + '</td>' +
                    '<td></td>' +
                    '<td></td>' +
                    '<td></td>' +
                    '</tr>';
                };

                var table = $('#DataTables_Table_0').dataTable();
                var api = table.api();
                $('#DataTables_Table_0 tbody').on('click', 'td', function () {
                    var tr = $(this).closest('tr');
                    var row = api.row( tr );
                    var data = row.data();
                    
                    if (row.child.isShown()) {
                        // This row is already open - close it
                        row.child.hide();
                        tr.removeClass('shown');
                    } else {
                        // Open this row
                        let data = row.data();
                        if (json[data.uoid].child != null) {
                            var element = '<table class="child dataTable table-bordered table table-striped dataTable no-footer">';
                            element += format(data, data.data_Update, json[data.uoid].urls);
                            for (var child of json[data.uoid].child) {
                                element += format(data, child.data.Update, child.urls);
                            };
                            element += '</table>';

                            element += "<div class='adding'><a  title='"+actions.repeat.options.title+"' data-confirm='"+actions.repeat.options['data-confirm']+"' data-method='"+actions.repeat.options['data-method']+"' data-body='"+actions.repeat.options['data-method']+"'  href='"+json[data.uoid].urls.copy+"'>Add a new response</a></div>";
                            row.child(element).show();
                            tr.addClass('shown');
                        }
                    }
                } );

JS
            );
            $clientScript->scriptMap["jquery.dataTables.min.css"] = false;
            $clientScript->scriptMap["jquery.dataTables.css"] = false;
            $clientScript->registerCssFile("$bowerPath/datatables/media/css/dataTables.bootstrap4.min.css");
            $clientScript->registerScriptFile("$bowerPath/datatables/media/js/dataTables.bootstrap4.js", $clientScript::POS_END);
        }
    }
}