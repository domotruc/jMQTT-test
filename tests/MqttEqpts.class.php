<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'Util.class.php';
include_once 'JeedomObjects.class.php';
include_once 'MqttTestCase.php';
include_once 'MqttEqptCmd.class.php';

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
    public const KEY_ID = Util::KEY_ID;
    public const KEY_BRK_ID = 'brkId';
    public const KEY_DAEMON_STATE = 'daemon_state';
    public const KEY_CONFIGURATION = 'configuration';
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
     * @param bool $topic_auto whether or not topic is set to '$name/#'
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
        Util::copyValues($param, $eqpt);
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
        
        $this->setParameters($bname, $ename, array(self::KEY_CONFIGURATION => array($key => $value)));
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
     * Add a command from the JSON command tab view
     *
     * Start page req.: the equipment page, command tab, JSON view
     * End page: same as start page
     */
    public function addJsonCmd_ui(string $bname, string $ename, string $topic, $val, string $cname, bool $save = true) {
        $this->tc->setJsonCmdName($topic, $cname);
        $this->setCmdInfo($bname, $ename, $topic, $val, $cname);
        if ($save) {
            $this->tc->saveEqLogic();
        }
    }
    
    /**
     * Add a command from the JSON command tab view
     *
     * Start page req.: the equipment page, command tab, Classic view
     * End page: same as start page
     */
    public function moveCmd_ui(string $bname, string $ename, string $cnameSource, string $cnameTarget, bool $save = true) {
        $this->tc->moveCmd($cnameSource, $cnameTarget);
        
        $eqpt = & $this->getEqptFromName($bname, $ename);
        $i_source = $this->getCmdIndex($eqpt, $cnameSource);
        $i_target = $this->getCmdIndex($eqpt, $cnameTarget);
        //$save = $eqpt[self::KEY_CMDS][$i_source];
        
        $save = array_splice($eqpt[self::KEY_CMDS], $i_source, 1);
        array_splice($eqpt[self::KEY_CMDS], $i_target, 0, $save);
        
        $this->setCmdOrders($bname, $ename);
        
        if ($save) {
            $this->tc->saveEqLogic();
        }
    }
    
    public function deleteCmd_ui(string $bname, string $ename, string $cname, bool $save = true) {
        $this->tc->deleteCmd($cname);
        
        $eqpt = & $this->getEqptFromName($bname, $ename);
        $index = $this->getCmdIndex($eqpt, $cname);
        array_splice($eqpt[self::KEY_CMDS], $index, 1);
        
        $this->setCmdOrders($bname, $ename);
        
        if ($save) {
            $this->tc->saveEqLogic();
        }
    }
    
    /**
     * Update or add an action cmd to the given named equipment associated to the given broker
     * @param string $bname
     * @param string $ename
     * @param string $topic
     * @param string $cmdName
     * @param string $subtype
     * @param string|null $val optional (null by default)
     * @return MqttEqptCmd the created command
     */
    public function setCmdAction(string $bname, string $ename, string $topic, string $cmdName, string $subtype, $val=null) {
        return $this->setCmd($bname, $ename, MqttEqptCmd::TYP_ACTION, $subtype, $topic, $val, $cmdName);
    }

    /**
     * Update or add an info cmd to the given named equipment associated to the given broker
     * @param string $bname
     * @param string $ename
     * @param string $topic
     * @param string|null $val
     * @param string $cname
     * @param string $subtype optional (default=string)
     * @return MqttEqptCmd the created command
     */
    public function setCmdInfo(string $bname, string $ename, string $topic, $val, string $cname=null, string $subtype='string') {
        return $this->setCmd($bname, $ename, MqttEqptCmd::TYP_INFO, $subtype, $topic, $val, $cname);
    }
    
    /**
     * Update or add a cmd to the given named equipment from an mqtt message
     * @param string $bname
     * @see MqttEqpts::setCmd()
     * @param string $ename
     * @param array $msg cmd is defined by $msg[MqttGen::S_TOPIC] and $msg[MqttGen::S_PAYLOAD]
     * @param string $cmdName (optional) command name (automatically defined as the plugin does if null)
     * @return MqttEqptCmd the created or updated command
     * @throw Exception if equipement does not exist
     */
    public function setCmdFromMsg(string $bname, string $ename, array $msg, $cmdName=null) {
        return $this->setCmdInfo($bname, $ename, $msg[MqttGen::S_TOPIC], $msg[MqttGen::S_PAYLOAD], $cmdName, 'string');
    }
    
    /**
     * Update commands of the given named equipment derived from the given JSON mqtt message
     * @param string $bname
     * @param string $ename
     * @param array $msg cmd is defined by $msg[MqttGen::S_TOPIC] and $msg[MqttGen::S_PAYLOAD]
     * @throw Exception if equipement does not exist
     */
    public function setCmdFromJsonMsg(string $bname, string $ename, array $msg) {
        
        $eqpt = & $this->getEqptFromName($bname, $ename);
        
        $parseJsonFunc = function(string $topic, $payload) use (&$parseJsonFunc, &$eqpt, $bname, $ename) {
            if (is_array($payload)) {
                $payload_str = json_encode($payload);
                foreach($payload as $key => $sub_payload) {
                    $parseJsonFunc($topic . '{' . $key . '}', $sub_payload);
                }
            }
            else {
                $payload_str = $payload;
            }
            
            $cmd = MqttEqptCmd::byTopic($eqpt[self::KEY_CMDS], $topic);
            if ($cmd !== null) {
                $this->setCmdInfo($bname, $ename, $topic, $payload_str, $cmd->getName());
            }
        };
        
        $parseJsonFunc($msg[MqttGen::S_TOPIC], json_decode($msg[MqttGen::S_PAYLOAD], true));
    }
    
    /**
     * Update or add an info cmd to the given broker
     * @param string $bname
     * @param string $topic
     * @param string|null $val
     * @return MqttEqptCmd the created command
     */
    public function setBrokerCmdInfo(string $bname, string $topic, $val) {
        return $this->setCmdInfo($bname, $this->eqpts[$bname][0][self::KEY_NAME], $topic, $val);
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
     * @param string $type MqttEqptCmd::TYP_ACTION or MqttEqptCmd::TYP_INFO
     * @param string $subtype
     * @param string $topic
     * @param string|null $val
     * @param string $cmdName (optional) command name (automatically defined as the plugin does if null)
     * @return MqttEqptCmd the created command
     * @throw Exception if equipement does not exist
     */
    private function setCmd(string $bname, string $ename, string $type, string $subtype, string $topic, $val, string $cmdName=null) {
        
        $eqpt = & $this->getEqptFromName($bname, $ename);
        
        // Command name not provided: built it automatically
        if (!isset($cmdName)) {
            $cmdName = MqttEqptCmd::getAutomaticCmdName($eqpt['logicalId'], $topic);            
        }
        $cmdName = str_replace("/", ":", $cmdName);
        
        if (($cmd = self::getCmd($eqpt, $cmdName)) == null) {
            $cmd = MqttEqptCmd::new($cmdName, $topic, $eqpt[self::KEY_ID], $val, $type, $subtype);
            if (! array_key_exists(self::KEY_CMDS, $eqpt)) {
                $eqpt[self::KEY_CMDS] = array();
            }
            $cmd->setOrder($type == MqttEqptCmd::TYP_ACTION ? strval(count($eqpt[self::KEY_CMDS])) : '0');
            $eqpt[self::KEY_CMDS][] = $cmd;
            
            // Sort the array according to the order of the cmd::byEqLogicId API command
            usort($eqpt[self::KEY_CMDS], function($a, $b) {
                $order_a = intval($a->getOrder());
                $order_b = intval($b->getOrder());
                if ($order_a == $order_b)
                    return strcasecmp($a->getName(), $b->getName());
                else
                    return $order_a - $order_b;
            });            
        }
            
        $cmd->update($cmdName, $topic, $val);
        
        return $cmd;
    }
    
    /**
     * Set the order configuration parameter of the commands of the defined equipment
     * @param string $bname broker name
     * @param string $ename equipment name
     */
    private function setCmdOrders(string $bname, string $ename) {
        $eqpt = & $this->getEqptFromName($bname, $ename);
        MqttEqptCmd::setCmdOrders($eqpt[self::KEY_CMDS]);
    }
    
    /**
     * Returns whether or not the given equipement exists in this object
     * @param string $bname
     * @param string $ename
     * @return boolean
     */
    public function existsByName(string $bname, string $ename) {
        if (array_key_exists($bname, $this->eqpts)) {
            return Util::inArrayByKeyValue($this->eqpts[$bname], self::KEY_NAME, $ename);
        }
        return false;       
    }
    
    /**
     * Returns whether or not the given equipement exists in this object
     * @param string $bname
     * @param string $topic
     * @return boolean
     */
    public function existsByTopic(string $bname, string $topic) {
        if (array_key_exists($bname, $this->eqpts)) {
            return Util::inArrayByKeyValue($this->eqpts[$bname], 'logicalId', $topic);
        }
        return false;
    }
    
    /**
     * Returns whether or not the given equipement exists in this object
     * @param string $bname
     * @param string $eqptName
     * @return boolean
     */
    public function existsById(string $bname, string $id) {
        if (array_key_exists($bname, $this->eqpts)) {
            return Util::inArrayByKeyValue($this->eqpts[$bname], self::KEY_ID, $id);
        }
        return false;
    }
        
    /**
     * Delete the given equipment from Jeedom interface and from this object
     * @param string $bname
     * @param string $ename
     * @return boolean whether or not an equipment has been deleted
     */
    public function deleteFromInterface(string $bname, string $ename) {
        foreach($this->eqpts[$bname] as $i => $eqpt) {
            if ($ename == $eqpt[self::KEY_NAME]) {
                $this->tc->deleteEqpt($bname, $ename);
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
    

    /**
     * Assert that the last communication with the given equipement occured within 2s wrt the given
     * time.
     * @param string $bname broker name
     * @param string $name equipment name
     * @param int $time unix timestamp (if negative, current time is considered)
     */
    public function assertLastCommunication(string $bname, string $name, int $time=-1) {
        if ($time < 0)
            $time = time();
        
        $eqpt = $this->getEqptFromName($bname, $name);
        
        $res = $this->tc->sendJsonRpcRequestOK('eqLogic::byId', array('id' => $eqpt[self::KEY_ID]));
                
        // Check lastCommunication
        $this->tc->assertLessThan(2, abs(strtotime($res['status'][self::KEY_LAST_COMMUNICATION]) - $time),
             'Wrong lastCommunication date for equipement ' . $name . ' associated to broker ' . $bname);
    }
    
    /**
     * Check the plugin log files are the expected ones
     */
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
                Util::alignArrayKeysAndGetId($eqpts, $actualEqpts[$bname]);
                $this->setCmdEqLogicId();
            }

            foreach($this->eqpts as &$eqpts) {
                $bname = $eqpts[0][self::KEY_NAME];
                foreach($eqpts as $id => &$eqpt) {
                    $actualEqpt = $actualEqpts[$bname][$id];
                    
                    // Initialize brkId of the reference equipment the first time
                    if ($eqpt[self::KEY_CONFIGURATION]['type'] == 'broker' && empty($eqpt['configuration'][self::KEY_BRK_ID])) {
                        $eqpt[self::KEY_CONFIGURATION][self::KEY_BRK_ID] = $eqpt[self::KEY_ID];
                    }
                                
                    if (array_key_exists(self::KEY_CMDS, $eqpt)) {
                        // Get full commands data using the API
                        $actualCmds = $this->sendJeedomRequest($api_method, $api, 'cmd::byEqLogicId', array('eqLogic_id' => $eqpt[self::KEY_ID]));
                    
                        // Check the number of cmds
                        $this->tc->assertCount(count($eqpt[self::KEY_CMDS]), $actualCmds,
                            'Bad number of commands for equipement ' . $eqpt[self::KEY_NAME] . ' associated to broker ' . $bname .
                            ' (api=' . $api->getBrokerName() . ')');
                
                        // Build the actual cmds array
                        $actualEqpt[self::KEY_CMDS] = MqttEqptCmd::createCmdArray($actualCmds);

                        // When not yet defined, set the id of the expected command from the actual command 
                        for($i=0 ; $i<count($eqpt[self::KEY_CMDS]) ; $i++) {
                            $eqpt[self::KEY_CMDS][$i]->setIdIfEmpty($actualEqpt[self::KEY_CMDS][$i]->getId());
                        }
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
     * @param string $bname
     * @param string $ename
     * @param bool $checkJsonFalseCmdValues whether or not values of the non existing commands of the JSON view are checked or not
     */
    public function assertCmdPanel(string $bname, string $ename, bool $checkJsonFalseCmdValues=true) {
        $eqpt = $this->getEqptFromName($bname, $ename);
        $refCmds = $eqpt[self::KEY_CMDS];
        
        $view =$this->tc->getCmdView();
        if ($view == $this->tc::VIEW_JSON) {
                      
            $newRefCmds = array();
            
            $addFunc = function($topic, $payload) use ($refCmds, &$newRefCmds, $eqpt) {
                if (MqttEqptCmd::byTopic($newRefCmds, $topic) !== null) {
                    return;
                }
                $existing_cmd =  MqttEqptCmd::byTopic($refCmds, $topic);
                if (isset($existing_cmd)) {
                    $newRefCmds[] = $existing_cmd;
                }
                else {
                    $newRefCmds[] = MqttEqptCmd::new("", $topic, $eqpt[self::KEY_ID], is_array($payload) ? json_encode($payload) : $payload);
                }
            };

            $addJsonFunc = function($topic, $payload) use (&$addJsonFunc, &$addFunc, $refCmds, &$newRefCmds, $eqpt) {
                $addFunc($topic, $payload);
                if (!is_array($payload)) {
                    return;
                }
                foreach($payload as $key => $val) {
                    $child_topic = $topic . '{' . $key . '}';
                    $addJsonFunc($child_topic, $val);
                }
            };
            
            /**
             * @param string $topic
             * @param string|array|null $payload
             */
            $parseAddFunc = function(string $topic, $payload) use (&$parseAddFunc, &$addJsonFunc, $refCmds, &$newRefCmds, $eqpt) {
                $new_payload = json_decode($payload, true);
                if (json_last_error() == JSON_ERROR_NONE)
                    $payload = $new_payload;
                
                $pos = strrpos($topic, '{');
                if ($pos !== false) {
                    $father_topic = substr($topic, 0, $pos);
                    $father_cmd = MqttEqptCmd::byTopic($refCmds, $father_topic);
                    $parseAddFunc($father_topic, isset($father_cmd) ? $father_cmd->getCurrentValue() : null);
                }
                
                if (MqttEqptCmd::byTopic($newRefCmds, $topic) === null && isset($payload)) {
                    $addJsonFunc($topic, $payload);
                }
            };
                        
            /** @var MqttEqptCmd $cmd */
            foreach ($refCmds as $cmd) {
                if (! MqttEqptCmd::inArrayByKeyValue($newRefCmds, self::KEY_NAME, $cmd->getName())) {
                    if ($cmd->getType() == MqttEqptCmd::TYP_INFO) {
                        $parseAddFunc($cmd->getTopic(), $cmd->getCurrentValue());
                    }
                    else {
                        $newRefCmds[] = $cmd;
                    }
                }
            }
            
            $refCmds = $newRefCmds;
        }
        
        // Get displayed commands and compare them to the expected one
        // The command retrieval in the browser window is done in a loop to let the time for the value to be refreshed
        $startTime = microtime(true);
        do {
            $isNok = true;
            $actualCmds = $this->tc->getCmds();
            try {
                // Check the number of cmds
                $this->tc->assertCount(count($refCmds), $actualCmds,
                    'Bad number of displayed commands for equipement ' . $ename . ' associated to broker ' . $bname . ')');
                
                // Reset command values for non existing commands if $checkJsonFalseCmdValues is false
                if (! $checkJsonFalseCmdValues) {
                    MqttEqptCmd::resetFalseCommandValues($actualCmds, $refCmds);
                }
                
                // Do not check the order when checking the cmd panel in JSON view
                // (the order is different)
                if (MqttEqptCmd::isCmdOrdersSet($refCmds)) {
                    if ($view == $this->tc::VIEW_JSON) {
                        MqttEqptCmd::setCmdOrders($actualCmds, $refCmds);
                    }
                }
                else {
                    MqttEqptCmd::setCmdOrders($actualCmds, 0);
                }
                
                $this->tc->assertEquals($refCmds, $actualCmds);
                $isNok = false;
            }
            catch (Exception $e) {
                usleep(100000);
            }
        }
        while ($isNok && (microtime(true) - $startTime < 5.0));
        
        // for debug
        file_put_contents('/tmp/expected_cmds.json', json_encode($refCmds, JSON_PRETTY_PRINT));
        file_put_contents('/tmp/actual_cmds.json', json_encode($actualCmds, JSON_PRETTY_PRINT));
        
        $this->tc->assertEquals($refCmds, $actualCmds, 'Displayed commands of equipement ' . $ename .
            ', associated to broker ' . $bname . ', are not as expected' . ')');
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
        $broker[self::KEY_CONFIGURATION]['type'] = self::TYP_BRK;
        $broker[self::KEY_CONFIGURATION][self::CONF_MQTT_ADDRESS] = $address;
        $broker[self::KEY_CONFIGURATION][self::CONF_MQTT_PORT] = strval($port);
        $broker[self::KEY_CONFIGURATION][self::CONF_MQTT_ID] = $mqtt_id;
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
     * Return the required command from the given equipment 
     * @param array $eqpt
     * @param string $cname
     * @return null|MqttEqptCmd command (null if not found)
     */
    private function getCmd($eqpt, $cname) {
        $key = $this->getCmdIndex($eqpt, $cname);
        return $key === false ? null : $eqpt[self::KEY_CMDS][$key];

    }
    
    /**
     * Return the required command index from the given equipment
     * @param array $eqpt
     * @param string $cname
     * @return bool|int command index (false if not found)
     */
    private function getCmdIndex(array $eqpt, string $cname) {
        $ret = false;
        if (array_key_exists(self::KEY_CMDS, $eqpt)) {
            for ($i=0 ; $i<count($eqpt[self::KEY_CMDS]) ; $i++) {
                if ($eqpt[self::KEY_CMDS][$i]->getName() == $cname) {
                    $ret = $i;
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
        
        Util::copyValues($Eqpts, $this->eqpts, false);
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
            $brkId = $eqpt[self::KEY_CONFIGURATION][self::KEY_BRK_ID];
            $tmp_eqpts[$brkId][] = $eqpt;
            if ($brkId == $eqpt[self::KEY_ID]) {
                $id_to_bname[$brkId] = $eqpt[self::KEY_NAME];
                $this->tc->assertEquals('broker', $eqpt[self::KEY_CONFIGURATION]['type'], 'Eqpt should be of type broker');
            }
            else {
                $this->tc->assertEquals('eqpt', $eqpt[self::KEY_CONFIGURATION]['type'], 'Eqpt should be of type eqpt');
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
     * @throw Exception if the equipment does not exist
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
                    self::CONF_AUTO_ADD_CMD => $eqpt[self::KEY_CONFIGURATION][self::CONF_AUTO_ADD_CMD] == '0' ? false : true
                );
            }
            if ($this->daemonStates[$bName] != self::DAEMON_UNCHECKED)
                $ret[$bName][0][self::KEY_DAEMON_STATE] = $this->daemonStates[$bName];
        }
        return $ret;
    }
    
    
    /**
     * Set the eqLogic_id of all commands when not alreay set
     */
    private function setCmdEqLogicId() {
        foreach($this->eqpts as &$eqpts) {
            foreach($eqpts as &$eqpt) {
                if (array_key_exists(self::KEY_CMDS, $eqpt)) {
                    /** @var MqttEqptCmd $cmd */
                    foreach($eqpt[self::KEY_CMDS] as $cmd) {
                        if (empty($cmd->getEqLogicId()))
                            $cmd->setEqLogicId($eqpt[self::KEY_ID]);
                    }
                }
            }
        }
    }
}