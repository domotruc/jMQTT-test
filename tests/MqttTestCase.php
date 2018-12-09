<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttApiClient.class.php';
include_once 'PluginTestCase.php';

use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverSelect as Select;
use Facebook\WebDriver\Exception;


class MqttTestCase extends PluginTestCase {

    protected static $apiClient;
    
    public function __construct($name = null, array $data = [], $dataName = '', string $plugin_id = '') {
        parent::__construct($name, $data, $dataName, 'jMQTT');
    }
    
    /**
     * Connect jeedom and point the browser to the plugin management page
     * Initialise the apiClient object
     * @throws \Exception
     */
    public static function setUpBeforeClass() {
        parent::setUpBeforeClass('jMQTT');
        self::$apiClient = new MqttApiClient($_ENV['mosquitto_client_id'], $_ENV['mosquitto_host'],
                $_ENV['mosquitto_port']);
    }
    
    /**
     * Test no equipment is shown in the jMQTT plugin page
     */
    public function assertNoEqpt() {
        // Expected array is empty : check the container height is null (to avoid findElements waiting for nothing)
        //$els = self::$wd->findElements(By::xpath("//div[contains(@class,'eqLogicThumbnailContainer')]"));
        $els = $this->waitElemsAreVisible(By::xpath("//div[contains(@class,'eqLogicThumbnailContainer')]"));
        $this->assertCount(2, $els, 'Page should have 2 eqLogicThumbnailContainer');
        $this->assertEquals(0, $els[1]->getSize()->getHeight(), 'eqpt eqLogicThumbnailContainer is not empty');
    }
    
    /**
     * Test the given list of equipment is shown on the jMQTT plugin page
     */
    public function assertEqptList(array $list = array()) {
        if (count($list) == 0) {
            $this->assertNoEqpt();
        }
        else {
            // We may have to wait for equipement inclusion
            $by = By::xpath("//div[contains(@class,'eqLogicDisplayCard')]");
            //$by = By::xpath("//div[contains(@class,'eqLogicDisplayCard')]//center//strong");
            $els = self::$wd->findElements($by);
            for($i=0 ; $i<10 ; $i++) {
                if (count($list) == count($list))
                    break;
                usleep(100000);
                $els = self::$wd->findElements($by);
            } 
            $this->assertCount(count($list), $els, 'Equipment number is incorrect');

            $els_name = self::$wd->findElements(By::xpath("//div[contains(@class,'eqLogicDisplayCard')]//center//strong"));
            usort($els_name, function($a, $b) {
                return MqttEqpts::my_strcmp(trim($a->getAttribute("innerText")), trim($b->getAttribute("innerText")));
            });
            
            foreach ($list as $key => $prop) {

                if ($prop[MqttEqpts::KEY_AUTO_ADD_CMD])
                    $this->assertContains('auto', $els[$key]->getAttribute('class'));
                else
                    $this->assertNotContains('auto', $els[$key]->getAttribute('class'));

                // Note: do not use getText see https://stackoverflow.com/questions/20888592/gettext-method-of-selenium-chrome-driver-sometimes-returns-an-empty-string    
                $this->assertEquals($prop[MqttEqpts::KEY_NAME], trim($els_name[$key]->getAttribute("innerText")));
            }
        }
    }
    
    /**
     * Start page spec: no condition
     * End page: page of the given equipment
     * @param string $name
     */
    public function gotoEqptPage(string $name) {
        $this->gotoPluginMngt();
        $this->waitElemIsClickable(By::xpath("//div[contains(@class,'eqLogicDisplayCard')]//strong[contains(text(),'" . $name . "')]"))->click();
    }
    
    /**
     * Delete the given equipment
     * Goto the plugin equipment page first, and return back to that page
     * @param string $name
     */
    public function deleteEqpt(string $name) {
        $this->gotoEqptPage($name);
        $this->waitElemIsClickable(By::xpath("//a[@data-action='remove']"))->click();
        $this->waitElemIsClickable(By::xpath("//button[@data-bb-handler='confirm']"))->click();
        $this->waitElemIsVisible(By::xpath("//span[text()='Suppression effectuée avec succès']"));
        self::$wd->navigate()->refresh();
    }
    
    /**
     * Delete all equipments and check the equipment page is empty
     * Start page spec: no condition
     * End page: page of the given equipment
     */
    public function deleteAllEqpts() {
        $this->gotoPluginMngt();
        self::disableIncludeMode();
        
        do {
            $els = self::$wd->findElements(By::xpath("//div[contains(@class,'eqLogicDisplayCard')]//center//strong"));
            foreach ($els as $id => $el) {
                //print('Removing ' . trim($el->getAttribute("innerText")) . PHP_EOL);
                $el->click();
                $this->waitElemIsClickable(By::xpath("//a[@data-action='remove']"))->click();
                $this->waitElemIsClickable(By::xpath("//button[@data-bb-handler='confirm']"))->click();
                $this->waitElemIsVisible(By::xpath("//span[text()='Suppression effectuée avec succès']"));
                self::$wd->navigate()->refresh();
                break;
            }
        }
        while (count($els) > 1);
        
        // Check jMQTT page does not contain any equipment
        $this->assertNoEqpt();
    }
    
    /**
     * Set the auto command adding flag
     * Start page spec: the equipment page
     * End page: the equipment page
     * @param bool $is_enabled
     */
    public function setAutoCmdAdding(bool $is_enabled) {
        $el = $this->waitElemIsClickable(By::xpath("//input[@data-l2key='auto_add_cmd']"));
        if ($el->isSelected() && ! $is_enabled)
            $el->click();
        if ( ! $el->isSelected() && $is_enabled)
            $el->click();
        self::$wd->findElement(By::xpath("//a[@data-action='save']"))->click();
    }
    
    public function waitEquipmentInclusion(string $name) {
        $this->waitElemIsVisible(By::xpath("//div[contains(@class,'eqLogicDisplayCard')]//strong[contains(text(),'" . $name . "')]"));
    }
    
    /**
     * Enable or disable the jMQTT API
     * @param bool $isEnable
     */
    public function setMqttApi(bool $isEnable) {
        $val = $isEnable ? 'enable' : 'disable';
        $this->gotoPluginConfPanel();
        $el = $this->waitElemIsClickable(By::xpath("//select[@data-l1key='api']"));
        if ($el->getAttribute('value') != $val) {
            (new Select($el))->selectByValue($val);
            $this->waitForDeamonRestartDelay();
            self::$wd->findElement(By::id('bt_savePluginConfig'))->click();
            $this->waitElemIsVisible(By::id('div_alertPluginConfiguration'));
        }
    }
    
    /**
     * Activate and check the API
     */
    public function activateAndAssertAPI() {
        
        // Send a ping to the API
        $resp = self::$apiClient->sendRequest('ping', array());
        
        if (!isset($resp) || array_key_exists('error', $resp)) {
                      
            // Activate the API in jMQTT if not activated
            $this->setMqttApi(true);

            // Activate also the JSON RPC API
            $this->setJsonRpcApi(true);
            $resp = self::$apiClient->sendRequest('ping', array());
        }
        
        $this->assertEquals("pong", $resp['result']);
    }
    
    /**
     * Assert the include button is in the given state
     *
     * @param $is_include bool
     *            expected state
     * @return WebDriverElement the include mode button
     */
    protected function assertIncludeMode(bool $is_include) {
        $incl_bt = self::$wd->findElement(By::className('bt_changeIncludeMode'));
        //$incl_bt_text = self::$wd->findElements(By::xpath("//div[contains(@class,'changeIncludeMode')]//span//center"));
        if ($is_include) {
            $this->assertContains('include', $incl_bt->getAttribute('class'));
            $this->assertEquals("Arrêter l'inclusion", $incl_bt->getText());
        }
        else {
            $this->assertNotContains('include', $incl_bt->getAttribute('class'));
            $this->assertEquals("Mode inclusion", $incl_bt->getText());
        }
        return $incl_bt;
    }
    
    protected function enableIncludeMode() {
        $incl_bt = self::$wd->findElement(By::className('bt_changeIncludeMode'));
        if (strpos($incl_bt->getAttribute('class'), 'include') === false)
            $incl_bt->click();
    }
    
    protected function disableIncludeMode() {
        $incl_bt = self::$wd->findElement(By::className('bt_changeIncludeMode'));
        if (strpos($incl_bt->getAttribute('class'), 'include') !== false)
            $incl_bt->click();
    }
    
    protected function logDbg($msg) {
        if (in_array('--debug', $_SERVER ['argv'], true)) {
            print_r($msg);
        }
    }
}

?>
