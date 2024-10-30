<?php

class chimpxpressMCAPI {

    public $apiVersion = '3.0';

    protected $ch;

    private $timeout = 30;
    private $dc;
    private $apiUrl;
    private $settings;
    private $options;
    private $secure = false;

    private $optionsName = 'chimpxpress';
    private $optionsGroup = 'chimpxpress-options';

    private $notices;
    private $errors;
    private $debug = false;


    public function __construct() {
        $this->getSettings();

        /*$this->accessToken = $accessToken;
        $this->dc = $dc;
        $this->apiKey = $apiKey;
        $this->options = $options;*/

        if (in_array($_SERVER['SERVER_NAME'] ?? '', ['joomlamailer.loc', 'joomla.loc', 'joomla.local'])) {
            $this->debug = true;
        }
    }

    /*public function __destruct() {
        $this->closeConnection();
    }*/

    private function getSettings() {
        if (empty($this->settings)) {
            $this->settings = get_option($this->optionsName);
        }
        if (!is_array($this->settings)) {
            $this->settings = [];
        }
        $defaults = [
            'username'        => '',
            'password'        => '',
            'accessToken'     => '',
            'apiKey'          => '',
            'debugging'       => 'off',
            'debugging_email' => '',
            'version'         => $this->apiVersion
        ];
        $this->settings = wp_parse_args($this->settings, $defaults);
    }

    public function getApiUrl() {
        if (defined('OPENSSL_VERSION_NUMBER')) {
            $this->secure = true;
            $protocol = 'https';
        } else {
            $this->secure = false;
            $protocol = 'http';
        }

        $dc = $this->getDc();

        return "$protocol://$dc.api.mailchimp.com/$this->apiVersion/";
    }

    public function setTimeout($seconds) {
        if (is_int($seconds)) {
            $this->timeout = $seconds;
        }
    }

    public function getTimeout() {
        return $this->timeout;
    }

    public function getDc() {
        if (!empty($this->settings['apiKey']) && strstr($this->settings['apiKey'] ?? '', '-')) {
            [, $this->dc] = explode('-', $this->settings['apiKey']);
        }
        if (empty($this->dc)) {
            $this->dc = 'us1';
        }

        return $this->dc;
    }

    private function callServer($endpoint, $params = [], $method = 'GET') {
        // only proceed if we have access token or api key
        if (empty($this->settings['accessToken']) && empty($this->settings['apiKey'])) {
            return false;
        }

        $body = '';
        switch ($method) {
            case 'POST':
                if (count($params)) {
                    $body = wp_json_encode($params);
                }
                break;
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
                if (count($params)) {
                    $body = wp_json_encode($params);
                }
                break;
            case 'GET':
            default:
                $method = 'GET';
                $params = $this->httpBuildQuery($params);
                $endpoint .= ($params ? '?' . $params : '');
                break;
        }

        // request parameters
        $args = [
            'method'     => $method,
            'user-agent' => 'chimpXpress/' . $this->apiVersion,
            'timeout'    => $this->getTimeout(),
            'headers'    => [
                'Content-Type' => 'application/json; charset=utf-8'
            ],
            'body'       => $body,
            //'sslverify'  => !$this->debug
        ];

        // set authorization header
        if (!empty($this->settings['accessToken'])) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->settings['accessToken'];
        } else {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode('user:' . $this->settings['apiKey']);
        }

        $response = wp_remote_request($this->getApiUrl() . $endpoint, $args);

        /*$start = microtime(true);
        $this->log('[' . date('Y-m-d H:i:s') . '] ' . $method . ' ' . $this->getApiUrl() . $endpoint . ($params ? ': ' . wp_json_encode($params) : ''));
        if ($this->debug) {
            $curlBuffer = fopen('php://memory', 'w+');
            curl_setopt($this->ch, CURLOPT_STDERR, $curlBuffer);
        }*/

        /*$info = curl_getinfo($this->ch);
        $time = microtime(true) - $start;
        if ($this->debug) {
            rewind($curlBuffer);
            $this->log(stream_get_contents($curlBuffer));
            fclose($curlBuffer);
        }
        $this->log("\n.... Completed in " . number_format($time * 1000, 2) . 'ms');
        $this->log("\n.... Response: {$response}");*/

        /*if (curl_error($this->ch)) {
            $errorMsg = "API call to {$endpoint} failed: " . curl_error($this->ch);
            $this->log('[' . date('Y-m-d H:i:s') . '] ' . $errorMsg);
            $this->_addError(['error' => $errorMsg, 'code' => curl_errno($this->ch)]);
            return false;
        }*/

        $data = $this->parseResponse($response);

        return $data;
    }

    private function httpBuildQuery($params, $key = null) {
        $ret = [];

        foreach ((array)$params as $name => $val) {
            $name = urlencode($name);
            if ($key !== null) {
                $name = $key . '[' . $name . ']';
            }

            if (is_array($val) || is_object($val)) {
                $ret[] = $this->httpBuildQuery($val, $name);
            } else if ($val !== null) {
                $ret[] = $name . '=' . urlencode($val);
            }
        }

        return implode('&', $ret);
    }

    private function parseResponse($response) {
        if ($response instanceof WP_Error) {
            $this->_addError(['error' => $response->get_error_message(), 'code' => (int)$response->get_error_code()]);
            return false;
        }

        // decode response body
        $code = (int)wp_remote_retrieve_response_code($response);
        $message = wp_remote_retrieve_response_message($response);
        $body = wp_remote_retrieve_body($response);

        // set body to "true" in case Mailchimp returned No Content
        if ($code < 300 && empty($body)) {
            $body = 'true';
        }

        $data = json_decode($body, true);

        if ($code >= 400) {
            // check for akamai errors
            // {"type":"akamai_error_message","title":"akamai_503","status":503,"ref_no":"Reference Number: 00.950e16c3.1498559813.1450dbe2"}
            if (is_object($data) && isset($data->type) && $data->type === 'akamai_error_message') {
                $this->_addError(['error' => $message, 'code' => $code]);
                return false;
            }

            if ($code === 404) {
                $this->_addError(['error' => $message, 'code' => $code]);
                return false;
            }

            // mailchimp returned an error..
            $errorMsg = $data['title'] . ($data['detail'] ? ' - ' . $data['detail'] : '');
            $this->_addError(['error' => $errorMsg, 'code' => $data['status']]);
            return false;
        }

        // throw exception if unable to decode response
        if ($data === null) {
            $this->_addError(['error' => $message, 'code' => $code]);
            return false;
        }

        // remove _link elements from response
        $this->recursiveUnset($data, '_links');

        return $data;
    }

    /*private function log($msg) {
        if ($this->debug && trim($msg)) {
            $uploadDir = chimpxpress::getUploadDir();

            // using file_put_contents directly instead of WP_Filesystem because FILE_APPEND mode is not implemented
            file_put_contents($uploadDir['absPath'] . DS . 'mc_api.log', trim($msg) . "\n", FILE_APPEND);
        }
    }*/


    /**
     * API ROOT
     */
    public function getAccountDetails() {
        return $this->callServer('');
    }

    public function ping() {
        $ping = $this->callServer('ping');

        if (isset($ping['health_status'])) {
            $_SESSION['MCping'] = sanitize_text_field($ping);
        } else {
            $_SESSION['MCping'] = null;
        }

        return $ping['health_status'] ?? false;
    }

    /**
     * CAMPAIGNS
     */
    public function campaigns($params = [], $method = 'GET') {
        $endpoint = 'campaigns';

        $cid = preg_replace('/[^a-z0-9]/', '', $params['campaign_id'] ?? '');
        if ($cid) {
            $endpoint .= "/$cid";
        }

        return $this->callServer($endpoint, $params, $method);
    }

    public function campaignsContent($campaignId, $params = [], $method = 'GET') {
        return $this->callServer("campaigns/{$campaignId}/content", $params, $method);
    }

    public function campaignsActions($campaignId, $action, $params = []) {
        $endpoint = 'campaigns/' . $campaignId . '/actions/' . $action;

        return $this->callServer($endpoint, $params, 'POST');
    }

    /**
     * Campaign-Folders
     *
     * @param string $method
     * @param string $folderId
     * @param string $name
     * @return json API response
     * @throws Exception
     */
    public function campaignFolders($method = 'GET', $folderId = null, $name = '') {
        $params = [];

        $endpoint = 'campaign-folders';
        if ($folderId) {
            $endpoint .= '/' . $folderId;
        }

        if (in_array($method, ['POST', 'PATCH'])) {
            $name = trim($name);
            if (empty($name)) {
                throw new Exception('Folder name can not be empty!');
            }

            $params['name'] = $name;
        } else if ($method == 'DELETE' && !$folderId) {
            throw new Exception('Folder Id is required!');
        }

        return $this->callServer($endpoint, $params, $method);
    }

    /**
     * LISTS
     */
    public function lists($params = []) {
        return $this->callServer('lists', $params);
    }

    public function listMergeFields($listId, $count = 200) {
        return $this->callServer('lists/' . $listId . '/merge-fields', ['count' => $count]);
    }

    public function listMergeField($listId, $params, $method = 'GET') {
        $endpoint = 'lists/' . $listId . '/merge-fields/';
        if (isset($params['merge_id'])) {
            $endpoint .= $params['merge_id'];
        }

        return $this->callServer($endpoint, $params, $method);
    }

    public function listInterestCategories($listId, $categoryId = '', $params = [], $method = 'GET') {
        $endpoint = 'lists/' . $listId . '/interest-categories/';
        if ($categoryId) {
            $endpoint .= $categoryId;
            if ($method != 'DELETE') {
                $endpoint .= '/interests';
            }
        }

        return $this->callServer($endpoint, $params, $method);
    }

    public function listSegments($listId, $segmentId = false, $method = 'GET', $params = []) {
        $endpoint = 'lists/' . $listId . '/segments/';
        if ($segmentId) {
            $endpoint .= $segmentId;
        }

        return $this->callServer($endpoint, $params, $method);
    }

    public function listMembers($listId, $status = 'subscribed', $count = 100, $offset = 0, $since = '') {
        $params = [
            'status'              => $status,
            'count'               => $count,
            'offset'              => $offset,
            'since_timestamp_opt' => $since
        ];

        return $this->callServer('lists/' . $listId . '/members', $params);
    }

    public function listMember($listId, $email) {
        $endpoint = 'lists/' . $listId . '/members/' . md5(\Joomla\String\StringHelper::strtolower($email));

        return $this->callServer($endpoint);
    }

    public function listMemberSubscribe($listId, $params) {
        if (!empty($params['email_address_old'])) {
            $email = $params['email_address_old'];
            unset($params['email_address_old']);
            $method = 'PATCH';
        } else {
            $method = 'PUT';
            $email = $params['email_address'];
        }
        $endpoint = 'lists/' . $listId . '/members/' . md5(\Joomla\String\StringHelper::strtolower($email));

        return $this->callServer($endpoint, $params, $method);
    }

    public function listMemberUnsubscribe($listId, $email) {
        $endpoint = 'lists/' . $listId . '/members/' . md5(\Joomla\String\StringHelper::strtolower($email));
        $params = [
            'status' => 'unsubscribed'
        ];

        return $this->callServer($endpoint, $params, 'PATCH');
    }

    public function listMemberDelete($listId, $email) {
        $endpoint = 'lists/' . $listId . '/members/' . md5(\Joomla\String\StringHelper::strtolower($email))
            . '/actions/delete-permanent';

        return $this->callServer($endpoint, [], 'POST');
    }


    /**
     * REPORTS
     */
    public function reports($campaignId) {
        return $this->callServer('reports/' . $campaignId);
    }

    public function reportsEmailActivity($campaignId, $email) {
        $endpoint = 'reports/' . $campaignId . '/email-activity/' . md5(\Joomla\String\StringHelper::strtolower($email));

        return $this->callServer($endpoint);
    }

    public function reportsAbuseReports($campaignId, $count = 25, $offset = 0) {
        $endpoint = 'reports/' . $campaignId . '/abuse-reports';
        $params = [
            'count'  => $count,
            'offset' => $offset
        ];

        return $this->callServer($endpoint, $params);
    }

    public function reportsSentTo($campaignId, $count = 25, $offset = 0) {
        $endpoint = 'reports/' . $campaignId . '/sent-to';
        $params = [
            'count'  => $count,
            'offset' => $offset
        ];

        return $this->callServer($endpoint, $params);
    }

    public function reportsClickDetails($campaignId, $linkId = null, $count = 25, $offset = 0, $getMembers = false) {
        $endpoint = 'reports/' . $campaignId . '/click-details';
        if ($linkId) {
            $endpoint .= '/' . $linkId;
            if ($getMembers) {
                $endpoint .= '/members';
                $params = [
                    'count'  => $count,
                    'offset' => $offset
                ];
            } else {
                $params = [];
            }
        } else {
            $params = [
                'count'  => $count,
                'offset' => $offset
            ];
        }

        return $this->callServer($endpoint, $params);
    }

    public function reportsUnsubscribes($campaignId, $count = 25, $offset = 0) {
        $endpoint = 'reports/' . $campaignId . '/unsubscribed';
        $params = [
            'count'  => $count,
            'offset' => $offset
        ];

        return $this->callServer($endpoint, $params);
    }

    public function reportsAdvice($campaignId) {
        $endpoint = 'reports/' . $campaignId . '/advice';

        return $this->callServer($endpoint);
    }

    public function reportsEepUrl($campaignId) {
        $endpoint = 'reports/' . $campaignId . '/eepurl';
        $params = [
            'count'  => 1000,
            'offset' => 0
        ];

        return $this->callServer($endpoint, $params);
    }

    function reportsLocations($campaignId) {
        $endpoint = 'reports/' . $campaignId . '/locations';
        $params = [
            'count'  => 1000,
            'offset' => 0
        ];
        $res = $this->callServer($endpoint, $params);

        // group locations by country rather than region
        if ($res['total_items'] > 0) {
            $data = [];
            foreach ($res['locations'] as $location) {
                $location['country_code'] = ($location['country_code'] == 'UK') ? 'GB' : $location['country_code'];
                if (!isset($data[$location['country_code']])) {
                    $data[$location['country_code']] = 0;
                }
                $data[$location['country_code']] += $location['opens'];
            }

            asort($data);

            $res['locations'] = array_reverse($data);
        }

        return $res;
    }

    /**
     * Templates
     */
    public function templates($type = 'user') {
        $endpoint = 'templates';
        $params = [
            'type' => $type,
        ];

        return $this->callServer($endpoint, $params);
    }

    public function templateInfo($tid) {
        $endpoint = 'templates/' . (int)$tid;

        return $this->callServer($endpoint);
    }

    public function templateDefaultContent($tid) {
        $endpoint = 'templates/' . (int)$tid . '/default-content';

        return $this->callServer($endpoint);
    }

    /**
     * BATCHES
     */
    public function batches($method = 'GET', $batchId = false, $operations = []) {
        $endpoint = 'batches';
        if ($batchId) {
            $endpoint .= '/' . $batchId;
        }

        return $this->callServer($endpoint, $operations, $method);
    }


    /**
     * Remove all array elements with a given key recursively
     * @param $array
     * @param $unwanted_key
     */
    private function recursiveUnset(&$array, $unwanted_key) {
        if (!is_array($array)) {
            return;
        }
        unset($array[$unwanted_key]);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveUnset($value, $unwanted_key);
            }
        }
    }



    /*



    public function __construct($secure = false) {
        $this->getSettings();

        if (defined('OPENSSL_VERSION_NUMBER')) {
            $this->secure = true;
            $protocol = 'https';
        } else {
            $this->secure = false;
            $protocol = 'http';
        }

        // Get the datacenter from the API key
        $datacenter = substr(strrchr($this->settings['apiKey'], '-'), 1);
        if (empty($datacenter)) {
            $datacenter = "us1";
        }
        // Put the datacenter and version into the url
        $this->apiUrl = parse_url("{$protocol}://{$datacenter}.api.mailchimp.com/{$this->settings['version']}/");

        $this->api_key = $this->settings['apiKey'];

        $this->secure = $secure;
    }

    public function getSetting($settingName, $default = false) {
        if (empty($this->settings)) {
            $this->getSettings();
        }
        if (isset($this->settings[$settingName])) {
            return $this->settings[$settingName];
        } else {
            return $default;
        }
    }



    function campaignUnschedule($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignUnschedule", $params);
    }

    function campaignSchedule($cid, $schedule_time, $schedule_time_b = null) {
        $params = [];
        $params["cid"] = $cid;
        $params["schedule_time"] = $schedule_time;
        $params["schedule_time_b"] = $schedule_time_b;
        return $this->callServer("campaignSchedule", $params);
    }

    function campaignResume($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignResume", $params);
    }

    function campaignPause($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignPause", $params);
    }

    function campaignSendNow($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignSendNow", $params);
    }

    function campaignSendTest($cid, $test_emails = [], $send_type = null) {
        $params = [];
        $params["cid"] = $cid;
        $params["test_emails"] = $test_emails;
        $params["send_type"] = $send_type;
        return $this->callServer("campaignSendTest", $params);
    }

    function campaignSegmentTest($list_id, $options) {
        $params = [];
        $params["list_id"] = $list_id;
        $params["options"] = $options;
        return $this->callServer("campaignSegmentTest", $params);
    }

    function campaignCreate($type, $options, $content, $segment_opts = null, $type_opts = null) {
        $params = [];
        $params["type"] = $type;
        $params["options"] = $options;
        $params["content"] = $content;
        $params["segment_opts"] = $segment_opts;
        $params["type_opts"] = $type_opts;
        return $this->callServer("campaignCreate", $params);
    }

    function campaignUpdate($cid, $name, $value) {
        $params = [];
        $params["cid"] = $cid;
        $params["name"] = $name;
        $params["value"] = $value;
        return $this->callServer("campaignUpdate", $params);
    }

    function campaignReplicate($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignReplicate", $params);
    }

    function campaignDelete($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignDelete", $params);
    }

    function campaigns($filters = [], $start = 0, $limit = 25) {
        $params = [];
        $params["filters"] = $filters;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaigns", $params);
    }

    function campaignStats($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignStats", $params);
    }

    function campaignClickStats($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignClickStats", $params);
    }

    function campaignEmailDomainPerformance($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignEmailDomainPerformance", $params);
    }

    function campaignMembers($cid, $status = null, $start = 0, $limit = 1000) {
        $params = [];
        $params["cid"] = $cid;
        $params["status"] = $status;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaignMembers", $params);
    }

    function campaignHardBounces($cid, $start = 0, $limit = 1000) {
        $params = [];
        $params["cid"] = $cid;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaignHardBounces", $params);
    }

    function campaignSoftBounces($cid, $start = 0, $limit = 1000) {
        $params = [];
        $params["cid"] = $cid;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaignSoftBounces", $params);
    }

    function campaignUnsubscribes($cid, $start = 0, $limit = 1000) {
        $params = [];
        $params["cid"] = $cid;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaignUnsubscribes", $params);
    }

    function campaignAbuseReports($cid, $since = null, $start = 0, $limit = 500) {
        $params = [];
        $params["cid"] = $cid;
        $params["since"] = $since;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaignAbuseReports", $params);
    }

    function campaignAdvice($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignAdvice", $params);
    }

    function campaignAnalytics($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignAnalytics", $params);
    }

    function campaignGeoOpens($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignGeoOpens", $params);
    }

    function campaignGeoOpensForCountry($cid, $code) {
        $params = [];
        $params["cid"] = $cid;
        $params["code"] = $code;
        return $this->callServer("campaignGeoOpensForCountry", $params);
    }

    function campaignEepUrlStats($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignEepUrlStats", $params);
    }

    function campaignBounceMessage($cid, $email) {
        $params = [];
        $params["cid"] = $cid;
        $params["email"] = $email;
        return $this->callServer("campaignBounceMessage", $params);
    }

    function campaignBounceMessages($cid, $start = 0, $limit = 25, $since = null) {
        $params = [];
        $params["cid"] = $cid;
        $params["start"] = $start;
        $params["limit"] = $limit;
        $params["since"] = $since;
        return $this->callServer("campaignBounceMessages", $params);
    }

    function campaignEcommOrders($cid, $start = 0, $limit = 100, $since = null) {
        $params = [];
        $params["cid"] = $cid;
        $params["start"] = $start;
        $params["limit"] = $limit;
        $params["since"] = $since;
        return $this->callServer("campaignEcommOrders", $params);
    }

    function campaignShareReport(
        $cid, $opts = [
    ]
    ) {
        $params = [];
        $params["cid"] = $cid;
        $params["opts"] = $opts;
        return $this->callServer("campaignShareReport", $params);
    }

    function campaignContent($cid, $for_archive = true) {
        $params = [];
        $params["cid"] = $cid;
        $params["for_archive"] = $for_archive;
        return $this->callServer("campaignContent", $params);
    }

    function campaignTemplateContent($cid) {
        $params = [];
        $params["cid"] = $cid;
        return $this->callServer("campaignTemplateContent", $params);
    }

    function campaignOpenedAIM($cid, $start = 0, $limit = 1000) {
        $params = [];
        $params["cid"] = $cid;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaignOpenedAIM", $params);
    }

    function campaignNotOpenedAIM($cid, $start = 0, $limit = 1000) {
        $params = [];
        $params["cid"] = $cid;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaignNotOpenedAIM", $params);
    }

    function campaignClickDetailAIM($cid, $url, $start = 0, $limit = 1000) {
        $params = [];
        $params["cid"] = $cid;
        $params["url"] = $url;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaignClickDetailAIM", $params);
    }

    function campaignEmailStatsAIM($cid, $email_address) {
        $params = [];
        $params["cid"] = $cid;
        $params["email_address"] = $email_address;
        return $this->callServer("campaignEmailStatsAIM", $params);
    }

    function campaignEmailStatsAIMAll($cid, $start = 0, $limit = 100) {
        $params = [];
        $params["cid"] = $cid;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("campaignEmailStatsAIMAll", $params);
    }

    function campaignEcommOrderAdd($order) {
        $params = [];
        $params["order"] = $order;
        return $this->callServer("campaignEcommOrderAdd", $params);
    }

    function lists($filters = [], $start = 0, $limit = 25) {
        $params = [];
        $params["filters"] = $filters;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("lists", $params);
    }

    function listMergeVars($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("listMergeVars", $params);
    }

    function listMergeVarAdd($id, $tag, $name, $options = []) {
        $params = [];
        $params["id"] = $id;
        $params["tag"] = $tag;
        $params["name"] = $name;
        $params["options"] = $options;
        return $this->callServer("listMergeVarAdd", $params);
    }

    function listMergeVarUpdate($id, $tag, $options) {
        $params = [];
        $params["id"] = $id;
        $params["tag"] = $tag;
        $params["options"] = $options;
        return $this->callServer("listMergeVarUpdate", $params);
    }

    function listMergeVarDel($id, $tag) {
        $params = [];
        $params["id"] = $id;
        $params["tag"] = $tag;
        return $this->callServer("listMergeVarDel", $params);
    }

    function listInterestGroupings($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("listInterestGroupings", $params);
    }

    function listInterestGroupAdd($id, $group_name, $grouping_id = null) {
        $params = [];
        $params["id"] = $id;
        $params["group_name"] = $group_name;
        $params["grouping_id"] = $grouping_id;
        return $this->callServer("listInterestGroupAdd", $params);
    }

    function listInterestGroupDel($id, $group_name, $grouping_id = null) {
        $params = [];
        $params["id"] = $id;
        $params["group_name"] = $group_name;
        $params["grouping_id"] = $grouping_id;
        return $this->callServer("listInterestGroupDel", $params);
    }

    function listInterestGroupUpdate($id, $old_name, $new_name, $grouping_id = null) {
        $params = [];
        $params["id"] = $id;
        $params["old_name"] = $old_name;
        $params["new_name"] = $new_name;
        $params["grouping_id"] = $grouping_id;
        return $this->callServer("listInterestGroupUpdate", $params);
    }

    function listInterestGroupingAdd($id, $name, $type, $groups) {
        $params = [];
        $params["id"] = $id;
        $params["name"] = $name;
        $params["type"] = $type;
        $params["groups"] = $groups;
        return $this->callServer("listInterestGroupingAdd", $params);
    }

    function listInterestGroupingUpdate($grouping_id, $name, $value) {
        $params = [];
        $params["grouping_id"] = $grouping_id;
        $params["name"] = $name;
        $params["value"] = $value;
        return $this->callServer("listInterestGroupingUpdate", $params);
    }

    function listInterestGroupingDel($grouping_id) {
        $params = [];
        $params["grouping_id"] = $grouping_id;
        return $this->callServer("listInterestGroupingDel", $params);
    }

    function listWebhooks($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("listWebhooks", $params);
    }

    function listWebhookAdd($id, $url, $actions = [], $sources = []) {
        $params = [];
        $params["id"] = $id;
        $params["url"] = $url;
        $params["actions"] = $actions;
        $params["sources"] = $sources;
        return $this->callServer("listWebhookAdd", $params);
    }

    function listWebhookDel($id, $url) {
        $params = [];
        $params["id"] = $id;
        $params["url"] = $url;
        return $this->callServer("listWebhookDel", $params);
    }

    function listStaticSegments($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("listStaticSegments", $params);
    }

    function listStaticSegmentAdd($id, $name) {
        $params = [];
        $params["id"] = $id;
        $params["name"] = $name;
        return $this->callServer("listStaticSegmentAdd", $params);
    }

    function listStaticSegmentReset($id, $seg_id) {
        $params = [];
        $params["id"] = $id;
        $params["seg_id"] = $seg_id;
        return $this->callServer("listStaticSegmentReset", $params);
    }

    function listStaticSegmentDel($id, $seg_id) {
        $params = [];
        $params["id"] = $id;
        $params["seg_id"] = $seg_id;
        return $this->callServer("listStaticSegmentDel", $params);
    }

    function listStaticSegmentMembersAdd($id, $seg_id, $batch) {
        $params = [];
        $params["id"] = $id;
        $params["seg_id"] = $seg_id;
        $params["batch"] = $batch;
        return $this->callServer("listStaticSegmentMembersAdd", $params);
    }

    function listStaticSegmentMembersDel($id, $seg_id, $batch) {
        $params = [];
        $params["id"] = $id;
        $params["seg_id"] = $seg_id;
        $params["batch"] = $batch;
        return $this->callServer("listStaticSegmentMembersDel", $params);
    }

    function listSubscribe($id, $email_address, $merge_vars = [], $email_type = 'html', $double_optin = true, $update_existing = false, $replace_interests = true, $send_welcome = false) {
        $params = [];
        $params["id"] = $id;
        $params["email_address"] = $email_address;
        $params["merge_vars"] = $merge_vars;
        $params["email_type"] = $email_type;
        $params["double_optin"] = $double_optin;
        $params["update_existing"] = $update_existing;
        $params["replace_interests"] = $replace_interests;
        $params["send_welcome"] = $send_welcome;
        return $this->callServer("listSubscribe", $params);
    }

    function listUnsubscribe($id, $email_address, $delete_member = false, $send_goodbye = true, $send_notify = true) {
        $params = [];
        $params["id"] = $id;
        $params["email_address"] = $email_address;
        $params["delete_member"] = $delete_member;
        $params["send_goodbye"] = $send_goodbye;
        $params["send_notify"] = $send_notify;
        return $this->callServer("listUnsubscribe", $params);
    }

    function listUpdateMember($id, $email_address, $merge_vars, $email_type = '', $replace_interests = true) {
        $params = [];
        $params["id"] = $id;
        $params["email_address"] = $email_address;
        $params["merge_vars"] = $merge_vars;
        $params["email_type"] = $email_type;
        $params["replace_interests"] = $replace_interests;
        return $this->callServer("listUpdateMember", $params);
    }

    function listBatchSubscribe($id, $batch, $double_optin = true, $update_existing = false, $replace_interests = true) {
        $params = [];
        $params["id"] = $id;
        $params["batch"] = $batch;
        $params["double_optin"] = $double_optin;
        $params["update_existing"] = $update_existing;
        $params["replace_interests"] = $replace_interests;
        return $this->callServer("listBatchSubscribe", $params);
    }

    function listBatchUnsubscribe($id, $emails, $delete_member = false, $send_goodbye = true, $send_notify = false) {
        $params = [];
        $params["id"] = $id;
        $params["emails"] = $emails;
        $params["delete_member"] = $delete_member;
        $params["send_goodbye"] = $send_goodbye;
        $params["send_notify"] = $send_notify;
        return $this->callServer("listBatchUnsubscribe", $params);
    }

    function listMembers($id, $status = 'subscribed', $since = null, $start = 0, $limit = 100) {
        $params = [];
        $params["id"] = $id;
        $params["status"] = $status;
        $params["since"] = $since;
        $params["start"] = $start;
        $params["limit"] = $limit;
        return $this->callServer("listMembers", $params);
    }

    function listMemberInfo($id, $email_address) {
        $params = [];
        $params["id"] = $id;
        $params["email_address"] = $email_address;
        return $this->callServer("listMemberInfo", $params);
    }

    function listMemberActivity($id, $email_address) {
        $params = [];
        $params["id"] = $id;
        $params["email_address"] = $email_address;
        return $this->callServer("listMemberActivity", $params);
    }

    function listAbuseReports($id, $start = 0, $limit = 500, $since = null) {
        $params = [];
        $params["id"] = $id;
        $params["start"] = $start;
        $params["limit"] = $limit;
        $params["since"] = $since;
        return $this->callServer("listAbuseReports", $params);
    }

    function listGrowthHistory($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("listGrowthHistory", $params);
    }

    function listActivity($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("listActivity", $params);
    }

    function listLocations($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("listLocations", $params);
    }

    function listClients($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("listClients", $params);
    }

    function templates($types = ['user'], $category = null, $inactives = []) {
        $params = [];
        $params["types"] = $types;
        $params["category"] = $category;
        $params["inactives"] = $inactives;
        return $this->callServer("templates", $params);
    }

    function templateInfo($tid, $type = 'user') {
        $params = [];
        $params["tid"] = $tid;
        $params["type"] = $type;
        return $this->callServer("templateInfo", $params);
    }

    function templateAdd($name, $html) {
        $params = [];
        $params["name"] = $name;
        $params["html"] = $html;
        return $this->callServer("templateAdd", $params);
    }

    function templateUpdate($id, $values) {
        $params = [];
        $params["id"] = $id;
        $params["values"] = $values;
        return $this->callServer("templateUpdate", $params);
    }

    function templateDel($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("templateDel", $params);
    }

    function templateUndel($id) {
        $params = [];
        $params["id"] = $id;
        return $this->callServer("templateUndel", $params);
    }

    function getAccountDetails() {
        $params = [];
        return $this->callServer("getAccountDetails", $params);
    }

    function generateText($type, $content) {
        $params = [];
        $params["type"] = $type;
        $params["content"] = $content;
        return $this->callServer("generateText", $params);
    }

    function inlineCss($html, $strip_css = false) {
        $params = [];
        $params["html"] = $html;
        $params["strip_css"] = $strip_css;
        return $this->callServer("inlineCss", $params);
    }

    function folders($type = 'campaign') {
        $params = [];
        $params["type"] = $type;
        return $this->callServer("folders", $params);
    }

    function folderAdd($name, $type = 'campaign') {
        $params = [];
        $params["name"] = $name;
        $params["type"] = $type;
        return $this->callServer("folderAdd", $params);
    }

    function folderUpdate($fid, $name, $type = 'campaign') {
        $params = [];
        $params["fid"] = $fid;
        $params["name"] = $name;
        $params["type"] = $type;
        return $this->callServer("folderUpdate", $params);
    }

    function folderDel($fid, $type = 'campaign') {
        $params = [];
        $params["fid"] = $fid;
        $params["type"] = $type;
        return $this->callServer("folderDel", $params);
    }

    function ecommOrders($start = 0, $limit = 100, $since = null) {
        $params = [];
        $params["start"] = $start;
        $params["limit"] = $limit;
        $params["since"] = $since;
        return $this->callServer("ecommOrders", $params);
    }

    function ecommOrderAdd($order) {
        $params = [];
        $params["order"] = $order;
        return $this->callServer("ecommOrderAdd", $params);
    }

    function ecommOrderDel($store_id, $order_id) {
        $params = [];
        $params["store_id"] = $store_id;
        $params["order_id"] = $order_id;
        return $this->callServer("ecommOrderDel", $params);
    }

    function listsForEmail($email_address) {
        $params = [];
        $params["email_address"] = $email_address;
        return $this->callServer("listsForEmail", $params);
    }

    function campaignsForEmail($email_address) {
        $params = [];
        $params["email_address"] = $email_address;
        return $this->callServer("campaignsForEmail", $params);
    }

    function chimpChatter() {
        $params = [];
        return $this->callServer("chimpChatter", $params);
    }

    function apikeys($expired = false) {
        $params = [];
        $params["username"] = $this->settings['username'];
        $params["password"] = $this->settings['password'];
        $params["expired"] = $expired;
        return $this->callServer("apikeys", $params);
    }

    function apikeyAdd($username, $password) {
        $params = [];
        $params["username"] = $username;
        $params["password"] = $password;
        return $this->callServer("apikeyAdd", $params);
    }

    function apikeyExpire($username, $password) {
        $params = [];
        $params["username"] = $username;
        $params["password"] = $password;
        return $this->callServer("apikeyExpire", $params);
    }

    function ping() {
        if (!$this->api_key) {
            return false;
        }
        $params = [];
        return $this->callServer("ping", $params);
    }

    function callMethod() {
        $params = [];
        return $this->callServer("callMethod", $params);
    }

    function callServer($method, $params) {

        if ($this->settings['apiKey'] == '') {
            $this->_addError(["error" => "API Key can not be blank", "code" => "104"]);
            return false;
        }

        //$params['apiKey'] = $this->_settings['apiKey'];

        $this->errorMessage = "";
        $this->errorCode = "";
        $sep_changed = false;
        //sigh, apparently some distribs change this to &amp; by default
        if (ini_get("arg_separator.output") != "&") {
            $sep_changed = true;
            $orig_sep = ini_get("arg_separator.output");
            ini_set("arg_separator.output", "&");
        }
        $post_vars = http_build_query($params);
        if ($sep_changed) {
            ini_set("arg_separator.output", $orig_sep);
        }

        $payload = "POST " . $this->apiUrl["path"] . $method . " HTTP/1.0\r\n";
        $payload .= "Host: " . $this->apiUrl["host"] . "\r\n";
        $payload .= "User-Agent: chimpxpress/" . $this->version . "\r\n";
        $payload .= 'Authorization: Basic ' . base64_encode('user:' . $this->settings['apiKey']) . "\r\n";
        $payload .= "Content-type: application/x-www-form-urlencoded\r\n";
        $payload .= "Content-length: " . strlen($post_vars) . "\r\n";
        $payload .= "Connection: close \r\n\r\n";
        $payload .= $post_vars;
        var_dump($payload);

        ob_start();
        if ($this->secure) {
            $sock = fsockopen("ssl://" . $this->apiUrl["host"], 443, $errno, $errstr, 30);
        } else {
            $sock = fsockopen($this->apiUrl["host"], 80, $errno, $errstr, 30);
        }
        if (!$sock) {
            $this->errorMessage = "Could not connect (ERR $errno: $errstr)";
            $this->errorCode = "-99";
            ob_end_clean();
            return false;
        }

        $response = "";
        fwrite($sock, $payload);
        stream_set_timeout($sock, $this->timeout);
        $info = stream_get_meta_data($sock);
        while ((!feof($sock)) && (!$info["timed_out"])) {
            $response .= fread($sock, $this->chunkSize);
            $info = stream_get_meta_data($sock);
        }
        if ($info["timed_out"]) {
            //$this->errorMessage = "Could not read response (timed out)";
            //$this->errorCode = -98;
            $this->_addError(["error" => "Could not read response (timed out)", "code" => "-98"]);
        }
        fclose($sock);
        ob_end_clean();
        if ($info["timed_out"]) {
            return false;
        }

        [$throw, $response] = explode("\r\n\r\n", $response, 2);

        if (ini_get("magic_quotes_runtime")) {
            $response = stripslashes($response);
        }

        var_dump($response);
        die;

        $serial = unserialize($response);
        if ($response && $serial === false) {
            $response = ["error" => "Bad Response.  Got This: " . $response, "code" => "-99"];
        } else {
            $response = $serial;
        }
        if (is_array($response) && isset($response["error"])) {
            //$this->errorMessage = $response["error"];
            //$this->errorCode = $response["code"];
            $response["error"] = $response["error"];
            $this->_addError($response);
            return false;
        }

        return $response;
    }
*/

    // messages
    public function showMessages() {
        $this->showErrors();
        $this->showNotices();
    }

    public function showErrors() {
        $this->_getErrors();
        if (!empty($this->errors)) {
            $errorsDone = [];
            echo '<div class="error fade">';
            foreach ($this->errors as $e) {
                if (is_array($e) && !in_array($e['code'], $errorsDone)) {
                    echo "<p><strong>" . esc_html($e['error']) . "</strong> (" . esc_html__('error code', 'chimpxpress') . ": " . esc_html($e['code']) . ")</p>";
                    $errorsDone[] = $e['code'];
                }
            }
            echo '</div>';
        }
        $this->_emptyErrors();
    }

    public function showNotices() {
        $this->_getNotices();
        if (!empty($this->notices)) {
            echo '<div class="updated fade">';
            foreach ($this->notices as $n) {
                echo esc_html("<p><strong>$n</strong></p>");
            }
            echo '</div>';
        }
        $this->_emptyNotices();
    }

    // get and set errors and notices
    public function getErrors() {
        if (empty($this->errors)) {
            $this->_getErrors();
        }
        return $this->errors;
    }

    private function _getErrors() {
        $this->errors = get_option($this->optionsName . '-errors', []);
    }

    public function _addError($error) {
        if (empty($this->errors)) {
            $this->_getErrors();
        }
        $this->errors[] = $error;
        $this->_setErrors();
    }

    public function _emptyErrors() {
        $this->errors = [];
        $this->_setErrors();
    }

    private function _setErrors() {
        update_option($this->optionsName . '-errors', $this->errors);
    }

    // Retrieve a set of notices that have occured.
    // @return array Notices
    public function getNotices() {
        if (empty($this->notices)) {
            $this->_getNotices();
        }
        return $this->notices;
    }

    private function _getNotices() {
        $this->notices = get_option($this->optionsName . '-notices', []);
    }

    public function _addNotice($notice) {
        if (empty($this->notices)) {
            $this->_getNotices();
        }
        $this->notices[] = $notice;
        $this->_setNotices();
    }

    public function _emptyNotices() {
        $this->notices = [];
        $this->_setNotices();
    }

    private function _setNotices() {
        update_option($this->optionsName . '-notices', $this->notices);
    }
}

if (!function_exists('debug_wp_remote_post_and_get_request')) {
    function chimpx_debug_wp_remote_request($response, $context, $class, $request, $url) {
        // redact Authorization header
        if (isset($request['headers']['Authorization'])) {
            $request['headers']['Authorization'] = 'REDACTED';
        }
        $log = [
            'chimpXpress request' => [
                'endpoint' => $url,
                'request'  => $request,
                'response' => $response
            ]
        ];
        error_log(wp_json_encode($log));
    }

    add_action('http_api_debug', 'chimpx_debug_wp_remote_request', 10, 5);
}
