<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'JeedomObjects.class.php';
include_once 'MqttTestCase.php';

use Bluerhinos\phpMQTT;
use Facebook\WebDriver\WebDriverBy as By;
use MqttGen\MqttGen;


interface iBroker {
    public function setCmdInfo(string $bname, string $eqptName, string $topic, $val, string $cmdName=null, string $subtype='string');
    public function existsByName(string $bname, string $eqptName);
}


/**
 * Mirrors the jMQTT equipments that should be available in Jeedom
 */
class MqttEqpts implements iBroker {

    private const KEY_CMDS = 'cmds';
    
    public const KEY_NAME = 'name';
    public const KEY_ID = 'id';
    public const KEY_BRK_ID = 'brkId';
    public const KEY_DAEMON_STATE = 'daemon_state';
    public const KEY_LAST_COMMUNICATION = 'lastCommunication';

    public const CONF_AUTO_ADD_CMD = 'auto_add_cmd';
    public const CONF_QOS = 'Qos';
    public const CONF_MQTT_ADDRESS = 'mqttAddress';
    public const CONF_MQTT_PORT = 'mqttPort';
    public const CONF_MQTT_ID = 'mqttId';
    public const CONF_MQTT_USER = 'mqttUser';
    public const CONF_MQTT_PASS = 'mqttPass';
    public const CONF_MQTT_INC_TOPIC = 'mqttIncTopic';
    public const CONF_API = 'api';
    
    public const DAEMON_OK  = 'ok';
    public const DAEMON_POK = 'pok';
    public const DAEMON_NOK = 'nok';
    public const DAEMON_UNCHECKED = 'unchecked';
    public const DAEMON_ONLINE = 'online';
    public const DAEMON_OFFLINE = 'offline';
    
    // API to be used to interact with Jeedom
    public const API_JSON_RPC = 0;
    public const API_MQTT = 1;
    
    const TYP_EQPT = 'eqpt';
    const TYP_BRK  = 'broker';
    
    /**
     * jMQTT equipment array of brokers of equipment
     * @var array[][] jMQTT equipments array
     */
    private $eqpts;
    
    /**
     * Broker daemon states
     * @var string[]
     */
    private $daemonStates;
    
    /**
     * @var MqttTestCase $tc
     */
    private $tc;
    
    /**
     * @var MqttApiClient[] $apis
     */
    private $apis;
    
    
    /**
     * @var string $current_bname current broker name (see addFromMqttBroker)
     */
    private $current_bname;
    
    
    /**
     * Construct a new object from $_ENV['broker']. Activate and check API.
     * IMPORTANT: Brokers shall be created and online on plugin side before calling this constructor.
     * @param MqttTestCase $tc
     * @param bool $init_from_plugin if true, create equipments from the API
     * [*]
     */
    function __construct(MqttTestCase $tc, bool $init_from_plugin = false) {
        $this->eqpts = array();
        $this->apis = array();
        $this->tc = $tc;
        
        foreach ($_ENV['brokers'] as $bname => $broker) {
            $this->apis[$bname] = new MqttApiClient($bname, $broker['mosquitto_client_id'],
                $broker['mosquitto_host'], $broker['mosquitto_port'], $this);
        }
        
        $this->activateAndAssertAllAPI();
        
        JeedomObjects::init(reset($this->apis));
        
        // Create brokers
        foreach ($_ENV['brokers'] as $bname => $broker) {
            $this->eqpts[$bname] = array();
            $this->eqpts[$bname][] = $this->createBroker($bname, $broker['mosquitto_host'], $broker['mosquitto_port'],
                $broker['mosquitto_client_id']);
            $this->setIsEnable($bname, $bname, true);
            $this->setBrokerState($bname, self::DAEMON_OK);
            $this->createBrokerApiCmdInfo($bname);
            $this->setCmdOrders($bname, $bname);
        }
        
        // Create other equipments if $init_from_plugin is true
        if ($init_from_plugin) {
            $this->initFromMqttAPI(reset($this->apis));
            
            // If other brokers have been added, init their state
            foreach(array_keys($this->eqpts) as $bname) {
                if (! array_key_exists($bname, $_ENV['brokers']))
                    $this->setBrokerState($bname, self::DAEMON_UNCHECKED);
            }
        }
    }

    /**
     * Activate and check the API for the given broker
     * [*]
     */
    public function activateAndAssertAPI($bname) {
        $api = $this->apis[$bname];   
            
        // Send a ping to the API
        $resp = $api->sendRequest('ping');
        
        if (!isset($resp) || array_key_exists('error', $resp)) {
            
            // Activate the API in jMQTT if not activated and retry
            $this->tc->setMqttApi($bname, true);
            $this->tc->gotoPluginMngt();
            
            $resp = $api->sendRequest('ping');
            if (!isset($resp) || array_key_exists('error', $resp)) {
                // Activate also the JSON RPC API
                $this->tc->setJsonRpcApi(true);
                $resp = $api->sendRequest('ping');
            }
        }
        
        $this->tc->assertEquals("pong", $resp['result']);
    }
    
    /**
     * Activate and check the API for All broker
     * [*]
     */
    public function activateAndAssertAllAPI() {
        foreach (array_keys($this->apis) as $bname) {
            $this->activateAndAssertAPI($bname);
        }
    }
    
    /**
     * Compare equipment names (to order them as the plugin does in the plugin equipment page)
     * @param string $a
     * @param string $b
     * @return number
     * [*]
     */
    public static function eqptnamecmp(string $a, string $b) {
        //return strnatcasecmp(str_replace('_', '-', $a), str_replace('_', '-', $b));
        return strnatcasecmp($a, $b);
    }

    /**
     * Compare equipment (to order them as the plugn does in the plugin equipment page)
     * @param array $a
     * @param array $b
     * @return number
     * [*]
     */
    public static function eqptcmp(array $a, array $b) {
        if ($a['configuration']['type'] == 'broker')
            return -1;
        elseif ($b['configuration']['type'] == 'broker')
            return 1;
        else
            return self::eqptnamecmp($a[self::KEY_NAME], $b[self::KEY_NAME]);
    }
    
    /**
     * Add an eqpt to the given broker
     * 
     * Subscription topic is set to '$name/#', as the plugin does when adding an eqpt automatically, except if
     * $topic_auto is set to false.
     * Order the array in alphabetical order by the 2 following keys : belonging object name, eqpt name
     * @param string $broker broker name
     * @param string $name equipement name
     * @param bool $isEnable whether or not this equipment is enable 
     * @param string|null $obj_id object id the equipment belongs to 
     * @param bool $topic_auto wether or not topic is set to '$name/#'
     */
    public function add(string $bname, string $name, bool $isEnable=false, string $obj_id=null, bool $topic_auto=true) {
        $eqpt = $this->createEqpt($name, $obj_id, $topic_auto);
        if (key_exists($bname, $this->eqpts) && ! empty($this->eqpts[$bname]))
            $eqpt['configuration'][self::KEY_BRK_ID] = $this->eqpts[$bname][0]['configuration'][self::KEY_BRK_ID];
        $this->eqpts[$bname][] = $eqpt;
        
        $this->setIsEnable($bname, $name, $isEnable);
        
        // Sort the array according to the order of the eqLogic::byType API command
        usort($this->eqpts[$bname], array('MqttEqpts', 'eqptcmp'));
    }

    /**
     * Add an equipment to the given broker through the Jeedom interface and to this object.
     * Equipment is added to the given broker
     * Equipment souscription topic is set if given.
     *
     * @param string $broker broker name
     * @param string $ename new equipement name
     * @param bool $isEnable true by default
     * @param string $topic null by default
     */
    public function addFromInterface(string $bname, string $ename, bool $isEnable=true, string $topic=null) {
        $this->tc->addEqpt($bname, $ename);
        $this->add($bname, $ename, $isEnable, null, false);
        
        if (isset($topic)) {
            $this->setTopic_ui($bname, $ename, $topic);
        }
        
        // By default the created equipment is disabled. If enable is requested, 
        // enable the equipment in Jeedom through the UI and save
        if ($isEnable)
            $this->setIsEnable_ui($bname, $ename, $isEnable);
    }
    
    /**
     * Callback for the addFromMqttBroker function
     * @param string $topic
     * @param string $msg
     */
    public function processMqttMsg($topic, $msg){
        $topicArray = explode('/', $topic);
        if (! $this->existsByTopic($this->current_bname, $topicArray[0] . '/#')) {
            $this->add($this->current_bname, $topicArray[0], true);
        }
    }

    /**
     * Connect the MQTT broker, subscribe to '#', and add equipments corresponding to the received topics
     * To be used when enabling the jMQTT automatic inclusion mode to determine the expected equipment list
     * @param string $broker broker name
     * [*]
     */
    public function addFromMqttBroker(string $bname) {
        $mqtt = new phpMQTT($_ENV['brokers'][$bname]['mosquitto_host'], $_ENV['brokers'][$bname]['mosquitto_port'],
            "MqttEqpts_" . $bname);
        $this->current_bname = $bname;
        $mqtt->connect();
        $mqtt->subscribe(array('#' => array("qos" => 0, "function" => array($this, 'processMqttMsg'))), 0);        
        for ($i=0 ; $i<20 ; $i++) {
            $mqtt->proc();
        }
        $mqtt->close();
        $this->current_bname = null;
    }
    
    /**
     * Set the state of the given broker
     * Possible state values: self::DAEMON_OK, self::DAEMON_POK, self::DAEMON_NOK, self::DAEMON_UNCHECKED
     * @param string $bname
     * @param string $state
     * [*]
     */
    public function setBrokerState(string $bname, string $state) {
        $broker = $this->eqpts[$bname][0];
        $status_cmd_topic = substr($broker['logicalId'], 0, strlen($broker['logicalId'])-1) . 'status';
        
        $this->daemonStates[$bname] = $state;
        if ($state == self::DAEMON_OK) {
            $this->setBrokerCmdInfo($bname, $status_cmd_topic, self::DAEMON_ONLINE);
        }
        elseif ($state != self::DAEMON_UNCHECKED) {
            $this->setBrokerCmdInfo($bname, $status_cmd_topic, self::DAEMON_OFFLINE);
        }
    }
    
    /**
     * Return the state of the given broker
     * Possible state values: self::DAEMON_OK, self::DAEMON_POK, self::DAEMON_NOK, self::DAEMON_UNCHECKED
     * @param string $bname
     * @return string 
     */
    public function getBrokerState(string $bname) {
        return $this->daemonStates[$bname];
    }
    
    /**
     * Set the given parameters of the given named eqpt
     * @param string $bname broker name
     * @param string $name
     * @param array $param
     */
    public function setParameters(string $bname, string $name, array $param) {
        $eqpt = & $this->getEqptFromName($bname, $name);
        self::copyValues($param, $eqpt);
    }
    
    /**
     * Set the topic of the given named eqpt
     * @param string $bname broker name
     * @param string $ename equipment name
     * @param string $topic
     */
    private function setTopic(string $bname, string $ename, string $topic) {
        $eqpt = & $this->getEqptFromName($bname, $ename);
        $eqpt['logicalId'] = $topic;
    }
    
    /**
     * Set the include mode of the given broker.
     * Include mode state are verified before and after.
     * Wait for broker to be online again after the change.
     * @param string $bname
     * @param bool $is_include
     */
    public function setIncludeMode(string $bname, bool $is_include) {
        $this->tc->assertIncludeMode($bname, ! $is_include)->click();
        $this->tc->assertIncludeMode($bname, $is_include);
        $this->waitForBroker($bname);
    }
    
    /**
     * Change the name of the given old_name equipment to new_name through the UI
     * Equipment is not saved.
     * Start page req.: the equipment page (no matter which tab)
     * End page: same as start page
     * @param string $bname
     * @param string $old_name
     * @param string $new_name
     */
    public function setEqptName_ui(string $bname, string $old_name, string $new_name) {
        $eqpt = & $this->getEqptFromName($bname, $old_name);
        $eqpt[self::KEY_NAME] = $new_name;
        $this->tc->setEqptName($new_name);
    }
    
    /**
     * Set the given configuration parameter of the currently displayed equipment through the UI.
     * 
     * Start page req.: the equipment page (no matter which tab)
     * End page: same as start page
     * 
     * @param string $bname
     * @param string $ename
     * @param string $key parameter key (among MqttEqpts::CONF_*)
     * @param string|bool $value
     * @param bool $save whether or not equiment shall be saved
     */
    public function setConfiguration_ui(string $bname, string $ename, string $key, $value, bool $save=true) {
        if (is_bool($value))
            $value = $value ? "1" : "0";
        
        $this->setParameters($bname, $ename, array('configuration' => array($key => $value)));
        $this->tc->setConfiguration($key, $value, $save);
    }
    
    /**
     * Enable or disable the currently displayed equipment through the UI.
     * 
     * Start page req.: the equipment page (no matter which tab)
     * End page: same as start page
     * 
     * @param string $bname
     * @param string $ename
     * @param bool $isEnable
     * @param bool $save whether or not equiment shall be saved
     */
    public function setIsEnable_ui(string $bname, string $ename, bool $isEnable, bool $save=true) {
        $this->setIsEnable($bname, $ename, $isEnable);
        $this->tc->setIsEnable($isEnable, $save);
    }
    
    /**
     * Set the isEnable flag of the given named eqpt
     * @param string $bname broker name
     * @param string $ename equipment name
     * @param bool $isEnable
     */
    private function setIsEnable(string $bname, string $ename, bool $isEnable) {
        $this->setParameters($bname, $ename, array('isEnable' => $isEnable ? '1' : '0'));
    }
    
    /**
     * Set the topic of the currently displayed equipment through the UI.
     * Equipment is not saved.
     *
     * Start page req.: the equipment page (no matter which tab)
     * End page: same as start page
     *
     * @param string $bname
     * @param string $ename
     * @param string $topic
     */
    public function setTopic_ui(string $bname, string $ename, string $topic) {
        $this->setTopic($bname, $ename, $topic);
        $this->tc->setTopic($topic);
    }
    
    /**
     * Update or add an action cmd to the given named equipment associated to the given broker
     * @param string $bname
     * @param string $eqptName
     * @param string $topic
     * @param string $cmdName
     * @param string $subtype
     * @param string|null $val optional (null by default)
     */
    public function setCmdAction(string $bname, string $eqptName, string $topic, string $cmdName, string $subtype, $val=null) {
        $this->setCmd($bname, $eqptName, 'action', $subtype, $topic, $val, $cmdName);
    }

    /**
     * Update or add an info cmd to the given named equipment associated to the given broker
     * @param string $bname
     * @param string $eqptName
     * @param string $topic
     * @param string|null $val
     * @param string $cmdName
     * @param string $subtype optional (default=string)
     */
    public function setCmdInfo(string $bname, string $eqptName, string $topic, $val, string $cmdName=null, string $subtype='string') {
        $this->setCmd($bname, $eqptName, 'info', $subtype, $topic, $val, $cmdName);
    }
    
    /**
     * Update or add an info cmd to the given broker
     * @param string $bname
     * @param string $topic
     * @param string|null $val
     */
    public function setBrokerCmdInfo(string $bname, string $topic, $val) {
        $this->setCmdInfo($bname, $this->eqpts[$bname][0][self::KEY_NAME], $topic, $val);
    }
    
    /**
     * Create the broker api info command
     * @param string $bname broker name
     */
    private function createBrokerApiCmdInfo(string $bname) {
        $broker = $this->eqpts[$bname][0];
        $api_cmd_topic = substr($broker['logicalId'], 0, strlen($broker['logicalId'])-1) . 'api';
        $this->setBrokerCmdInfo($bname, $api_cmd_topic, "");
    }
    
    /**
     * Update or add a cmd to the given named equipment associated to the given broker
     * @param string $bname broker name
     * @param string $ename equipment name
     * @param string $type 'info' or 'action'
     * @param string $subtype
     * @param string $topic
     * @param string|null $val
     * @param string $cmdName (optional) command name (automatically defined as the plugin does if null)
     * @throw Exception if equipement does not exist
     */
    private function setCmd(string $bname, string $ename, string $type, string $subtype, string $topic, $val, string $cmdName=null) {
        
        $eqpt = & $this->getEqptFromName($bname, $ename);
        
        // Command name not provided: built it automatically
        if (!isset($cmdName)) {
            $cmdName = self::getAutomaticCmdName($eqpt['logicalId'], $topic);            
        }
        $cmdName = str_replace("/", ":", $cmdName);
        
        if (($cmd = & self::getCmd($eqpt, $cmdName)) == null) {
            $cmd = self::createCmd($cmdName, $type, $subtype, $topic, $val);
            if (! array_key_exists(self::KEY_CMDS, $eqpt)) {
                $eqpt[self::KEY_CMDS] = array();
            }
            $cmd['order'] = $type == 'action' ? strval(count($eqpt[self::KEY_CMDS])) : '0';
            $cmd['eqLogic_id'] = $eqpt[self::KEY_ID];
            $eqpt[self::KEY_CMDS][] = $cmd;
            
            // Sort the array according to the order of the cmd::byEqLogicId API command
            usort($eqpt[self::KEY_CMDS], function($a, $b) {
                $order_a = intval($a['order']);
                $order_b = intval($b['order']);
                if ($order_a == $order_b)
                    return strcasecmp($a[self::KEY_NAME], $b[self::KEY_NAME]);
                else
                    return $order_a - $order_b;
            });            
        }
            
        self::updateCmd($cmd, $cmdName, $topic, $val);
    }
    
    /**
     * Set the order configuration parameter of the commands of the defined equipment
     * @param string $bname broker name
     * @param string $ename equipment name
     */
    private function setCmdOrders(string $bname, string $ename) {
        $eqpt = & $this->getEqptFromName($bname, $ename);
        for($i=0 ; $i<count($eqpt[self::KEY_CMDS]) ; $i++) {
            $eqpt[self::KEY_CMDS][$i]['order'] = strval($i);
        }
    }

    /**
     * Update or add a cmd to the given named equipment from an mqtt message
     * @param string $bname
     * @see MqttEqpts::setCmd()
     * @param string $eqptName
     * @param array $msg cmd is defined by $msg[MqttGen::S_TOPIC] and $msg[MqttGen::S_PAYLOAD]
     * @param string $cmdName (optional) command name (automatically defined as the plugin does if null)
     * @throw Exception if equipement does not exist
     * [*]
     */
    public function setCmdFromMsg(string $bname, string $eqptName, array $msg, $cmdName=null) {
        $this->setCmdInfo($bname, $eqptName, $msg[MqttGen::S_TOPIC], $msg[MqttGen::S_PAYLOAD], $cmdName);
    }
    
    /**
     * Returns whether or not the given equipement exists
     * @param string $bname
     * @param string $eqptName
     * @return boolean
     * [*]
     */
    public function existsByName(string $bname, string $eqptName) {
        if (array_key_exists($bname, $this->eqpts)) {
            foreach($this->eqpts[$bname] as $eqpt) {
                if ($eqptName == $eqpt[self::KEY_NAME])
                    return true;
            }
        }
        return false;       
    }
    
    /**
     * Returns whether or not the given equipement exists
     * @param string $bname
     * @param string $topic
     * @return boolean
     */
    public function existsByTopic(string $bname, string $topic) {
        if (array_key_exists($bname, $this->eqpts)) {
            foreach($this->eqpts[$bname] as $eqpt) {
                if ($topic == $eqpt['logicalId'])
                    return true;
            }
        }
        return false;
    }
    
    /**
     * Returns whether or not the given equipement exists
     * @param string $bname
     * @param string $eqptName
     * @return boolean
     * [*]
     */
    public function existsById(string $bname, string $id) {
        if (array_key_exists($bname, $this->eqpts)) {
            foreach($this->eqpts[$bname] as $eqpt) {
                if ($id == $eqpt[self::KEY_ID])
                    return true;
            }
        }
        return false;
    }
    
    /**
     * Delete the given equipment from Jeedom interface and from this object
     * @param string $bname
     * @param string $name
     * @return boolean whether or not an equipment has been deleted
     * [*]
     */
    public function deleteFromInterface(string $bname, string $name) {
        foreach($this->eqpts[$bname] as $i => $eqpt) {
            if ($name == $eqpt[self::KEY_NAME]) {
                $this->tc->deleteEqpt($bname, $name);
                array_splice($this->eqpts[$bname], $i, 1);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Delete all equipments associated to the given broker (all brokers if the given broker is null)
     * The broker itself is not removed
     * @param string $_bname broker name
     */
    public function deleteAllEqpts(string $_bname = null) {
        if (isset($_bname)) {
            $brokers = array($_bname);
        }
        else {
            $brokers = array_keys($this->apis);
        }
        
        // Send the delete request
        foreach ($brokers as $bname) {
            $this->tc->assertEquals('ok', $this->sendMqttRequest($this->apis[$bname], 'jMQTT::removeAllEqpts'));
            array_splice($this->eqpts[$bname], 1);
        }
        
        // Wait for brokers to be online again
        foreach ($brokers as $bname) {
            $this->waitForBroker($bname);
        }
        
        $this->tc->refreshPage();
    }
    
    public function prettyPrint() {
        print(json_encode($this->eqpts, JSON_PRETTY_PRINT));
    }

    /**
     * @param string|MqttApiClient $_api
     * @param string $method
     * @param array $params
     * @param array $add_msg
     * @return array
     */
    public function sendMqttRequest($api, string $method, array $params=array(), array &$add_msg=null) {
        if (! is_object($api))
            $api = $this->apis[$api];
        $resp = $api->sendRequest($method, $params, $add_msg);
        $this->tc->assertNotNull($resp, 'API did not return any message');
        $this->tc->assertArrayNotHasKey('error', $resp, 'API returned an error on ' . $method . ' request');
        return $resp['result'];
    }
    
    /**
     * @param int $api_method API method: either MqttEqpts::API_JSON_RPC or MqttEqpts::API_MQTT
     * @param null|string|MqttApiClient $api
     * @param string $method
     * @param array $params
     * @param array $add_msg for MQTT API only (optional)
     * @return array|NULL|number|string
     */
    public function sendJeedomRequest(int $api_method, $api, string $method, array $params=array(), array &$add_msg=null) {
        if ($api_method == self::API_MQTT) {
            return $this->sendMqttRequest($api, $method, $params, $add_msg);
        }
        else {
            MqttApiClient::updateApiRequestAnswerCommands();
            MqttApiClient::updateApiRequestCommands();
            return $this->tc->sendJsonRpcRequestOK($method, $params);
        }
    }
    
    /**
     * Wait for the given broker ro respond correctly
     * @param string $bname broker name
     */
    private function waitForBroker(string $bname) {
        $api = $this->apis[$bname];
        for ($resp=null, $i=0 ; $i<10 ; $i++) {
            usleep(500000);
            $resp = $api->sendRequest('ping');
            if (isset($resp))
                break;
        }
        $this->tc->assertEquals("pong", $resp['result'], "Broker " . $bname . " does no more respond correctly");
    }
    
    public function assertLastCommunication(string $bname, string $name, int $lastCommunication=-1) {
        if ($lastCommunication < 0)
            $lastCommunication = time();
        
        $eqpt = $this->getEqptFromName($bname, $name);
        
        $res = $this->tc->sendJsonRpcRequestOK('eqLogic::byId', array('id' => $eqpt[self::KEY_ID]));
                
        // Check lastCommunication
        $this->tc->assertLessThan(2, abs(strtotime($res['status'][self::KEY_LAST_COMMUNICATION]) - $lastCommunication),
             'Wrong lastCommunication date for equipement ' . $name . ' associated to broker ' . $bname);
    }
    
    public function assertLogFiles() {
        // Determine the expected list of jMQTT log file
        $expectedLogFiles = $this->getBrokerEqptNames();
        foreach($expectedLogFiles as &$file) {
            $file = 'jMQTT_' . $file;
        }
        $expectedLogFiles[] = 'jMQTT';
        $expectedLogFiles[] = 'jMQTT_dep';
        natcasesort($expectedLogFiles);
        $expectedLogFiles = array_values($expectedLogFiles);
        
        // Get the actual list from Jeedom
        $actualLogFiles = $this->tc->sendJsonRpcRequestOK('log::list', array('filtre' => 'jMQTT'));
        
        $this->tc->assertEquals($expectedLogFiles, $actualLogFiles);
    }
    
    /**
     * Check all equipments
     * Check is done using the given API if not null, or with all API if null
     * Check is also done on the jMQTT equipment page if $check_html is true
     * @param int $api_method API method: either MqttEqpts::API_JSON_RPC or MqttEqpts::API_MQTT (API_MQTT by default)
     * @param string $_api api name (null to perform the check with all APIs or if JSON RPC API is used)
     * @param bool $check_html whether or not jMQTT equipment page shall be checked
     * [*]
     */
    public function assert(int $api_method=self::API_MQTT, string $_api=null, bool $check_html=true) {
        
        /** @var MqttApiClient[] $apis */
        if ($api_method == self::API_JSON_RPC) {
            $apis = array(reset($this->apis));
        }
        else {
            if (isset($_api))
                $apis = array($this->apis[$_api]);
            else
                $apis = $this->apis;
        }
        
        foreach ($apis as $api) {
            
            $actualEqpts = $this->getEqptsFromJeedom($api_method, $api);
            
            // Check brokers name
            $this->tc->assertEquals($this->getBrokerEqptNames(), array_keys($actualEqpts),
                'Broker names are not identical or not in the same order');
            
            // Check the number of eqpts
            // Get rid of non checked keys in $actualEqpts
            // Set commands eqLogic_id
            foreach($this->eqpts as &$eqpts) {
                $bname = $eqpts[0][self::KEY_NAME];
                $this->tc->assertCount(count($eqpts), $actualEqpts[$bname], 'Number of equipment is not as expected for broker ' . $bname);
                self::alignArrayKeysAndGetId($eqpts, $actualEqpts[$bname]);
                $this->setCmdEqLogicId();
            }

            foreach($this->eqpts as &$eqpts) {
                $bname = $eqpts[0][self::KEY_NAME];
                foreach($eqpts as $id => &$eqpt) {
                    $actualEqpt = $actualEqpts[$bname][$id];
                    
                    // Initialize brkId of the reference equipment the first time
                    if ($eqpt['configuration']['type'] == 'broker' && empty($eqpt['configuration'][self::KEY_BRK_ID])) {
                        $eqpt['configuration'][self::KEY_BRK_ID] = $eqpt[self::KEY_ID];
                    }
                                
                    if (array_key_exists(self::KEY_CMDS, $eqpt)) {
                        // Get full commands data using the API
                        $actualCmds = $this->sendJeedomRequest($api_method, $api, 'cmd::byEqLogicId', array('eqLogic_id' => $eqpt[self::KEY_ID]));
                    
                        // Check the number of cmds
                        $this->tc->assertCount(count($eqpt[self::KEY_CMDS]), $actualCmds,
                            'Bad number of commands for equipement ' . $eqpt[self::KEY_NAME] . ' associated to broker ' . $bname .
                            ' (api=' . $api->getBrokerName() . ')');
                
                        // Get rid of non checked keys in $actualCmds
                        self::alignArrayKeysAndGetId($eqpt[self::KEY_CMDS], $actualCmds);
                
                        $actualEqpt[self::KEY_CMDS] = $actualCmds;
                    }
                    
                    //for debug
                    file_put_contents('/tmp/expected.json', json_encode($eqpt, JSON_PRETTY_PRINT));
                    file_put_contents('/tmp/actual.json', json_encode($actualEqpt, JSON_PRETTY_PRINT));
                               
                    $this->tc->assertEquals($eqpt, $actualEqpt, 'Equipment ' . $eqpt[self::KEY_NAME] .
                        ', associated to broker ' . $bname . ', is not as expected' .
                        ' (api=' . $api->getBrokerName() . ')');
                }
            }
        }

        // Check equipment on the jMQTT equipment page
        if ($check_html)
            $this->tc->assertEqptList($this->getEqptListDisplayProp());
    }
    
    /**
     * 
     */
    public function test() {
        $resp = $this->apis->sendRequest('eqLogic::byType', array('type' => 'jMQTT'));
        $json_data = json_encode($resp, JSON_PRETTY_PRINT);
        file_put_contents('/tmp/eqpt.json', $json_data);

        $resp = $this->apis->sendRequest('cmd::byEqLogicId', array('eqLogic_id' => $this->eqpts[0][self::KEY_ID]));
        $json_data = json_encode($resp, JSON_PRETTY_PRINT);
        file_put_contents('/tmp/cmd.json', $json_data);
    }

    
    /**
     * Return a new broker from default_broker.json
     * @param string $bname broker name
     * @param string $address broker ip address
     * @param string $port broker ip port
     * @param string $mqtt_id MQTT client id for this broker
     * @param string|null $obj_id object id the equipment belongs to (null by default)
     * @return array broker
     */
    private function createBroker($bname, $address, $port, $mqtt_id, $obj_id=null) {
        $broker = json_decode(file_get_contents(__DIR__ . '/default_broker.json'), true);
        $broker[self::KEY_NAME] = $bname;
        $broker['object_id'] = $obj_id;
        $broker['configuration']['type'] = self::TYP_BRK;
        $broker['configuration'][self::CONF_MQTT_ADDRESS] = $address;
        $broker['configuration'][self::CONF_MQTT_PORT] = strval($port);
        $broker['configuration'][self::CONF_MQTT_ID] = $mqtt_id;
        $broker['logicalId'] = $mqtt_id . '/#';
        return $broker;
    }
    
    /**
     * Return a new equipment from default_eqpt.json.
     * logicalId is automatically set if $topic_auto is true.
     * @param string $name equipement name
     * @param string|null $obj_id object id the equipment belongs to (null by default)
     * @param bool $topic_auto wether or not topic is set to '$name/#'
     * @return array equipment
     */
    private function createEqpt($name, $obj_id=null, $topic_auto=true) {
        $eqpt = json_decode(file_get_contents(__DIR__ . '/default_eqpt.json'), true);
        $eqpt[self::KEY_NAME] = $name;
        $eqpt['object_id'] = $obj_id;
        if ($topic_auto) {
            $eqpt['logicalId'] = $name . '/#';
        }
        
        return $eqpt;
    }
    
    /**
     * Create a command
     * @param string $cmdName
     * @param string $type 'info' or 'action'
     * @param string $subtype
     * @param string $topic
     * @param string $val
     * @return array command
     */
    private function createCmd($cmdName, $type, $subtype, $topic, $val) {
        $cmd = json_decode(file_get_contents(__DIR__ . '/default_cmd.json'), true);
//        if ($this->api->getJeedomVersion() == '3.3') {
//             foreach($cmd as $id => &$v) {
//                 if (is_null($v))
//                     $v = false;
//             }
//        }
        $cmd['type'] = $type;
        $cmd['subType'] = $subtype;
        self::updateCmd($cmd, $cmdName, $topic, $val);
                
        return $cmd;
    }
    
    /**
     * Return the required command from the given equipment 
     * @param array $eqpt
     * @param string $cmdName
     * @return null|array command (null if not found)
     */
    private function &getCmd(&$eqpt, $cmdName) {
        $ret = null;
        if (array_key_exists(self::KEY_CMDS, $eqpt)) {
            foreach($eqpt[self::KEY_CMDS] as &$cmd) {
                if ($cmd[self::KEY_NAME] == $cmdName) {
                    $ret = &$cmd;
                    break;
                }
            }   
        }
        return $ret;
    }
    
    /**
     * Return the logical id of the given equipment
     * @param string $bname
     * @param string $name
     */
    public function getLogicalId(string $bname, string $name) {
        $eqpt = $this->getEqptFromName($bname, $name);
        return $eqpt['logicalId'];
    }
    
    /**
     * Update the given command
     * @param array $cmd the command to update
     * @param string $cmdName
     * @param string $topic
     * @param string $val
     * @return array $cmd is returned
     */
    private function updateCmd(&$cmd, $cmdName, $topic, $val) {
        $cmd[self::KEY_NAME] = $cmdName;
        $cmd['configuration']['topic'] = $topic;
        
        # For the moment, we suppose that all data are string
        $type = gettype($val);
        if ($type == 'integer' || $type == 'double')
            $val = strval($val);
        $cmd['currentValue'] = $val;
            
        $cmd['value'] = ($cmd['type'] == 'info') ? null: '';

        return $cmd;
    }

    /**
     * Return the command name as automatically built by the plugin
     * @param string $eqptTopic
     * @param string $cmdTopic
     * @return string
     */
    private function getAutomaticCmdName(string $eqptTopic, string $cmdTopic) {
        $pos1 = strpos($eqptTopic, '#');
        $pos2 = strpos($eqptTopic, '+');
        $pos = $pos1 === false ? $pos2 : ($pos2 === false ? $pos1 : ($pos1 > $pos2 ? $pos2 : $pos1));
        if ($pos === false) {
            $topicArray = explode("/", $cmdTopic);
            $cmdName = end($topicArray);
        }
        else {
            $cmdName = substr($cmdTopic, $pos);
        }
        
        if (strlen($cmdName) > 45) {
            $cmdName = hash("md4", $cmdName);
        }
        
        return str_replace("/", ":", $cmdName);;
    }
    
    /**
     * @param MqttApiClient $api
     */
    private function initFromMqttAPI(MqttApiClient $api) {
        
        $Eqpts = $this->getEqptsFromJeedom(self::API_MQTT, $api);
                      
        foreach($Eqpts as $bname => $eqpts) {
            foreach($eqpts as $eqpt) {
                if (! $this->existsByName($bname, $eqpt[self::KEY_NAME]))
                    $this->add($bname, $eqpt[self::KEY_NAME], false, $eqpt['object_id']);
            }
        }
        
        self::copyValues($Eqpts, $this->eqpts, false);
    }
    
    /**
     * @param int $api_method API method: either MqttEqpts::API_JSON_RPC or MqttEqpts::API_MQTT
     * @param null|MqttApiClient $api API to be used if method is MQTT API
     * @return string[][]
     */
    private function getEqptsFromJeedom(string $api_method, MqttApiClient $api=null) {
        
        // Get full equipement data using the API
        $resp = $this->sendJeedomRequest($api_method, $api, 'eqLogic::byType', array('type' => 'jMQTT'));
        
        // Separate equipments by brokers; order equipments
        $tmp_eqpts = array();
        $id_to_bname = array();
        foreach($resp as $eqpt) {
            $brkId = $eqpt['configuration'][self::KEY_BRK_ID];
            $tmp_eqpts[$brkId][] = $eqpt;
            if ($brkId == $eqpt[self::KEY_ID]) {
                $id_to_bname[$brkId] = $eqpt['name'];
                $this->tc->assertEquals('broker', $eqpt['configuration']['type'], 'Eqpt should be of type broker');
            }
            else {
                $this->tc->assertEquals('eqpt', $eqpt['configuration']['type'], 'Eqpt should be of type eqpt');
            }
        }
        $eqpts = array();
        foreach($tmp_eqpts as $bname => $aeqpts) {
            $eqpts[$id_to_bname[$bname]] = $aeqpts;
            
            // Sort $actualEqpts[] by alphabetical order on the equipment name
            usort($eqpts[$id_to_bname[$bname]], array('MqttEqpts', 'eqptcmp'));
        }
        unset($tmp_eqpts);
        
        uksort($eqpts, array('MqttEqpts', 'eqptnamecmp'));
               
        return $eqpts;
    }
    
    /**
     * Return the equipment array associated to the given broker and which name is the given one
     * @param string $bname broker name
     * @param string $name
     * @return array|NULL equipment (null if not found)
     */
    private function &getEqptFromName($bname, $name) {
        foreach($this->eqpts[$bname] as &$eqpt) {
            if ($eqpt[self::KEY_NAME] === $name) {
                return $eqpt;
            }
        }
        throw new \Exception('eqpt ' . $name . ' does not exist in broker ' . $bname);
    }
    
    /**
     * Return a table of the names of the broker equipment
     * @return String[]
     */
    private function getBrokerEqptNames() {
        $bNames = array();
        foreach($this->eqpts as $eqpts) {
            $bNames[] = $eqpts[0][self::KEY_NAME];
        }
        return $bNames;
    }
    
    /**
     * Return an array with the equipment name and properties displayed on the plugin equipment page
     * @return Array[string][string]
     */
    private function getEqptListDisplayProp() {
        $ret = array();
        foreach($this->eqpts as $bName => $eqpts) {
            foreach($eqpts as $eqpt) {
                $ret[$bName][] = array(
                    self::KEY_NAME => $eqpt[self::KEY_NAME],
                    self::CONF_AUTO_ADD_CMD => $eqpt['configuration'][self::CONF_AUTO_ADD_CMD] == '0' ? false : true
                );
            }
            if ($this->daemonStates[$bName] != self::DAEMON_UNCHECKED)
                $ret[$bName][0][self::KEY_DAEMON_STATE] = $this->daemonStates[$bName];
        }
        return $ret;
    }
    
    /**
     * Copy values from the src array to the dest array
     * Only keys defined in the src array are treated if $keys_from_src_only is true
     * Only keys defined in the src and dest arrays are treated if $keys_from_src_only is false
     * @param array $src source array
     * @param array $dest destination array
     * @param bool $keys_from_src_only
     * [*]
     */
    private static function copyValues(array $src, array &$dest, bool $keys_from_src_only=true) {
        if ($keys_from_src_only) {
            foreach($src as $key => $val) {
                if (is_array($val))
                    self::copyValues($src[$key], $dest[$key], $keys_from_src_only);
                else
                    $dest[$key] = $src[$key];
            }
        }
        else {
            foreach($dest as $key => $val) {
                if (array_key_exists($key, $src)) {
                    if (is_array($val))
                        self::copyValues($src[$key], $dest[$key], $keys_from_src_only);
                    else
                        $dest[$key] = $src[$key];
                }
            }
        }
    }
    
    /**
     * Set the eqLogic_id of all commands
     */
    private function setCmdEqLogicId() {
        foreach($this->eqpts as &$eqpts) {
            foreach($eqpts as &$eqpt) {
                if (array_key_exists(self::KEY_CMDS, $eqpt)) {
                    foreach($eqpt[self::KEY_CMDS] as &$cmd) {
                        $cmd['eqLogic_id'] = $eqpt[self::KEY_ID];
                    }
                }
            }
        }
    }
    
    /**
     * Make the $sub array containing the same keys as the $ref array, and
     * fill the self::KEY_ID field in $ref.
     * Keys from $sub that are not present in $ref are suppressed.
     * Function is recursive.
     * @param array $ref
     * @param array $sub
     */
    private static function alignArrayKeysAndGetId(array &$ref, array &$sub) {
        foreach($sub as $key => $val) {
            if ($key === self::KEY_ID && array_key_exists($key, $ref))
                $ref[$key] = $val;            
            if (!array_key_exists($key, $ref)) {
                unset($sub[$key]);
            }
            elseif (is_array($val) && is_array($ref[$key])) {
                self::alignArrayKeysAndGetId($ref[$key], $sub[$key]);
            }
        }
    }
}