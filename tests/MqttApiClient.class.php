<?php

require_once (__DIR__ . '/../vendor/autoload.php');

use Bluerhinos\phpMQTT;

class MqttApiClient {

    const S_CLIENT_ID = 'jmqtt_test';
    const S_RET_TOPIC = self::S_CLIENT_ID . '/req';
    
    /**
     * @var phpMQTT
     */
    private $client;
   
    /**
     * @var string jeedom id the request shall be adressed to
     */
    private $jeedom_id;

    /**
     * @var integer api request id
     */
    private $req_id = 0;

    /**
     * @var array
     */
    private $response;
    
    /**
     * @var MqttEqpts reference equipment list
     */
    private $mqttEqpts;
    
    /**
     * @var string
     */
    private $previous_req = null;

    /**
     * @var string
     */
    private $previous_resp = null;
    
    
    /**
     * @var string
     */
    private $jeedom_version;
    
    /**
     * Create a mosquitto client and connect to the broker
     */
    function __construct($jeedom_id, $host = 'localhost', $port = 1883) {
        $this->jeedom_id = $jeedom_id;

        // Configure and connect MQTT client
        $this->client = new phpMQTT($_ENV['mosquitto_host'], $_ENV['mosquitto_port'], self::S_CLIENT_ID);
        $this->client->keepalive = 120;
        ; // new \Mosquitto\Client(self::S_CLIENT_ID);
        if ($this->client->connect() === false)
            throw new \Exception('Cannot connect to broker ' . $_ENV['mosquitto_host'] . ':' . $_ENV['mosquitto_port']);

        $this->client->subscribe(
            array(self::S_RET_TOPIC => array("qos" => 0,"function" => array($this,'mqttMessage')),0));

        // $this->client->onMessage(array($this, 'mosquittoMessage'));
        // $this->client->connect($host, $port);
        // $this->client->subscribe(self::S_RET_TOPIC, 0);
    }
    
    
    /**
     * Set the reference eqpt list
     * So that this api object can refresh the api command in the $jeedom_id equipment
     * @param MqttEqpts $mqttEqpts
     */
    public function setMqttEqpts(MqttEqpts $mqttEqpts) {
        $this->mqttEqpts = $mqttEqpts;        
    }
    
    /**
     * Mosquitto callback called each time a subscribed topic is dispatched by the broker.
     * Decode the received JSON response as an array
     * Add the response to the S_CLIENT_ID equipment if it exists
     * @param string $topic
     * @param string $msg
     * @return array JSON decoded message 
     */
    public function mqttMessage($topic, $msg) {
        $this->response = json_decode($msg, true);
        
        // add the response to the S_CLIENT_ID equipment if it exists
        if (isset($this->mqttEqpts) && $this->mqttEqpts->exists(self::S_CLIENT_ID)) {
            $this->mqttEqpts->setCmd(self::S_CLIENT_ID, $topic, $this->previous_resp);
            $this->previous_resp = $msg;
        }
    }

    /**
     * @param string $method
     * @param array|null $params request parameters (or null or absent if no parameters)
     * @return NULL|string
     */
    public function sendRequest($method, array $params=null) {
        
        // Conversion between 3.2 and 3.3 version
        $conv = array('object::all' => 'jeeObject::all');
        
        if (array_key_exists($method, $conv)) {
            if ($this->getJeedomVersion() == '3.3')
                $method = $conv[$method];
        }
        
        return $this->processRequest($method, $params);
    }
    
    /**
     * @param string $method
     * @param array|null $params request parameters (or null or absent if no parameters)
     * @return NULL|string
     */
    private function processRequest($method, array $params=null) {
        
        $req = array('method' => $method,
                     'id' => strval($this->req_id++),
                     'topic' => self::S_RET_TOPIC);
        
        if (isset($params)) {
            $req['params'] = $params;
        }

        $this->response = null;

        // send the request
        $topic = $this->jeedom_id . '/api';
        $req = json_encode($req);
        $this->client->proc();
        $this->client->publish($topic, $req);
        
        // add the request to the jeedom_id equipment if it exists
        if (isset($this->mqttEqpts) && $this->mqttEqpts->exists($this->jeedom_id)) {
            $this->mqttEqpts->setCmd($this->jeedom_id, $topic, $this->previous_req);
            $this->previous_req = $req;
        }

        // wait for the answer
        for ($i = 0; $i <= 50; $i++) {
            $this->client->proc();
            if (isset($this->response))
                break;
        }
                    
        return $this->response;
    }
    
    public function getJeedomVersion() {
        if (! isset($this->jeedom_version)) {
            $resp = $this->processRequest('version');
            $this->jeedom_version = substr($resp['result'], 0, 3);
        }
        return $this->jeedom_version;
    }
}
