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
            $this->subscribe('beforeLoadResponse');
            // Provides survey specific settings.
            $this->subscribe('beforeSurveySettings');

            // Saves survey specific settings.
            $this->subscribe('newSurveySettings');

            $this->subscribe('newDirectRequest');

            $this->subscribe('newUnsecureRequest');
        }

        /**
         * @throws CHttpException
         * Unsecure means there is no CSRF.
         */
        public function newUnsecureRequest() {
            if ($this->event->get('target') == __CLASS__) {
                /** @var CHttpRequest $request */
                $request = $this->event->get('request');
                $surveyId = $request->getParam('surveyId');
                $responseId = $request->getParam('responseId');
                $token = $request->getParam('token');
                if (!isset($token, $surveyId, $responseId)) {
                    throw new \CHttpException(400, "Missing one or more mandatory parameters");
                }
                switch ($this->event->get("function")) {
                    case 'copy':
                        if (!$this->repeatEnabled($surveyId)) {
                            throw new \CHttpException(403, "Repeating not enabled for this survey");
                        }

                        if (!$request->isPostRequest) {
                            throw new \CHttpException(405, "This endpoint only supports POST");
                        }
                        $this->createCopy($surveyId, $responseId, $token);
                        break;
                    default:
                        throw new \CHttpException(404, "Unknown endpoint");
                }
            }
        }

        /**
         * This function renders a response in a table.
         * It uses LS internals which is not according to best practices.
         * @param $response
         * @param $surveyId
         * @throws CException
         * @throws Exception
         */
        private function viewResponse($response, $surveyId, $language)
        {
            $aFields = array_keys(createFieldMap($surveyId, 'full', true, false, $language));

            App()->loadHelper('admin.exportresults');
            App()->loadHelper('export');
            \Yii::import('application.helpers.viewHelper');
            App()->controller->__set('action', App()->controller->getAction());
            $oExport = new \ExportSurveyResultsService();
            $oFormattingOptions = new FormattingOptions();

            $oFormattingOptions->responseMinRecord = $response['id'];
            $oFormattingOptions->responseMaxRecord = $response['id'];

            $oFormattingOptions->selectedColumns = $aFields;
            $oFormattingOptions->responseCompletionState = 'all';
            $oFormattingOptions->headingFormat = 'full';
            $oFormattingOptions->answerFormat = 'long';
            $oFormattingOptions->output = 'file';



            $sTempFile = $oExport->exportSurvey($surveyId, $language, 'json', $oFormattingOptions, '');
            $data = array_values(json_decode(file_get_contents($sTempFile), true)['responses'][0])[0];

            $out = '<html><head><meta charset="UTF-8"></head>
                <title></title><body><table>';
            foreach ($data as $key => $value) {
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
        public function newDirectRequest()
        {
            if ($this->event->get('target') == __CLASS__) {
                /** @var CHttpRequest $request */
                $request = $this->event->get('request');
                $surveyId = $request->getParam('surveyId');
                $responseId = $request->getParam('responseId');
                $token = $request->getParam('token');
                switch ($this->event->get("function")) {
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
                            $this->viewResponse($response, $surveyId, $request->getParam('language', 'en'));
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
        protected function createCopy($surveyId, $responseId, $token = null)
        {
            if (null === $response = \Response::model($surveyId)->findByPk($responseId)) {
                throw new \CHttpException(404, "Response not found.");
            }

            if (isset($token) && !$response->token === $token) {
                throw new \CHttpException(403, "Token not valid");
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

            $attributePrefixes = array_map(function (Question $question) {
                return "{$question->sid}X{$question->gid}X{$question->qid}";
            }, $questions);

            /** @var Question $question */
            foreach ($attributePrefixes as $prefix) {
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
            $this->defaultLang = 'en';
            if (is_array($responses) && count($responses) > 0)
                $this->defaultLang = $responses[0]->attributes['startlanguage'];
            /**
             * @var LSHttpRequest
             */
            $request = $this->api->getRequest();

            // Only handle get requests.
            if ($request->requestType == 'GET') {
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
            } else {
                if (isset($_SESSION['ResponsePicker'])) {

                    $choice = $_SESSION['ResponsePicker'];
                    unset($_SESSION['ResponsePicker']);
                    if ($choice == 'new') {
                        $this->event->set('response', false);
                    } else {
                        foreach ($responses as $response) {
                            if ($response->id == $choice) {

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
                        'label' => 'Enable view button: ',
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

        /** Adding double quote is forbidden
         * @return void
         */
        protected function setTranslations()
        {
            $this->translation['en']['No data found for'] = 'No data found for';
            $this->translation['fr']['No data found for'] = 'Pas de donnée pour';
            $this->translation['ar']['No data found for'] = 'لم يتم ايجاد نص ل';
            $this->translation['pt']['No data found for'] = 'Sem dados para';
            $this->translation['uk']['No data found for'] = 'Немає даних';
            $this->translation['en']['Add an update'] = 'Add an update';
            $this->translation['fr']['Add an update'] = 'Ajouter une mise à jour';
            $this->translation['ar']['Add an update'] = 'إضافة تحديث جديد';
            $this->translation['pt']['Add an update'] = 'Acrescentar uma nova resposta';
            $this->translation['uk']['Add an update'] = 'Додати нову відповідь';
            $this->translation['en']['Update ID'] = 'Update ID';
            $this->translation['fr']['Update ID'] = 'Id de la réponse';
            $this->translation['ar']['Update ID'] = 'رقم التحديث';
            $this->translation['pt']['Update ID'] = 'Codigo de resposta';
            $this->translation['uk']['Update ID'] = 'ID відповіді';
            $this->translation['en']['Date of update'] = 'Date of update';
            $this->translation['fr']['Date of update'] = 'Date de mise à jour';
            $this->translation['ar']['Date of update'] = 'تاريخ التحديث';
            $this->translation['pt']['Date of update'] = 'Data de actualização';
            $this->translation['uk']['Date of update'] = 'Дата оновлення';
            $this->translation['en']['Actions'] = 'Actions';
            $this->translation['fr']['Actions'] = 'Actions';
            $this->translation['ar']['Actions'] = 'إجراءات';
            $this->translation['pt']['Actions'] = 'Acções';
            $this->translation['uk']['Actions'] = 'Дії';
            $this->translation['en']['New'] = 'New';
            $this->translation['fr']['New'] = 'Nouveau';
            $this->translation['ar']['New'] = 'جديد';
            $this->translation['pt']['New'] = 'Novo';
            $this->translation['uk']['New'] = 'Нова';
            $this->translation['en']['# of updates'] = '# of updates';
            $this->translation['fr']['# of updates'] = '# de mises à jour';
            $this->translation['ar']['# of updates'] = 'عدد التحديثات';
            $this->translation['pt']['# of updates'] = '# de resposta';
            $this->translation['uk']['# of updates'] = '# оновлень';
        }

        /**
         * @param string $lang
         * @param string $key
         * @return string
         */
        protected function getTranslation($lang, $key)
        {
            if (!array_key_exists($lang, $this->translation))
                $lang = 'en';
            if (!array_key_exists($key, $this->translation[$lang]))
                $lang = 'en';
            return $this->translation[$lang][$key];
        }

        /**
         * Renders the table containing responses to available for the current token.
         * @param \CHttpRequest $request
         * @param $responses
         */
        protected function renderOptions($request, $responses)
        {
            $params['sid'] = $request->getParam('sid');
            $params['token'] = $request->getParam('token');
            $params['lang'] = $request->getParam('lang');
            $params['newtest'] = $request->getParam('newtest');
            $params['ResponsePicker'] = 'new';

            $params = array_filter($params);

            $result = [];
            foreach ($responses as $response) {
                $result[] = [
                    'data' => $this->api->getResponse($response->surveyId, $response->id),
                    'urls' => [
                        'delete' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'delete', 'surveyId' => $response->surveyId, 'responseId' => $response->id, 'token' => $response->token]),
                        'read' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'read', 'surveyId' => $response->surveyId, 'responseId' => $response->id, 'token' => $response->token, 'language' => $params['lang'] ?? 'en']),
                        'update' => $this->api->createUrl('survey/index', array_merge($params, ['ResponsePicker' => $response->id])),
                        'copy' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'copy', 'surveyId' => $response->surveyId, 'responseId' => $response->id]),

                    ],
                ];
            }
            $newResponse = $this->api->createUrl('survey/index', $params);

            unset($params['ResponsePicker']);
            unset($params['lang']);
            $baseUrl = $this->api->createUrl('survey/index', $params);

            $this->renderHtml($result, $newResponse, $baseUrl, $params['sid'], $request);
        }

        protected function renderJson($result)
        {
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
        protected function renderHtml($result, $newResponse, $baseUrl, $sid, \CHttpRequest $request)
        {
            Yii::app()->clientScript->reset();
            /** @var CAssetManager $am */
            $am = \Yii::app()->assetManager;
            \Yii::app()->params['bower-asset'] = $am->publish(__DIR__ . '/vendor/bower-asset', false, -1);

            //we set the default language to the survey base language
            $baseLang = getSurveyInfo($sid)['language'];
            //we get all available languages for the survey
            $surveyLang = getSurveyInfo($sid)['additional_languages'];
            //if there is a lang in the url we use it, or we use the browser lang
            $lang = $request->getParam('lang') !== null ? $request->getParam('lang') : $this->get_browser_language();
            $lang = strpos($lang, '-') !== false ? explode('-', $lang)[0] : $lang;
            $lang = strtolower($lang);
            $availableLanguages[] = $baseLang;
            if (strpos($surveyLang, ' ') !== false)
                $availableLanguages = array_merge($availableLanguages, explode(' ',$surveyLang));
            else if (!empty($surveyLang)) $availableLanguages[] = $surveyLang;
            $this->language = $baseLang;
            //if the survey has the language then we choose it 
            if (strpos($surveyLang, $lang) !== false)
                $this->language = $lang;

            $this->setTranslations();

            $columns = [];
            if (isset($result[0]['data'])) {
                foreach ($result[0]['data'] as $key => $value) {
                    $columns[$key] = true;
                }
            }

            $template = [];
            if ($this->viewEnabled($sid)) {
                $template[] = '{view}';
            }

            if ($this->updateEnabled($sid) && $request->getQuery('editButton', 1)) {
                $template[] = '{update}';
            }

            if ($this->repeatEnabled($sid) && $request->getQuery('copyButton', 1)) {
                $template[] = '{repeat}';
            }

            if ($this->deleteEnabled($sid) && $request->getQuery('deleteButton', 1)) {
                $template[] = '{delete}';
            }


            /*$gridColumns['control'] = [
                'name' => '',
                'header' => '',
                'htmlOptions' => [
                    'class' => 'open',
                    'width' => '20px',
                ],
                'filter' => false
            ];*/

            $gridColumns['count'] = [
                'name' => 'count',
                'header' => $this->getTranslation($this->language, '# of updates'),
                'htmlOptions' => [
                    'width' => '20px',
                ],
                'filter' => false
            ];

            if (!empty($template)) {
                $gridColumns['actions'] = [
                    'header' => 'actions',
                    'visible' => false,
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
                                'title' => $this->getTranslation($this->language, 'Add an update'),
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

            foreach ($result as $item) {
                $uoid = $item['data']['UOID'];
                $item['uoid'] = $uoid;
                if (array_key_exists('Update', $item['data'])) {
                    $item['data']['Update'] = date('Y-m-d', strtotime($item['data']['Update']));
                }

                if (!isset($series[$uoid])) {
                    $series[$uoid] = $item;
                }
                else
                { 
                    if ($series[$uoid]['data']['Update'] < $item['data']['Update']) {
                        $item['child'] = $series[$uoid]['child'];
                        $item['child'][] = $item;
                        unset($series[$uoid]['child']);
                        $series[$uoid] = $item;
                    } 
                    else 
                        $series[$uoid]['child'][] = $item;
                }

                $series[$uoid . "_" . $item['data']['id']] = $item;
                $series[$uoid]['count'] = count($series[$uoid]['child']) + 1;
            }

            $configuredColumns = explode("\r\n", $this->get('columns', 'Survey', $sid, ""));
            foreach ($configuredColumns as $column) {
                $parts = explode(':', $column);
                if (is_array($parts) && $parts[0] == "d") {
                    $filteredKeys[$parts[1]] = true;
                    array_shift($parts);
                }
                list($name, $filter, $title) = array_pad($parts, 3, null);
                $question = Question::model()->findByAttributes([
                    'sid' => $sid,
                    'title' => $name,
                    'language' => $this->language
                ]);
                if (isset($question)) {
                    $answers = [];
                    foreach (Answer::model()->findAllByAttributes([
                        'qid' => $question->qid,
                        'language' => $this->language
                    ]) as $answer) {
                        $answers[$answer->code] = $answer;
                    }
                    $header = $title ?? $question->question;
                    $header = strpos($header, ':') !== false ? explode(':', $header)[0] : $header;
                    $gridColumns[$name] = [
                        'name' => "data.$name",
                        'header' => $header,
                        'filter' => empty($filter) || array_key_exists($name,$filteredKeys) ? false : $filter,
                    ];
                    $advSettings = Question::model()->getAdvancedSettingsWithValues($question->qid, $question->type, $sid, $this->language);
                    if($question->type == 'D' && isset($advSettings) && array_key_exists('date_format',$advSettings) && array_key_exists('value',$advSettings['date_format']) ) {                
                        $dateFormat = $advSettings['date_format']['value'];
                        $gridColumns[$name]['value'] = function ($row) use ($dateFormat, $name) {
                            if(isset($dateFormat) && !empty($row['data'][$name])) {
                                $formatArr = explode('-',$dateFormat);
                                if(is_array($formatArr) && count($formatArr) > 0){
                                    $dateFormat = "";
                                    foreach($formatArr as $format) {
                                        if(strpos($format, 'y') !== false)
                                            $dateFormat .= strtoupper($format[0])."-";
                                        else $dateFormat .= $format[0]."-";
                                    }
                                    $dateFormat = rtrim($dateFormat,'-');
                                }
                                $time = strtotime($row['data'][$name]);
                                $row['data'][$name] = date($dateFormat,$time);
                            }
                            return $row['data'][$name];
                        };
                    }
                    if (isset($answers) && !empty($answers)) {
                        $gridColumns[$name]['value'] = function ($row) use ($answers, $name) {
                            if (isset($answers[$row['data'][$name]])) {
                                return $answers[$row['data'][$name]]->answer;
                            } else {
                                return $this->getTranslation($this->language, 'No data found for') . " $name";
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
            
            foreach($filteredKeys as $key => $value) {
                $gridColumns += array_splice($gridColumns,array_search($key,array_keys($gridColumns)),1);
            }

            if (!array_key_exists('UOID', $gridColumns)) {
                $gridColumns['UOID'] = [
                    'name' => 'data.UOID',
                    'header' => 'UOID',
                    'filter' => false
                ];
            }

            foreach ($columns as $column => $dummy) {
                if (substr($column, 0, 4) == 'DISP') {
                    $gridColumns[$column] = [
                        'name' => "data.$column",
                        'header' => ucfirst($column),
                        'filter' => 'select-strict'
                    ];
                }
            }
            header('Content-Type: text/html; charset=utf-8');

            echo '<html><title></title>';

            echo CHtml::tag('body', ['class' => $request->getQuery('seamless', 0) ? 'seamless' : ''], false, false);

            if (count($availableLanguages) > 1) {
                echo "<select id='languagePicker' class='form-control' onChange='changeLanguage();'>";
                foreach ($availableLanguages as $lang) {
                    $state = $lang == $this->language ? 'selected' : '';
                    echo "<option value='{$baseUrl}&lang={$lang}' {$state}>{$lang}</option>";
                }
                echo "</select>";
            }
            if ($request->getQuery('createButton', 1) && $this->get('create', 'Survey', $sid)) {
                echo \CHtml::link(
                    $this->get('newheader', 'Survey', $sid, "New response"),
                    $newResponse,
                    ['class' => 'btn btn-primary']
                );
            }
            \Yii::import('zii.widgets.grid.CGridView');

            echo Yii::app()->controller->widget(SamIT\Yii1\DataTables\DataTable::class, [
                'dataProvider' => new CArrayDataProvider($series, [
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

            $updateHeader = "Date of update";
            if (array_key_exists('Update', $gridColumns)) {
                $updateHeader = strpos($gridColumns['Update']['header'], ':') !== false ? explode(':', $gridColumns['Update']['header'])[0] : $gridColumns['Update']['header'];
            } else {
                $updateHeader = $this->getTranslation($this->language, 'Date of update');
            }
            if (array_key_exists('qid', $gridColumns)) {
                $idHeader = strpos($gridColumns['qid']['header'], ':') !== false ? explode(':', $gridColumns['qid']['header'])[0] : $gridColumns['qid']['header'];
            } else {
                $idHeader =  $this->getTranslation($this->language, 'Update ID');
            }
            $responsesColumns = [];
            $responsesColumns['actions'] = ["name" => "actions", "header" => $this->getTranslation($this->language, 'Actions'), "filter" => false];
            $responsesColumns['Update'] = ["name" => 'update', "header" => $updateHeader, "filter" => false];
            $responsesColumns['id'] = ["name" => "responseId", "header" => $idHeader, "filter" => false];
            if ($filteredKeys) $responsesColumns = array_merge($responsesColumns,array_intersect_key($gridColumns, $filteredKeys));
            
            $actions = $gridColumns['actions']['buttons'];
            $template = explode(' ', $gridColumns['actions']['template']);
            foreach ($gridColumns['actions']['buttons'] as $key => $action) {
                if (!in_array('{' . $key . '}', $template))
                    $actions[$key] = null;
            }
            echo '<script>';
            echo "function changeLanguage() {
                    let lp = document.getElementById('languagePicker');
                    let url = lp.options[lp.selectedIndex].value;
                    document.location.href= url; 
                }";
            echo 'let columns = ' . json_encode($responsesColumns) . ';';
            echo 'let actions = ' . json_encode($actions) . ';';
            echo '</script>';
            echo '</body></html>';
            die();
        }

        /**
         * Get browser language, given an array of avalaible languages.
         * 
         * @param  [array]   $availableLanguages  Avalaible languages for the site
         * @param  [string]  $default             Default language for the site
         * @return [string]                       Language code/prefix
         */
        public function get_browser_language($available = [], $default = 'en')
        {
            if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {

                $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);

                if (empty($available)) {
                    return $lang = substr($langs[0], 0, 2);
                }

                foreach ($langs as $lang) {
                    $lang = substr($lang, 0, 2);
                    if (in_array($lang, $available)) {
                        return $lang;
                    }
                }
            }
            return $default;
        }

        /**
         * Updates survey settings
         */
        public function newSurveySettings()
        {
            $surveyId = $this->event->get('survey');
            foreach ($this->event->get('settings') as $name => $value) {
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

            // Iframe resizer
            $clientScript->registerScriptFile('https://unpkg.com/iframe-resizer@4.3.1/js/iframeResizer.contentWindow.min.js', CClientScript::POS_HEAD, [
                'defer' => true
            ]);

            $clientScript->registerCss('select', <<<CSS
                .datatable-view {
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
                    padding: 20px;
                }

                a {
                    color: #5791e1;
                }

                select {
                    margin: 0 auto;
                    max-width: 150px;
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

                .table tbody tr {
                    cursor: pointer;
                    transition: color 0.2s;
                }

                .table tbody tr:hover td {
                    cursor: pointer;
                    color:#5791e1;
                    transition: color 0.2s;
                }

                .table tbody tr.shown td{
                    color:#5791e1;
                }


                table tbody tr.new td:nth-child(2):after, .table.new tbody tr:nth-child(2) td.update-column:after {
                    content: "{$this->getTranslation($this->language,'New')}";
                    font-size: 11px;
                    background: #60cb00;
                    color: white;
                    padding: 1px 6px 3px;
                    margin-left: 15px;
                    border-radius: 5px;
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
                    position: relative;
                }

                .responses-list {
                    background-color: #42424a !important;
                    table-layout: auto;
                    margin: 0 auto !important;
                    width: 99.5%;
                    border-bottom: 3px solid #333333;
                    border-top: 3px solid #5791e1;
                    padding-top: 3px;
                    padding-bottom: 50px;
                    padding-left: 40px;
                    padding-right: 40px;
                    cursor: default !important;
                }

                .table table.child {
                    table-layout: auto !important;
                    cursor: default !important;
                    background-color: #42424a;
                    width: auto;
                    min-width: 50%;
                }
                .table table.child td {
                    width: auto;
                    white-space:nowrap;
                }

                .table table.child.hasConfigurableColumns {
                    width: 100%;
                    table-layout: auto !important;
                }



                table.child thead tr td{
                    text-transform: uppercase;
                    border-top: 1px solid #333 !important;
                    color: #9d9d9d !important;
                    font-size: 11px;
                }

                table.child tr {
                    background-color: #42424a !important;
                    cursor: default !important;
                    margin-left: 10px;
                    padding-top: 10px;
                }

                table.child tr td {
                    border: none;
                    font-size: 14px;
                    padding: 10px 20px;
                    cursor: default !important;
                }

                table.child tr.response td {
                    border-top: 1px solid #333 !important;
                    color: grey;
                }

                table.child tr.response.enabled td {
                    color: white;
                }

                table.child tr.response:last-child td {
                    border-bottom: 1px solid #333 !important;
                }

                table.child tr td a .oi {
                    display:inline-block;
                }
                table.child tr td a {
                    color: white;
                    padding-right: 5px;
                }

                table.child tr td a:hover {
                    color: grey;
                }

                table.child tr td table {
                    padding: 0;
                }

                .name-column {
                    padding: 20px;
                    width:auto;
                    font-size: 16px;
                    line-height: 20px;
                    color: white;
                }

                .name-column i {
                    font-size: 12px;
                    line-height: 12px;
                    margin-left: 5px;
                    display:inline-block;
                }


                table.child tr td.title-column {
                    padding-left: 20px;
                    width:auto;
                    font-size: 13px;
                    line-height: 16px;
                    color: #999999 !important;
                }

                table.child tr td.button-column a {
                    margin-right: 7px;
                }

                table.child tr td.button-column a:last-child {
                    margin-right: 0px;
                }
                table.child tr td.button-column-add {
                    border-top: 1px solid #333;
                    padding-top: 15px;
                    padding-bottom: 15px;
                }
                table.child tr td.button-column-add a {
                    margin: 5px 0;
                    font-style: normal;
                    transition: color 0.2s;
                    font-size: 12px;
                    color: white; 
                    background: #5791e1;
                    border-radius: 5px;
                    padding: 5px 7px;
                }

                table.child tr td.button-column-add a:hover {
                    text-decoration: none;
                    color: #5791e1; 
                    background: white;
                    transition: color 0.2s;
                }

                body.seamless {
                    background-color: transparent;
                    padding: 0;
                    height: min-content;
                }

                body.seamless .datatable-view {
                    padding: 0;
                    border-radius: 0;
                    margin: 0;
                }
CSS
            );

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

                function createActions(cell, urls, showEditActions) {
                    cell.classList.add('button-column');
                    var link;
                    if(actions.view) {
                        link = document.createElement('a');
                        link.setAttribute('title', actions.view.options.title);
                        link.setAttribute('href', urls.read);
                        link.innerHTML = actions.view.label;
                        cell.appendChild(link);
                    }
                    
                    if(actions.update && showEditActions) {
                        link = document.createElement('a');
                        link.setAttribute('title', actions.update.options.title);
                        link.setAttribute('data-confirm', actions.update.options['data-confirm']);
                        link.setAttribute('href', urls.update);
                        link.innerHTML = actions.update.label;
                        cell.appendChild(link);
                    }

                    if(actions.delete && showEditActions) {
                        link = document.createElement('a');
                        link.setAttribute('title', actions.delete.options.title);
                        link.setAttribute('data-confirm', actions.delete.options['data-confirm']);
                        link.setAttribute('data-method', actions.delete.options['data-method']);
                        link.setAttribute('data-body', actions.delete.options['data-body']);
                        link.setAttribute('href', urls.delete);
                        link.innerHTML = actions.delete.label;
                        cell.appendChild(link);
                    }
                    
                    return cell;
                }

                function addResponseItem(childData, urls, isEnabled, showEditActions) {
                    let row = document.createElement('tr');
                    row.classList.add('response');
                    if(isEnabled) row.classList.add('enabled');
                    for(let column in columns) {
                        var cell = document.createElement('td');
                        if(column == "actions") createActions(cell, urls, showEditActions);
                        else if("data_"+column in childData) {
                            cell.innerHTML = childData["data_"+column];
                            if(childData["data_"+column].includes(':'))
                                cell.innerHTML = childData["data_"+column].split(':')[0];
                            if(cell.innerHTML == 'No data found for') cell.innerHTML = 'no data';
                            cell.classList.add(columns[column].name+'-column');
                        }
                        row.appendChild(cell);
                    }
                    return row;
                    
                };

                function addName(name, geo1) {
                    let div = document.createElement('div');
                    div.classList.add('name-column');
                    div.innerHTML = name;
                    if(geo1) {
                        div.innerHTML += ' <i>' + geo1 + '</i>';
                    }
                    return div;
                };

                function createHead(data) {
                    let head = document.createElement('thead');
                    let row = document.createElement('tr');
                    head.appendChild(row);
                    for(let i in columns) {
                        var cell = document.createElement('td');
                        cell.classList.add(columns[i].name+'-column');
                        cell.innerHTML = columns[i].header != null && columns[i].header.indexOf('-') ? columns[i].header.split(':')[0]: columns[i].header;
                        row.appendChild(cell);
                    }
                    return head;
                };

                function addNewReponseButton(urls, colspan) {
                    let row = document.createElement('tr')
                    let cell = document.createElement('td');
                    cell.classList.add('button-column-add');
                    cell.setAttribute('colspan', colspan);
                    let link = document.createElement('a');
                    link.setAttribute('title', actions.repeat.options.title);
                    link.setAttribute('data-confirm', actions.repeat.options['data-confirm']);
                    link.setAttribute('data-method', actions.repeat.options['data-method']);
                    link.setAttribute('data-body', actions.repeat.options['data-body']);
                    link.setAttribute('href', urls.copy);
                    link.innerHTML = actions.repeat.label+" "+actions.repeat.options.title;
                    cell.appendChild(link);
                    row.appendChild(cell);
                
                    return row;
                };
                

                let table = $('#DataTables_Table_0').dataTable();
                let api = table.api();
                
                let columnnames = api.settings().init().columns;
                for (var column in columns) {
                    if(column != "Update"){
                        let columnFound = columnnames.find(element => element.name == "data_"+column);
                        api.column(columnnames.indexOf(columnFound)).visible(false);
                    }
                }
                table.on('click', 'tr', function () {
                    var tr = $(this).closest('tr');
                    var isNew = $(tr).hasClass('new');
                    var row = api.row( tr );
                    var rowData = row.data();
                    let uoid = $(tr).attr('id');
                    if(!uoid) return;
                    if (row.child.isShown()) {
                        row.child.hide();
                        tr.removeClass('shown');
                        return;
                    } 
                    // Open this row
                    let jsonData = json[uoid];
                    if (jsonData.child != null) {
                        let columnsCount = Object.keys(columns).length;
                        let div = document.createElement('div');
                        div.classList.add('responses-list');
                        div.appendChild(addName(rowData.data_MoSD2, rowData.data_GEO1));
                        let tableElement = document.createElement('table');
                        if(columns != null && columnsCount > 3)
                            tableElement.classList.add('hasConfigurableColumns');
                        if(isNew) tableElement.classList.add('new');
                        tableElement.classList.add('child', 'dataTable', 'table-bordered', 'table', 'table-striped', 'dataTable', 'no-footer');
                        tableElement.appendChild(createHead(rowData));
                        let tableBody = document.createElement('tbody');
                        tableElement.appendChild(tableBody);
                        if(actions.repeat) tableBody.appendChild(addNewReponseButton(jsonData.urls, columnsCount));
                        for (var child of jsonData.child) {
                            tableBody.appendChild(addResponseItem(child, child.urls, child == jsonData.child[0], child == jsonData.child[0]));
                        };
                        div.appendChild(tableElement);
                        row.child(div).show();
                        tr.addClass('shown');
                    }
                } );

JS);
            $clientScript->scriptMap["jquery.dataTables.min.css"] = false;
            $clientScript->scriptMap["jquery.dataTables.css"] = false;
            $clientScript->registerCssFile("$bowerPath/datatables/media/css/dataTables.bootstrap4.min.css");
            $clientScript->registerScriptFile("$bowerPath/datatables/media/js/dataTables.bootstrap4.js", $clientScript::POS_END);
        }
    }
}
