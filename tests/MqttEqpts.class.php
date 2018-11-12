<?php

require_once('vendor/autoload.php');
include_once 'JeedomObjects.class.php';

use Bluerhinos\phpMQTT;
use MqttGen\MqttGen;

/**
 * Mirrors the jMQTT equipments that should be available in Jeedom
 *
 */
class MqttEqpts {

    private const KEY_CMDS = 'cmds';
    
    public const KEY_NAME = 'name';
    public const KEY_AUTO_ADD_CMD = 'auto_add_cmd';
    
    /**
     * @var array jMQTT equipments array
     */
    private $eqpts;
    
    /**
     * @var MqttTestCase
     */
    private $tc;
    
    /**
     * @var MqttApiClient
     */
    private $api;
    
    function __construct(MqttTestCase $tc, MqttApiClient $api, bool $init_from_plugin = false) {
        $this->eqpts = array();
        $this->tc = $tc;
        $this->api = $api;
        
        JeedomObjects::init($api);
        if ($init_from_plugin) {
            $this->initFromAPI();
        }
    }

    /**
     * Add an eqpt which name is given.
     * Subscription topic is set to '$name/#', as the plugin does when adding an eqpt automatically
     * Order the array in alphabetical order by the 2 following keys : belonging object name, eqpt name 
     * @param string $name equipement name
     * @param string|null $obj_id object id the equipment belongs to (null by default) 
     */
    public function add(string $name, string $obj_id=null) {
        $this->eqpts[] = $this->createEqpt($name, $obj_id);
        
        // Sort the array according to the order of the eqLogic::byType API command
        usort($this->eqpts, function($a, $b) {
            $obj_a = isset($a['object_id']) ? JeedomObjects::getById($a['object_id'])[self::KEY_NAME] : ' ';
            $obj_b = isset($b['object_id']) ? JeedomObjects::getById($b['object_id'])[self::KEY_NAME] : ' ';
            if (($ret = strnatcasecmp($obj_a, $obj_b)) == 0)
                $ret = strnatcasecmp($a[self::KEY_NAME], $b[self::KEY_NAME]);
            
            return $ret;
        });
    }

    /**
     * Callback for the addFromMqttBroker function
     * @param string $topic
     * @param string $msg
     */
    public function processMqttMsg($topic, $msg){
        $topicArray = explode('/', $topic);
        if (! $this->exists($topicArray[0]))
            $this->add($topicArray[0]);
    }
    
    /**
     * Connect the MQTT broker, subscribe to '#', and add equipments corresponding to the received topics
     * To be used when enabling the jMQTT automatic inclusion mode to determine the expected equipment list
     */
    public function addFromMqttBroker() {
        $mqtt = new phpMQTT($_ENV['mosquitto_host'], $_ENV['mosquitto_port'], "phpMQTT");
        $mqtt->connect();

        $mqtt->subscribe(array('#' => array("qos" => 0, "function" => array($this, 'processMqttMsg'))), 0);
        for ($i = 1; $i <= 10; $i++) {
            $mqtt->proc();
        }
        $mqtt->close();
    }

    /**
     * Set given parameters of the given named eqpt
     * @param string $name
     * @param array $param
     */
    public function setParameters(string $name, array $param) {
        $eqpt = & $this->getEqptFromName($name);
        self::copyValues($param, $eqpt);
    }
    
    /**
     * Set the auto command adding flag of the named equipement.
     * Done through the user i/f
     * Start page spec: the equipment page
     * End page: the equipment page
     * @param string $name
     * @param bool $is_enabled
     */
    public function setAutoCmdAdding(string $name, string $is_enabled) {
        $this->setParameters($name, array('configuration' => array(self::KEY_AUTO_ADD_CMD => $is_enabled ? "1" : "0")));
        $this->tc->setAutoCmdAdding($is_enabled);
    }
    
    /**
     * Update or add a cmd to the given named equipment
     * @param string $eqptName
     * @param string $topic
     * @param string|null $val
     * @param string $cmdName (optional) command name (automatically defined as the plugin does if null)
     * @throw Exception if equipement does not exist
     */
    public function setCmd(string $eqptName, string $topic, $val, $cmdName=null) {
        if (($eqpt = & $this->getEqptFromName($eqptName)) == null)
            throw new Exception('eqpt ' . $eqptName . ' does not exist');
        
        // Command name not provided: built it automatically
        if (!isset($cmdName)) {
            $cmdName = self::getAutomaticCmdName($eqpt['logicalId'], $topic);            
        }
        
        if (($cmd = & self::getCmd($eqpt, $cmdName)) == null) {
            $cmd = self::createCmd($cmdName, $topic, $val);
            if (! array_key_exists(self::KEY_CMDS, $eqpt))
                $eqpt[self::KEY_CMDS] = array();
            $cmd['eqLogic_id'] = $eqpt['id'];
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
     * Update or add a cmd to the given named equipment from an mqtt message
     * @see MqttEqpts::setCmd()
     * @param string $eqptName
     * @param array $msg cmd is defined by $msg[MqttGen::S_TOPIC] and $msg[MqttGen::S_PAYLOAD]
     * @param string $cmdName (optional) command name (automatically defined as the plugin does if null)
     * @throw Exception if equipement does not exist
     */
    public function setCmdFromMsg(string $eqptName, array $msg, $cmdName=null) {
        $this->setCmd($eqptName, $msg[MqttGen::S_TOPIC], $msg[MqttGen::S_PAYLOAD], $cmdName);
    }
    
    /**
     * Returns whether or not the given equipement exists
     * @param string $name
     * @return boolean
     */
    public function exists(string $name) {
        foreach($this->eqpts as $i => $eqpt) {
            if ($name == $eqpt[self::KEY_NAME])
                return true;
        }
        
        return false;       
    }
    
    /**
     * Delete the given equipment from Jeedom and from this object
     * @param string $name
     * @return boolean whether or not an equipment has been deleted 
     */
    public function delete(string $name) {
        foreach($this->eqpts as $i => $eqpt) {
            if ($name == $eqpt[self::KEY_NAME]) {
                $this->tc->deleteEqpt($name);
                array_splice($this->eqpts, $i, 1);
                return true;
            }
        }
        
        return false;
    }
    
    public function prettyPrint() {
        print(json_encode($this->eqpts, JSON_PRETTY_PRINT));
    }

    /**
     * Check all equipments through the API and on the jMQTT equipment page if $check_html is true
     * @param bool $check_html whether or not jMQTT equipment page shall be checked
     */
    public function assert(bool $check_html=true) {
        // Get full equipement data using the API
        $resp = $this->api->sendRequest('eqLogic::byType', array('type' => 'jMQTT'));
        $this->tc->assertNotNull($resp, 'API did not return any message');
        $this->tc->assertArrayNotHasKey('error', $resp, 'API returned an error on eqLogic::byType request');
        $actualEqpts = $resp['result'];
        
        // Check the number of eqpts
        $this->tc->assertCount(count($this->eqpts), $actualEqpts, 'Bad number of jMQTT equipments');
        
        // Get rid of non checked keys in $actualEqpts
        self::alignArrayKeysAndGetId($this->eqpts, $actualEqpts);
        
        // Set commands eqLogic_id
        $this->setCmdEqLogicId();

        foreach($this->eqpts as $id => &$eqpt) {
            
            $actualEqpt = $actualEqpts[$id];
            
            if (array_key_exists(self::KEY_CMDS, $eqpt)) {
                // Get full commands data using the API
                $resp = $this->api->sendRequest('cmd::byEqLogicId', array('eqLogic_id' => $eqpt['id']));
                $this->tc->assertArrayNotHasKey('error', $resp, 'API returned an error on cmd::byEqLogicId request');
                $actualCmds = $resp['result'];
                
                // Check the number of cmds
                $this->tc->assertCount(count($eqpt[self::KEY_CMDS]), $actualCmds,
                    'Bad number of commands for equipement ' . $eqpt[self::KEY_NAME]);
                
                //for debug
                file_put_contents('/tmp/expected.json', json_encode($eqpt, JSON_PRETTY_PRINT));
                file_put_contents('/tmp/actual.json', json_encode($actualEqpt, JSON_PRETTY_PRINT));
                
                // Get rid of non checked keys in $actualCmds
                self::alignArrayKeysAndGetId($eqpt[self::KEY_CMDS], $actualCmds);
                
                $actualEqpt[self::KEY_CMDS] = $actualCmds;
            }
            
            $this->tc->assertJsonStringEqualsJsonString(json_encode($eqpt), json_encode($actualEqpt),
                'assertion error in equipment ' . $eqpt[self::KEY_NAME]);
        }

        // Check equipment on the jMQTT equipment page
        if ($check_html)
            $this->tc->assertEqptList($this->getEqptListDisplayProp());
    }
    
    /**
     * 
     */
    public function test() {
        $resp = $this->api->sendRequest('eqLogic::byType', array('type' => 'jMQTT'));
        $json_data = json_encode($resp, JSON_PRETTY_PRINT);
        file_put_contents('/tmp/eqpt.json', $json_data);

        $resp = $this->api->sendRequest('cmd::byEqLogicId', array('eqLogic_id' => $this->eqpts[0]['id']));
        $json_data = json_encode($resp, JSON_PRETTY_PRINT);
        file_put_contents('/tmp/cmd.json', $json_data);
    }
    
    /**
     * Create a new equipment from default_eqpt.json
     * Name, logicalId and topic are set according to the given name
     * @param string $name equipement name
     * @param string|null $obj_id object id the equipment belongs to (null by default)
     * @return array equipment
     */
    private function createEqpt($name, $obj_id=null) {
        $eqpt = json_decode(file_get_contents(__DIR__ . '/default_eqpt.json'), true);
        $eqpt[self::KEY_NAME] = $name;
        $eqpt['object_id'] = $obj_id;
        $eqpt['logicalId'] = $name . '/#';
        $eqpt['configuration']['topic'] = $eqpt['logicalId'];
        return $eqpt;
    }
    
    /**
     * Create a command
     * @param string $cmdName
     * @param string $topic
     * @param string $val
     * @return array command
     */
    private function createCmd($cmdName, $topic, $val) {
        $cmd = json_decode(file_get_contents(__DIR__ . '/default_cmd.json'), true);
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
            foreach($eqpt[self::KEY_CMDS] as $id => &$cmd) {
                if ($cmd[self::KEY_NAME] == $cmdName) {
                    $ret = &$cmd;
                    break;
                }
            }   
        }
        return $ret;
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
        $cmd['logicalId'] = $topic;

        # For the moment, we suppose that all data are string
        $type = gettype($val);
        if ($type == 'integer' || $type = "double")
            $val = strval($val);
        
        $cmd['currentValue'] = $val;
        $cmd['configuration']['topic'] = $topic;
        $cmd['configuration']['value'] = $val;
        return $cmd;
    }

    private function getAutomaticCmdName($eqptTopic, $cmdTopic) {
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
        
        return $cmdName;
    }
    
    private function initFromAPI() {
        // Get full equipement data using the API
        $resp = $this->api->sendRequest('eqLogic::byType', array('type' => 'jMQTT'));
        $resp = $resp['result'];
        
        foreach($resp as $i => $eqpt) {
            $this->add($eqpt[self::KEY_NAME], $eqpt['object_id']);
        }
        
        self::copyValues($resp, $this->eqpts, false);
    }
    
    /**
     * Return the equipment array which name is the given one
     * @param string $name
     * @return array|NULL equipment (null if not found)
     */
    private function &getEqptFromName($name) {
        foreach($this->eqpts as $i => &$eqpt) {
            if ($eqpt[self::KEY_NAME] === $name) {
                return $eqpt;
            }
        }
        return null;
    }
    
    /**
     * Return a simple array with the equipment name and display property
     * @return Array[string]
     */
    private function getEqptListDisplayProp() {
        $ret = array();
        foreach($this->eqpts as $i => $eqpt) {
            $ret[] = array(self::KEY_NAME => $eqpt[self::KEY_NAME],
                self::KEY_AUTO_ADD_CMD => $eqpt['configuration'][self::KEY_AUTO_ADD_CMD] == '0' ? false : true);
        }
        return $ret;
    }
    
    /**
     * Copy values from the src array to the dest array
     * Only keys defined in the src array are treated if $keys_from_src is true
     * Only keys defined in the dest array are treated if $keys_from_src is false
     * @param array $src source array
     * @param array $dest destination array
     * @param bool $keys_from_src
     */
    private static function copyValues(array $src, array &$dest, bool $keys_from_src=true) {
        if ($keys_from_src) {
            foreach($src as $key => $val) {
                if (is_array($val)) {
                    self::copyValues($src[$key], $dest[$key], $keys_from_src);
                }
                else
                    $dest[$key] = $src[$key];
            }
        }
        else {
            foreach($dest as $key => $val) {
                if (is_array($val)) {
                    self::copyValues($src[$key], $dest[$key], $keys_from_src);
                }
                else
                    $dest[$key] = $src[$key];
            }
        }
    }
    
    /**
     * Set the eqLogic_id of all commands
     */
    private function setCmdEqLogicId() {
        foreach($this->eqpts as $eqpt_id => &$eqpt) {
            if (array_key_exists(self::KEY_CMDS, $eqpt)) {
                foreach($eqpt[self::KEY_CMDS] as $cmd_id => &$cmd) {
                    $cmd['eqLogic_id'] = $eqpt['id'];
                }
            }
        }
    }
    
    /**
     * Make the $sub array containing the same keys as the $ref array, and
     * fill the 'id' field in $ref.
     * Keys from $sub that are not present in $ref are suppressed.
     * Function is recursive.
     * @param array $ref
     * @param array $sub
     */
    private static function alignArrayKeysAndGetId(array &$ref, array &$sub) {
        foreach($sub as $key => $val) {
            if ($key === 'id' && array_key_exists($key, $ref))
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