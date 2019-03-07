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
     * @var array
     */
    private $prev_req;
    
    /**
     * @var array
     */
    private $prev_resp;
    
    /**
     * @var array &$add_msg
     */
    private $add_msg;
    
    /**
     * @var MqttEqpts reference equipment list
     */
    private $mqttEqpts;
      
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
     * @param string $payload
     */
    public function mqttMessage($topic, $payload) {
        if (isset($this->add_msg) && $this->add_msg['topic'] == $topic) {
            $this->add_msg['payload'] = $payload;
        }
        else {
            $this->response = json_decode($payload, true);
        
            // add the response to the S_CLIENT_ID equipment if it exists
            if (isset($this->mqttEqpts) && $this->mqttEqpts->exists(self::S_CLIENT_ID)) {
                $this->mqttEqpts->setCmdInfo(self::S_CLIENT_ID, $topic, $this->prev_resp);
                $this->prev_resp = $payload;
            }
        }
    }

    /**
     * Publish an MQTT request, wait for response and return it.
     * If $add_msg is set, wait also for an additional message and return the received payload.
     *  
     * @param string $method
     * @param array $params request parameters (empty array by default)
     * @param array &$add_msg null by default
     *      $add_msg['topic']: topic to listen (shall be unique topic i.e. shall not contain any wilcards # or +)
     *      $add_msg['payload']: received payload 
     * @return NULL|string
     */
    public function sendRequest($method, array $params=array(), array &$add_msg=null) {
        
        $this->add_msg = &$add_msg;
        
        // Conversion between 3.2 and 3.3 version
        $conv = array('object::all' => 'jeeObject::all');
        
        if (array_key_exists($method, $conv)) {
            if ($this->getJeedomVersion() == '3.3')
                $method = $conv[$method];
        }

        $subscription_array = array(self::S_RET_TOPIC => array("qos" => 0, "function" => array($this,'mqttMessage')));
        if (isset($add_msg)) {
            $subscription_array[$add_msg['topic']] = array("qos" => 0, "function" => array($this,'mqttMessage'));
            $add_msg['payload'] = null;
        }
        $this->client->subscribe($subscription_array, 0);
        
        $this->response = null;
        
        return $this->processRequest($method, $params, $add_msg);
    }
    
    /**
     * Always call MqttApiClient::sendRequest
     * @see MqttApiClient::sendRequest
     */
    private function processRequest($method, array $params=array(), array &$add_msg=null) {
        
        $req = array('method' => $method,
                     'id' => strval($this->req_id++),
                     'topic' => self::S_RET_TOPIC);
        
        if (!empty($params)) {
            $req['params'] = $params;
        }

        // send the request
        $topic = $this->jeedom_id . '/api';
        $req = json_encode($req);
        $this->client->proc();
        $this->client->publish($topic, $req);
        
        // add the request to the jeedom_id equipment if it exists
        if (isset($this->mqttEqpts) && $this->mqttEqpts->exists($this->jeedom_id)) {
            $this->mqttEqpts->setCmdInfo($this->jeedom_id, $topic, $this->prev_req);
            $this->prev_req = $req;
        }

        // wait for the answer
        for ($i = 0; $i <= 50; $i++) {
            $this->client->proc();
            if (isset($this->response) && (!isset($this->add_msg) || isset($this->add_msg['payload'])))
                break;
        }
                    
        return $this->response;
    }
    
    public function getJeedomVersion() {
        if (! isset($this->jeedom_version)) {
            $resp = $this->sendRequest('version');
            $this->jeedom_version = substr($resp['result'], 0, 3);
        }
        return $this->jeedom_version;
    }
}
