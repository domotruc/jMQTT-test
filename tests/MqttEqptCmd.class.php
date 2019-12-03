<?php

include_once 'Util.class.php';

class MqttEqptCmd implements JsonSerializable {
    
    private const KEY_NAME = 'name';
    private const KEY_ID = Util::KEY_ID;
    private const KEY_CONFIGURATION = 'configuration';
    private const KEY_TYPE = 'type';
    private const KEY_SUBTYPE = 'subType';
    private const KEY_TOPIC = 'topic';
    private const KEY_ORDER = 'order';
    private const KEY_EQLOGIC_ID = 'eqLogic_id';
    private const KEY_UNIT = 'unite';
    private const KEY_IS_HISTORIZED = 'isHistorized';
    private const KEY_IS_VISIBLE = 'isVisible';
    private const KEY_CURRENT_VALUE = 'currentValue';
    
    public const TYP_ACTION = 'action';
    public const TYP_INFO = 'info';
    
    public const SUBTYP_OTHER = 'string';
    
    
    /**
     * Data of this command
     * @var array[string]
     */
    private $data;
    
    /**
     * Create an empty command
     * @return MqttEqptCmd
     */
    private function __construct() {
        $this->data = json_decode(file_get_contents(__DIR__ . '/default_cmd.json'), true);
        //        if ($this->api->getJeedomVersion() == '3.3') {
        //             foreach($cmd as $id => &$v) {
        //                 if (is_null($v))
        //                     $v = false;
        //             }
        //        }
    }
    
    /**
     * Create a command with the given parameters
     * @param string $cmdName
     * @param string $topic
     * @param string $eqLogicId id of the eqLogic this command belongs to
     * @param string $val current value (default: null). string 'null' is converted into null.
     * @param string $type MqttEqptCmd::TYP_INFO (default value) or MqttEqptCmd::TYP_ACTION
     * @param string $subtype MqttEqptCmd::SUBTYP_OTHER (default value)
     * @return MqttEqptCmd
     */
    public static function new($cmdName, $topic, $eqLogicId, $val=null, $type=MqttEqptCmd::TYP_INFO, $subtype=MqttEqptCmd::SUBTYP_OTHER) {
        $cmd = new MqttEqptCmd();
        $cmd->setType($type);
        $cmd->setEqLogicId($eqLogicId);
        $cmd->data[self::KEY_SUBTYPE] = $subtype;
        $cmd->update($cmdName, $topic, $val);
        return $cmd;
    }
    
    private static function newFromData(array $dataArray) {
        $cmd = new MqttEqptCmd();
        Util::copyValues($dataArray, $cmd->data, false);
        // To correct the value if necessary
        $cmd->setCurrentValue($cmd->getCurrentValue());
        return $cmd;
    }
      
    /**
     * Update this command
     * @param array $cmd the command to update
     * @param string $cname
     * @param string $topic
     * @param string $val
     * @return array $cmd is returned
     */
    public function update($cname, $topic, $val) {
        $this->setName($cname);
        $this->setTopic($topic);
        
        # For the moment, we suppose that all data are string
        $type = gettype($val);
        if ($type == 'integer' || $type == 'double') {
            $val = strval($val);
        }
        $this->setCurrentValue($val);
        $this->data['value'] = ($this->data['type'] == 'info') ? null: '';
    }
    
    public static function createCmdArray(array $cmdsData) {
        $cmds = array();
        foreach($cmdsData as $cmdData) {
            $cmds[] = self::newFromData($cmdData);
        }
        return $cmds;
    } 
    
    /**
     * Return whether or not the given $key/$value data exists in the given array of MqttEqptCmd objects
     * @param array[MqttEqptCmd] $cmds
     * @param string $key
     * @param string $value
     * @return boolean
     */
    public static function inArrayByKeyValue(array $cmds, string $key, string $value) {
        foreach($cmds as $cmd) {
            if ($value == $cmd->data[$key])
                return true;
        }
        return false;
    }
    
    /**
     * Align the id of each command of the $dest array on the $src array if not yet defined
     * @param array[MqttEqptCmd] $src
     * @param array[MqttEqptCmd] $dest
     */
    public static function alignId(array $src, array  $dest) {
        for($i=0 ; $i<count($src) ; $i++) {
            if (empty($dest[$i]->getId()))
                $dest[$i]->setId($src[$i]->getId());
        }
    }
    
    /**
     * Reset the value of the commands that have no id in each given array
     * @param array[MqttEqptCmd] $src
     * @param array[MqttEqptCmd] $dest
     */
    public static function resetFalseCommandValues(array $src, array  $dest) {
        for($i=0 ; $i<count($src) ; $i++) {
            if (empty($src[$i]->getId()) && empty($dest[$i]->getId())) {
                $src[$i]->setCurrentValue('');
                $dest[$i]->setCurrentValue('');
            }
        }
    }
    
    /**
     * Return the command having the given topic in the given array of MqttEqptCmd objects, or null if not found.
     * @param array[MqttEqptCmd] $cmds
     * @param string $topic
     * @return null|MqttEqptCmd
     */
    public static function byTopic(array $cmds, string $topic) {
        return self::byKeyValue($cmds, self::KEY_TOPIC, $topic);
    }
    
    /**
     * Return the command having the given name in the given array of MqttEqptCmd objects, or null if not found.
     * @param array[MqttEqptCmd] $cmds
     * @param string $name
     * @return null|MqttEqptCmd
     */
    public static function byName(array $cmds, string $name) {
        return self::byKeyValue($cmds, self::KEY_NAME, $name);
    }
    
    private static function byKeyValue(array $cmds, string $key, $val) {
        foreach($cmds as $cmd) {
            if ($val == $cmd->data[self::KEY_CONFIGURATION][$key])
                return $cmd;
        }
        return null;
        
    }
    
    /**
     * Return whether the order parameter of the commands in the given array of MqttEqptCmd objects is set or not
     * @param array[MqttEqptCmd] $cmds
     * @return bool
     */
    public static function isCmdOrdersSet(array $cmds) {
        foreach($cmds as $cmd) {
            if ($cmd->getOrder() != 0 || $cmd->getOrder() != '0') {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Set the order configuration parameter of all the commands of the given array of MqttEqptCmd objects
     * @param array[MqttEqptCmd] $cmds
     * @param array[MqttEqptCmd]|string|null $ref
     */
    public static function setCmdOrders(array &$cmds, $ref=null) {
        for($i=0 ; $i<count($cmds) ; $i++) {
            if (is_array($ref)) {
                $val = $ref[$i]->getOrder();
            }
            else {
                $val = isset($ref) ? $ref : strval($i);
            }
            $cmds[$i]->setOrder($val);
        }
    }
    
    
    /**
     * Return the command name as automatically built by the plugin
     * @param string $eqptTopic
     * @param string $cmdTopic
     * @return string
     */
    public static function getAutomaticCmdName(string $eqptTopic, string $cmdTopic) {
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
        
        return str_replace("/", ":", $cmdName);
    }
       
    public function getType() {
        return $this->data[self::KEY_TYPE];
    }
    
    public function setType($type) {
        $this->data[self::KEY_TYPE] = $type;
    }
    
    public function getTopic() {
        return $this->data[self::KEY_CONFIGURATION][self::KEY_TOPIC];
    }
    
    public function setTopic($topic) {
        $this->data[self::KEY_CONFIGURATION][self::KEY_TOPIC] = $topic;
    }
    
    public function getId() {
        return $this->data[self::KEY_ID];
    }
    
    public function setId(string $id) {
        $this->data[self::KEY_ID] = $id;
    }
    
    public function setIdIfEmpty(string $id) {
        if (empty($this->data[self::KEY_ID])) {
            $this->data[self::KEY_ID] = $id;
        }
    }
    
    public function getName() {
        return $this->data[self::KEY_NAME];
    }
    
    public function setName(string $name) {
        $this->data[self::KEY_NAME] = $name;
    }
    
    public function getOrder() {
        return $this->data[self::KEY_ORDER];
    }
    
    public function setOrder(string $order) {
        $this->data[self::KEY_ORDER] = $order;
    }
    
    public function getCurrentValue() {
        return $this->data[self::KEY_CURRENT_VALUE];
    }
    
    public function setCurrentValue($val) {
        if ($val === 'null') {
            $val = null;
        }
        if ($val === 'true') {
            $val = true;
        }
        if ($val === 'false') {
            $val = false;
        }
        $this->data[self::KEY_CURRENT_VALUE] = $val;
    }
    
    public function setUnit(string $unit) {
        $this->data[self::KEY_UNIT] = $unit;
    }
    
    public function getEqLogicId() {
        return $this->data[self::KEY_EQLOGIC_ID];
    }
    
    public function setEqLogicId(string $eqLogicId) {
        $this->data[self::KEY_EQLOGIC_ID] = $eqLogicId;
    }
    
    public function setIsHistorized(string $isHistorized) {
        $this->data[self::KEY_IS_HISTORIZED] = $isHistorized;
    }
    
    public function setIsVisible(string $isVisible) {
        $this->data[self::KEY_IS_VISIBLE] = $isVisible;
    }
    
    public function jsonSerialize() {
        return $this->data;
    }
}
    