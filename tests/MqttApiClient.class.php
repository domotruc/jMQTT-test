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
     * @var string broker name
     */
    private $bname;
    
    /**
     * To avoid creating clients with the same mqtt id at broker level that would
     * disconnect themselves each others 
     * @var integer client id number 
     */
    private static $client_id = 1;
    
    /**
     * @var MqttApiClient[]
     */
    private static $apis = array();
    
    /**
     * @var bool
     */
    private $is_connected = false;
   
    /**
     * @var string $jeedom_id jeedom id the request shall be adressed to
     */
    private $jeedom_id;
    
    /**
     * @var string $api_topic
     */
    private $api_topic;

    /**
     * @var integer api request id
     */
    private static $req_id = 0;

    /**
     * @var array
     */
    private $response;
    
    /**
     * @var array
     */
    private $prev_req;
    
    /**
     * @var string
     */
    private $prev_resp;

    /**
     * @var array &$add_msg
     */
    private $add_msg;
    
    /**
     * @var iBroker $iBroker interface with the broker linked to this API
     */
    private $iBroker;
      
    /**
     * @var string
     */
    private $jeedom_version;
    
    /**
     * Create a mosquitto client and connect to the broker
     * @param iBroker $iBroker interface to update information commands of the broker, so that
     * this api object can refresh the api command in the broker equipment.
     */
    function __construct(string $bname, string $jeedom_id, string $host, int $port, iBroker $iBroker=null) {
        $this->bname = $bname;
        $this->jeedom_id = $jeedom_id;
        $this->api_topic = $jeedom_id . '/api';
        $this->iBroker = $iBroker;
        self::$apis[] = $this;

        // Configure and connect MQTT client
        $this->client = new phpMQTT($host, $port, self::S_CLIENT_ID . self::$client_id++);
        $this->client->keepalive = 120;
    }
       
    /**
     * Mosquitto callback called each time a subscribed topic is dispatched by the broker.
     * Decode the received JSON response as an array
     * Add the response to the S_CLIENT_ID equipment if it exists
     * @param string $topic
     * @param string $payload
     */
    public function mqttMessage(string $topic, string $payload) {
        if (isset($this->add_msg) && $this->add_msg['topic'] == $topic) {
            $this->add_msg['payload'] = $payload;
        }
        else {
            $this->response = json_decode($payload, true);
            $this->prev_resp = $payload;
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
    public function sendRequest(string $method, array $params=array(), array &$add_msg=null) {
        
        $this->add_msg = &$add_msg;
        
        if (! $this->is_connected) {
            if ($this->client->connect() === false)
                throw new \Exception('Cannot connect to broker ' . $this->client->address . ':' . $this->client->port);
            else
                $this->is_connected = true;
        }
        else {
            $this->client->proc();
        }
            
        // Conversion between 3.2 and 3.3 version
        $conv = array('object::all' => 'jeeObject::all');
        
        if (array_key_exists($method, $conv)) {
            if ($this->getJeedomVersion() == '3.3')
                $method = $conv[$method];
        }

        $subscription_array = array(
            self::S_RET_TOPIC => array(
                "qos" => 0,
                "function" => array($this,'mqttMessage')
            ));
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
                     'id' => strval(self::$req_id++),
                     'topic' => self::S_RET_TOPIC);
        
        if (!empty($params)) {
            $req['params'] = $params;
        }

        // update api request answer commands (for all brokers)
        self::updateApiRequestAnswerCommands();
        
        // send the request
        $req = json_encode($req);
        $this->client->proc();
        $this->client->publish($this->api_topic, $req);
        
        // update api request commands (for all brokers)
        self::updateApiRequestCommands();
        $this->prev_req = $req;
        
        // wait for the answer
        for ($i = 0; $i <= 50; $i++) {
            $this->client->proc();
            if (isset($this->response) && (!isset($this->add_msg) || isset($this->add_msg['payload'])))
                break;
        }
                    
        return $this->response;
    }
    
    public static function updateApiRequestAnswerCommands() {
        foreach (self::$apis as $api) {
            if (isset($api->prev_resp) && isset($api->iBroker) && $api->iBroker->existsByName($api->bname, self::S_CLIENT_ID)) {
                $api->iBroker->setCmdInfo($api->bname, self::S_CLIENT_ID, self::S_RET_TOPIC, $api->prev_resp);
                $api->prev_resp = null;
            }
        }
    }
    
    public static function updateApiRequestCommands() {
        foreach (self::$apis as $api) {
            if (isset($api->prev_req) && isset($api->iBroker) && $api->iBroker->existsByName($api->bname, $api->bname)) {
                $api->iBroker->setCmdInfo($api->bname, $api->bname, $api->api_topic, $api->prev_req);
                $api->prev_req = null;
            }
        }
    }
    
    public function getJeedomVersion() {
        if (! isset($this->jeedom_version)) {
            $resp = $this->sendRequest('version');
            $this->jeedom_version = substr($resp['result'], 0, 3);
        }
        return $this->jeedom_version;
    }
    
    public function getBrokerName() {
        return $this->bname;
    }
}
