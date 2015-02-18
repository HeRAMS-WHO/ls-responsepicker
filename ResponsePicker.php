<?php
    class ResponsePicker extends \ls\pluginmanager\PluginBase
    {

        static protected $description = 'This plugins allows a user to pick which response to work on if multiple candidate responses exist.';
        static protected $name = 'ResponsePicker';

        protected $storage = 'DbStorage';

        public function init()
        {
            $this->subscribe('beforeLoadResponse');
            // Provides survey specific settings.
            $this->subscribe('beforeSurveySettings');

            // Saves survey specific settings.
            $this->subscribe('newSurveySettings');
            
            $this->subscribe('newDirectRequest');
        }
        
        protected function viewResponse($response, $surveyId) {
            $out = '<html><title></title><body>';
            $rows = [];
            foreach ($response as $key => $value) {
                $rows[] = [
                    'question' => $key,
                    'answer' => $value
                ];
            }
            $out .= Yii::app()->controller->widget('zii.widgets.grid.CGridView', [
                'dataProvider' => new CArrayDataProvider($rows),
                'columns' => [
                    'question',
                    'answer'
                ]
                
            ], true);
            $out .= CHtml::link("Back to list", $this->api->createUrl('survey/index', ['sid' => $surveyId, 'token' => $response['token'], 'lang' => 'en', 'newtest' => 'Y']));
            $out .= '</body></html>';
            Yii::app()->getClientScript()->render($out);
            echo $out;
        }
        public function newDirectRequest() {
            if ($this->event->get('target') == __CLASS__) {
                $request = $this->event->get('request');
                $surveyId = $request->getParam('surveyId');
                $responseId = $request->getParam('responseId');
                $token = $request->getParam('token');
                switch($this->event->get("function")) {
                    case 'delete':
                        $response = Response::model($surveyId)->findByAttributes([
                            'id' => $responseId,
                            'token' => $token
                        ]);
                        if (isset($response) && $response->delete()) {
                            echo "Deleted.";
                        } elseif (!isset($response)) {
                            echo "Not found.";
                        } else {
                            echo "Delete failed.";
                        }
                        break;
                    case 'read':
                        $response = $this->api->getResponse($surveyId, $responseId);
                        if (isset($response)) {
                            $this->viewResponse($response, $surveyId);
                        } else {
                            throw new \CHttpException(404, "Response not found.");
                        }
                        break;
                    default:
                        echo "Unknown action.";
                }
            }
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
                if (isset($choice))
                {
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
                                $response->id = null;
                                $response->isNewRecord = true;
                                $response->save();
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
                }
                else
                {
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
                        'label' => 'Use response picker this survey: ',
                        'current' => $this->get('enabled', 'Survey', $event->get('survey'), 0)
                   ]
                ]
            ];
            $event->set("surveysettings.{$this->id}", $settings);

        }
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
//            $result = [];
            foreach ($responses as $response)
            {
                $result[] = [
                    'data' => $this->api->getResponse($response->surveyId, $response->id),
                    'urls' => [
                        'delete' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'delete', 'surveyId' => $response->surveyId, 'responseId' => $response->id, 'token' => $response->token]),
                        'read' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'read', 'surveyId' => $response->surveyId, 'responseId' => $response->id, 'token' => $response->token]),
                        
                        'update' => $this->api->createUrl('survey/index', array_merge($params, ['ResponsePicker' => $response->id])),
                        
                    ],
                ];
            }
            $result[] = [
                'id' => 'new',
                'url' => $this->api->createUrl('survey/index', $params)
            ];
//            $this->renderJson($result);
            $this->renderHtml($result);
        }

        protected function renderJson($result) {
            header('Content-Type: application/json');
            ob_end_clean();
            echo json_encode($result, JSON_PRETTY_PRINT);
            die();
        }
        
        protected function renderHtml($result) {
            $new = array_pop($result);
            $columns = [];
            foreach($result as $resultDetails) {
                foreach($resultDetails['data'] as $key => $value) {
                    $columns[$key] = true;
                }
            }
            $gridColumns['actions'] = [
                'class' => 'CButtonColumn',
                'template' => '{view}{update}{delete}',
                'buttons' => [
                    'view' => [
                        'url' => function($data) {
                            return $data['urls']['read'];
                        }
                    ],
                    'update' => [
                        'url' => function($data) {
                            return $data['urls']['update'];
                        }
                    ],
                    'delete' => [
                        'url' => function($data) {
                            return $data['urls']['delete'];
                        }
                    ]
                ]
            ];
            $gridColumns['id'] = [
                'name' => 'data.id',
                'header' => "Response id"
            ];
            $gridColumns['submitdate'] = [
                'name' => 'data.submitdate',
                'header' => "Submit Date"
            ];
            foreach ($columns as $column => $dummy) {
                if (substr($column, 0, 4) == 'DISP') {
                    $gridColumns[$column] = [
                        'name' => "data.$column",
                        'header' => ucfirst($column)

                    ];
                }
            }
            echo '<html><title></title><body>';
            Yii::app()->controller->widget('zii.widgets.grid.CGridView', [
                'dataProvider' => new CArrayDataProvider($result),
                'columns' => $gridColumns
                
            ]);
            echo CHtml::link("Create new response", $new['url']);
            die('</body></html>');
            
        }
        public function newSurveySettings()
        {
            foreach ($this->event->get('settings') as $name => $value)
            {
                if ($name != 'count')
                {
                    $this->set($name, $value, 'Survey', $this->event->get('survey'));
                }
            }
        }
    }
?>