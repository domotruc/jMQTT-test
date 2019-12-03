<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttApiClient.class.php';
include_once 'PluginTestCase.php';
include_once 'MqttEqpts.class.php';

use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverSelect as Select;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverAction;
use Facebook\WebDriver\Interactions\WebDriverActions;


class MqttTestCase extends PluginTestCase {
    
    public const TAB_EQPT = "eqpt";
    public const TAB_BRK = "brk";
    public const TAB_CMD = "cmd";    

    public const VIEW_JSON = "json";
    public const VIEW_CLASSIC = "classic";
    
    
    public function __construct($name = null, array $data = [], $dataName = '', string $plugin_id = '') {
        parent::__construct($name, $data, $dataName, 'jMQTT');
    }
    
    /**
     * Connect jeedom
     * Check mosquitto libray is loaded
     */
    public static function setUpBeforeClass() {
        parent::setUpBeforeClass('jMQTT');
        //if (! extension_loaded('mosquitto'))
        //    throw new Exception("PHP Mosquitto library is not installed (see https://mosquitto-php.readthedocs.io/en/latest/index.html)");
    }
    
    /**
     * Test no broker, no equipment is shown in the jMQTT plugin page
     */
    public function assertNoBroker() {
        $els = $this->waitElemsAreVisible(By::xpath("//div[contains(@class,'eqLogicThumbnailContainer')]"));
        $this->assertCount(1, $els, 'Page should only have 1 eqLogicThumbnailContainers');
        
        $this->assertCount(3, $els[0]->findElements(By::xpath(".//div")), 'Gestion section should only have 3 elements');
        $this->assertBrokerList(array());
    }
    
    /**
     * Test the given list of equipments is shown on the jMQTT plugin page
     * @param array $list list of equipments; first one shall be the broker
     */
    public function assertEqptList(array $list = array()) {
        if (count($list) == 0) {
            $this->assertNoBroker();
        }
        else {
            $this->assertBrokerList($list);
            foreach($list as $eqpts)
                $this->assertBrokerEqptList($eqpts);
        }
    }
    
    /**
     * @param array $list
     */
    private function assertBrokerEqptList(array $list) {
        $div = $this->getBrokerModulesDiv($list[0][MqttEqpts::KEY_NAME]);
        
        if (count($list) < 2) {
            $this->assertCount(2, $div->findElements(By::xpath(".//div")), 'Module section should only have 2 equipments');
        }
        else {
            // We may have to wait for equipement inclusion
            /** @var WebDriverElement[] $els */
            $by = By::xpath(".//div[contains(@class,'eqLogicDisplayCard')]");
            for($i=0 ; $i<10 ; $i++) {        
                usleep(100000);
                $els = $div->findElements($by);
                if (count($list)-1 == count($els))
                    break;
            }
            $this->assertCount(count($list)-1, $els, 'Equipment number is incorrect');
            $i=0;
            for($i=1 ; $i<count($list) ; $i++) {
                $this->assertEqpt($els[$i-1], $list[$i]);
                $i++;
            }
        }
    }
    
    /**
     * @param array[string][string] $list @see MqttEqpts::getEqptListDisplayProp
     */
    private function assertBrokerList(array $list) {
        $div = $this->getBrokersDiv();
        
        if (empty($list)) {
            $this->assertCount(3, $div->findElements(By::xpath(".//div")), 'Gestion section should only have 3 elements');
        }
        else {
            $els = $div->findElements(By::xpath(".//div[contains(@class,'eqLogicDisplayCard')]"));
            $this->assertCount(count($list), $els, 'Broker number is incorrect');
            $i=0;
            foreach ($list as $eqpts) {
                $this->assertEqpt($els[$i], $eqpts[0]);
                $i++;
            }
        }
    }
    
    /**
     * @param WebDriverElement $div
     * @param array[string] $eqpt
     */
    private function assertEqpt($div, $eqpt) {
        $html = $div->getAttribute("innerHTML");
        if ($eqpt[MqttEqpts::CONF_AUTO_ADD_CMD]) {
            $this->assertContains('class="auto"', $html, 'Equipment card should show the auto icon');
        }
        else {
            $this->assertNotContains('class="auto"', $html, 'Equipment card should not show the auto icon');
        }
        
        if (array_key_exists(MqttEqpts::KEY_DAEMON_STATE, $eqpt))
            $this->assertContains('node_broker_' . $eqpt[MqttEqpts::KEY_DAEMON_STATE] . '.svg', $html);
                
        // Note: do not use getText see https://stackoverflow.com/questions/20888592/gettext-method-of-selenium-chrome-driver-sometimes-returns-an-empty-string
        $this->assertEquals($eqpt[MqttEqpts::KEY_NAME], $div->findElement(By::xpath(".//span//strong"))->getAttribute("innerText"));
    }
    
    /**
     * Assert the given command adding message is shown in the panel on top of the page
     * Close the message
     * @param string $msg
     * @throws Exception
     */
    private function assertDivAlertCmdMsg(string $msg) {
        // Retry twice to avoid the message:
        // Facebook\WebDriver\Exception\UnrecognizedExceptionException: Element
        // <span class="btn_closeAlert pull-right cursor" href="#"> is not clickable at point (1255,68) because another element
        // <div class="modal-backdrop fade"> obscures it
        //
        // Occurs when a dialog box is closed just before entering here and had no time to be closed
//         usleep(200000);
//         $retry = false;
//         do {
//             try {
                $el = $this->waitElemIsVisible(By::xpath("//div[@id='div_cmdMsg' and contains(@class,'alert-warning')]"));
                $this->assertEquals($msg, $el->findElement(By::xpath("./span[contains(@class,'displayError')]"))->getText());
                $button = $el->findElement(By::xpath("./span[contains(@class,'btn_closeAlert')]"));
                //self::$wd->executeScript("arguments[0].scrollIntoView();", $button);
                $button->click();
//             }
//             catch (Exception $e) {
//                 if ($retry) {
//                     throw $e;
//                 }
//                 usleep(500000);
//                 $retry = true;
//             }
//         } while ($retry);
    }
    
    /**
     * Check the alert message related to the addition of the given command(s) is displayed
     * @param string $ename equipement name
     * @param string|array[string] $cnames command name array (or command name for a single command)
     * @param bool $reload true if the page is to be reloaded
     */
    public function assertDivAlertNewCmdMsg(string $ename, $cnames, bool $reload=true) {
        if (!is_array($cnames))
            $cnames = array($cnames);
        $msg = count($cnames) > 1 ? "Plusieurs commandes sont ajoutées" : "La commande " . $cnames[0] . " est ajoutée";
        $msg .= " à l'équipement " . $ename . ".";
        if ($reload)
            $msg .= " La page va se réactualiser automatiquement.";
        $this->assertDivAlertCmdMsg($msg);
    }

    /**
     * Assert the given new equipment adding message is shown in the panel on top of the page
     * Close the message
     * @param string $msg
     */
    public function assertNewEqptMsg(string $msg) {
        $el = $this->waitElemIsVisible(By::xpath("//div[@id='div_newEqptMsg' and contains(@class,'alert-warning')]"));
        $this->assertEquals($msg, $el->findElement(By::xpath("./span[contains(@class,'displayError')]"))->getText());
        $el->findElement(By::xpath("./span[contains(@class,'btn_closeAlert')]"))->click();
    }
        
    /**
     * Start page spec: no condition
     * End page: page of the given broker
     * @param string $bname
     */
    public function gotoBrokerPage(string $bname) {
        $this->gotoPluginMngt();
        $this->waitElemIsClickable(By::xpath("//div[contains(@class,'eqLogicDisplayCard') and @jmqtt_type='broker']//strong[contains(text(),'" . $bname . "')]"))->click();
    }
        
    /**
     * Start page spec: no condition
     * End page: page of the given equipment
     * @param string $bname
     * @param string $name
     */
    public function gotoEqptPage(string $bname, string $name) {
        $this->gotoPluginMngt();
        $div = $this->getBrokerModulesDiv($bname);
        $div->findElement(
            By::xpath(".//div[contains(@class,'eqLogicDisplayCard')]//strong[contains(text(),'" . $name . "')]")
            )->click();
    }
    
    /**
     * Start page spec: page of the desired equipment
     * End page: unchanged
     */
    public function refreshEqptPage() {
        self::$wd->findElement(By::xpath("//a[@data-action='refreshPage']"))->click();
    }
    
    /**
     * Select the given equipment tab
     * Start page spec: page of the desired equipment
     * End page: page of the given equipment
     * @param string $tab among self::TAB_EQPT, self::TAB_BRK, self::TAB_CMD
     */
    public function gotoEqptTab(string $tab) {
        $conv = array(self::TAB_EQPT => '#eqlogictab', self::TAB_BRK => '#brokertab', self::TAB_CMD => '#commandtab');
        $this->waitElemIsClickable(By::xpath("//a[@href='" . $conv[$tab] . "']"))->click();
    }
    
    /**
     * Select the given command view
     * Start page spec: command tab of the given equipment
     * End page: command tab of the given equipment, given view
     * @param string $view among self::VIEW_CLASSIC, self::VIEW_JSON
     */
    public function setCmdView(string $view) {
        $webElems = $this->waitElemsAreVisible(By::xpath("//table[@id='table_cmd']/tbody/tr"));
        $this->waitElemIsVisible(By::xpath("//*[@id='bt_" . $view . "']"))->click();
        if (count($webElems) > 0)
            $this->waitElemIsStale($webElems[0]);
    }
    
    /**
     * Return which command view is displayed
     * Start page spec: command tab of the given equipment
     * End page: command tab of the given equipment, given view
     * @return string self::VIEW_CLASSIC, self::VIEW_JSON
     */
    public function getCmdView() {
        $c = $this->waitElemIsVisible(By::xpath("//a[@id='bt_classic']"))->getAttribute("class");
        return strpos($c, 'active') === false ? self::VIEW_JSON : self::VIEW_CLASSIC;
    }
    
    /**
     * Delete the given equipment
     * Goto the plugin equipment page first, and return back to that page
     * Start page spec: no condition
     * End page: pluginn equipment page
     * @param string $bname broker name
     * @param string $name equipment name
     */
    public function deleteEqpt(string $bname, string $name) {
        $this->gotoEqptPage($bname, $name);
        $this->waitElemIsClickable(By::xpath("//a[@data-action='remove_jmqtt']"))->click();
        $this->clickDialogBoxConfirm();
        $this->assertDivAlertSuccessDelete();
        self::$wd->navigate()->refresh();
    }
        
    /**
     * Delete all brokers and check the equipment page is empty
     * Start page spec: no condition
     * End page: page of the given equipment
     */
    public function deleteAllBrokers() {
        $this->gotoPluginMngt();
        
        do {
            $div_brokers = self::$wd->findElements(By::xpath("//div[contains(@class,'eqLogicThumbnailContainer')]"))[0];
            $nb_div = count($div_brokers->findElements(By::xpath(".//div")));
            if ($nb_div > 3 ) {          
                $div_brokers->findElement(By::xpath(".//div[contains(@class,'eqLogicDisplayCard')]"))->click();
                $this->waitElemIsClickable(By::xpath("//a[@data-action='remove_jmqtt']"))->click();
                $this->clickDialogBoxConfirm();
                $this->clickDialogBoxConfirm();
                $this->assertDivAlertSuccessDelete();
            }
        }
        while ($nb_div > 3);
        
        // Check jMQTT page does not contain any equipment
        //self::$wd->navigate()->refresh();
        $this->assertNoBroker();
    }
    
    /**
     * Add a jMQTT equipment
     * Start page spec: no condition
     * End page: page of the created equipment
     * @param string $bname broker name
     * @param string $name equipment name
     * @param bool $save whether or not equiment shall be saved
     */
    public function addEqpt(string $bname, string $name) {
        $this->gotoPluginMngt();
        $this->waitElemIsClickable(By::xpath(
            "//legend[text()=' Equipements connectés à " . $bname  . "']//following-sibling::div" .
            "//div[contains(@class,'eqLogicDisplayAction') and contains(@data-action,'add_jmqtt')]"))->click();
        $this->waitElemIsVisible(By::xpath("//input[contains(@class,'bootbox-input-text')]"))->sendKeys($name);
        $this->clickDialogBoxConfirm();
        $this->assertDivAlertSuccessSave();
    }
    
    /**
     * Add all brokers (defined in the boostrap test file) through the UI 
     */
    public function addBrokers() {
        $this->gotoPluginMngt();
        foreach ($_ENV['brokers'] as $broker) {
            $this->addBroker($broker);
            $this->gotoPluginMngt();
        }
    }
    
    /**
     * Add the given broker through the UI and enable it
     * API is disable, log is set to debug
     * @param array $broker_info
     */
    private function addBroker(array $broker_info) {
        // Add the equipement
        $this->waitElemIsClickable(By::xpath("//div[@data-action='add_jmqtt' and not(@brkid)]"))->click();
        $this->waitElemIsVisible(By::xpath("//input[contains(@class,'bootbox-input-text')]"))->sendKeys($broker_info['name']);
        $this->clickDialogBoxConfirm();
        $this->assertDivAlertSuccessSave();
        
        // Enable the equipment
        $this->setIsEnable(true, false);
        
        // Fill broker parameters
        $this->setConfiguration(MqttEqpts::CONF_MQTT_ADDRESS, $broker_info['mosquitto_host'], false);
        $this->setConfiguration(MqttEqpts::CONF_MQTT_PORT, $broker_info['mosquitto_port'], false);
        $this->setConfiguration(MqttEqpts::CONF_MQTT_ID, $broker_info['mosquitto_client_id'], false);
        $this->setConfiguration(MqttEqpts::CONF_MQTT_INC_TOPIC, '#', false);
        $this->setConfiguration(MqttEqpts::CONF_API, false, false);
                
        // Log => debug
        $this->setLogLevel('100');
        
        // Save the equipement
        $this->saveEqLogic();
        
        // Wait for the daemon to pass ok
        for ($i=0 ; $i<20 ; $i++) {
            usleep(100000);
            $text = self::$wd->findElement(By::xpath("//td[contains(@class,'daemonState')]"))->getText();
            if ($text == 'OK')
                break;
        }
        $this->assertEquals("OK", $text, "Daemon is not OK");
    }

    /**
     * Set the given configuration parameter of the currently displayed equipment.
     * 
     * Start page req.: the equipment page (no matter which tab)
     * End page: same as start page
     * 
     * @param string $key parameter key (among MqttEqpts::CONF_*)
     * @param string|bool $value
     * @param bool $save whether or not equiment shall be saved
     */
    public function setConfiguration(string $key, $value, bool $save=true) {
        $conf = array(
            MqttEqpts::CONF_AUTO_ADD_CMD => array('tab' => self::TAB_EQPT, 'uitype' => 'checkbox'),
            MqttEqpts::CONF_QOS => array('tab' => self::TAB_EQPT, 'uitype' => 'select'),
            MqttEqpts::CONF_MQTT_ADDRESS => array('tab' => self::TAB_BRK, 'uitype' => 'text'),
            MqttEqpts::CONF_MQTT_PORT => array('tab' => self::TAB_BRK, 'uitype' => 'text'),
            MqttEqpts::CONF_MQTT_ID => array('tab' => self::TAB_BRK, 'uitype' => 'text'),
            MqttEqpts::CONF_MQTT_USER => array('tab' => self::TAB_BRK, 'uitype' => 'text'),
            MqttEqpts::CONF_MQTT_PASS => array('tab' => self::TAB_BRK, 'uitype' => 'text'),
            MqttEqpts::CONF_MQTT_INC_TOPIC => array('tab' => self::TAB_BRK, 'uitype' => 'text'),
            MqttEqpts::CONF_API => array('tab' => self::TAB_BRK, 'uitype' => 'select')
        );
        
        if ($key == MqttEqpts::CONF_API && is_bool($value))
            $value = $value ? 'enable' : 'disable';
        
        $this->gotoEqptTab($conf[$key]['tab']);
        
        switch ($conf[$key]['uitype']) {
            case 'select':
                (new Select($this->waitElemIsClickable(By::xpath("//select[@data-l2key='" . $key . "']"))))->selectByValue($value);
                break;
            case 'checkbox':
                $el = $this->waitElemIsClickable(By::xpath("//input[@data-l2key='" . $key . "']"));
                if ($el->isSelected() && ! $value)
                    $el->click();
                if ( ! $el->isSelected() && $value)
                    $el->click();
                break;
            case 'text':
                $this->waitElemIsClickable(By::xpath("//input[@data-l2key='" . $key . "']"))->clear()->sendKeys($value);
                break;
        }
        
        if ($save)
            $this->saveEqLogic();
    }
    
    /**
     * Enable or disable the currently displayed equipment through the UI.
     *
     * Start page req.: the equipment page (no matter which tab)
     * End page: same as start page
     *
     * @param bool $isEnable
     * @param bool $save whether or not equiment shall be saved
     */
    public function setIsEnable(bool $isEnable, bool $save=true) {
        $this->gotoEqptTab(self::TAB_EQPT);
        $el = $this->waitElemIsClickable(By::xpath("//input[@data-l1key='isEnable']"));
        if ($el->isSelected() && ! $isEnable)
            $el->click();
        if ( ! $el->isSelected() && $isEnable)
            $el->click();
        if ($save)
            $this->saveEqLogic();
    }
       
    /**
     * Rename the currently displayed equipment. Equipment is not saved.
     * Start page req.: the equipment page (no matter which tab)
     * End page: same as start page
     * @param string $name equipment name
     */
    public function setEqptName(string $name) {
        $this->gotoEqptTab(self::TAB_EQPT);
        $this->waitElemIsVisible(By::xpath("//input[@data-l1key='name']"))->clear()->sendKeys($name);
    }
    
    /**
     * Enable or disable the jMQTT API for the given broker. Broker is saved.
     * Start page spec: no condition
     * End page: page of the given broker equipment
     * @param string $bname broker name
     * @param bool $isEnable
     */
    public function setMqttApi(string $bname, bool $isEnable) {
        $this->gotoBrokerPage($bname);
        $this->setConfiguration(MqttEqpts::CONF_API, $isEnable);
    }

    /**
     * Set the log level of the currently displayed broker equipment. Equipment is not saved.
     * Start page req.: the equipment page (no matter which tab)
     * End page: same as start page
     * @param string $log_level
     */
    public function setLogLevel(string $log_level) {
        $this->gotoEqptTab(self::TAB_BRK);
        $this->waitElemIsClickable(By::xpath("//input[@data-l2key='" . $log_level . "']"))->click();
    }
    
    /**
     * Set topic of the currently displayed equipment. Equipment is not saved.
     * Start page req.: the equipment page (no matter which tab)
     * End page: same as start page
     * @param string $log_level
     */
    public function setTopic(string $topic) {
        $this->gotoEqptTab(self::TAB_EQPT);
        $this->waitElemIsVisible(By::xpath("//input[@data-l1key='logicalId']"))->clear()->sendKeys($topic);
    }
    
    /**
     * Set the name of the command which has the given topic
     * Start page spec: the equipment page in the command tab, JSON view
     * End page: same as start page
     * @param string $topic
     * @param string $cname
     * @throws Exception if the command is not found
     */
    public function setJsonCmdName(string $topic, string $cname) {
        /** @var RemoteWebElement $trs */
        $trs = $this->waitElemsAreVisible(By::xpath("//table[@id='table_cmd']/tbody/tr"));
        foreach ($trs as $tr) {
            if ($tr->findElement(By::xpath(".//*[@data-l2key='topic']"))->getAttribute('value') == $topic) {
                $tr->findElement(By::xpath(".//*[@data-l1key='name']"))->clear()->sendKeys($cname);
                return;
            }
        }
        throw new Exception("Cannot find the command with the topic: " . $topic);
    }
    
    /**
     * Wait for the inclusion of the given equipment
     * i.e. wait for the alert message, close it and wait for the equipement card to be displayed
     * Start page spec: the plugin page
     * End page: same as start page
     * @param string $bname broker name
     * @param string $ename eqpt name
     */
    public function waitEquipmentInclusion(string $bname, string $ename) {
        $this->assertNewEqptMsg("L'équipement " . $ename . " vient d'être inclu. La page va se réactualiser automatiquement.");
        $this->waitElemIsVisible(By::xpath(
            "//legend[text()=' Equipements connectés à " . $bname  . "']//following-sibling::div" .
            "//div[contains(@class,'eqLogicDisplayCard')]//strong[contains(text(),'" . $ename . "')]"));
    }
    
    /**
     * Wait for the inclusion of the given command(s)
     * i.e. wait for the alert message, close it and wait for the command to be displayed
     * Start page spec: the equipment page in the command tab
     * End page: same as start page
     * @param string $ename equipment name
     * @param string|array[string] $cnames command names array (or command name for a single command)
     * @param bool $reload true if the page is reloaded (true by default)
     * @param int $delay delay in s to wait for command(s) inclusion
     * @throws Exception if all commands have not been incuded after expiration of the delay
     */
    public function waitCmdInclusion(string $ename, $cnames, bool $reload=true, int $delay = self::IMPLICIT_WAIT_DUR) {
        if (!is_array($cnames))
            $cnames = array($cnames);
               
        $startTime = microtime(true);
        $by = By::xpath("//*[contains(@class,'cmdAttr') and @data-l1key='name']");
        do {
            usleep(100000);
            try {
                $cnamesNotFound = $cnames;
                $cmds = self::$wd->findElements($by);
                foreach ($cmds as $cmd) {
                    $cname = $cmd->getAttribute('value');
                    if (in_array($cname, $cnamesNotFound)) {
                        unset($cnamesNotFound[array_search($cname, $cnamesNotFound)]);
                    }
                }
            }
            catch (Exception $e) {}
            if (count($cnamesNotFound) == 0) {
                $this->assertDivAlertNewCmdMsg($ename, $cnames, $reload);
                return;
            }
        } while (microtime(true) - $startTime < $delay);
        
        throw new Exception("command(s) " . json_encode($cnamesNotFound) . " not found in equipement " . $ename);
    }

    /**
     * Delete the given command from the command tab
     * Start page spec: the equipment page in the command tab
     * End page: same as start page
     * @param string $cname command name
     * @throws Exception if command is not found
     */
    public function deleteCmd(string $cname) {
        $found = false;
        $cmds = self::$wd->findElements(By::xpath("//*[contains(@class,'cmdAttr') and @data-l1key='name']"));
        /** @var $cmd WebDriverElement */
        foreach ($cmds as $cmd) {
            if ($cmd->getAttribute('value') == $cname) {
                $cmd->findElement(By::xpath(".//ancestor::tr//*[@data-action='remove']"))->click();
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new Exception("command " . $cname . " not found on the current page");
        }
    }
    
    /**
     * Drag and drop the source command onto the target command
     * Start page spec: the equipment page in the command tab, classic view
     * End page: same as start page
     * @param string $cnameSource source command name
     * @param string $cnameTarget target command name
     */
    public function moveCmd(string $cnameSource, string $cnameTarget) {
        $cmds = self::$wd->findElements(By::xpath("//*[contains(@class,'cmdAttr') and @data-l1key='name']"));
        //$byId = By::xpath(".//ancestor::tr");
        $byId = By::xpath(".//ancestor::tr//*[contains(@class,'cmdAttr') and @data-l1key='id']");
        /** @var $cmd WebDriverElement */
        foreach ($cmds as $cmd) {
            if ($cmd->getAttribute('value') == $cnameSource) {
                $source = $cmd->findElement($byId);
            }
            if ($cmd->getAttribute('value') == $cnameTarget) {
                $target = $cmd->findElement($byId);
            }
        }
        $wa = new WebDriverActions(self::$wd);
        $wa->dragAndDrop($source, $target)->perform();
    }
    
    /**
     * Return the commands displayed on the command page of the equipment.
     * Depending on the view (classic or json) return array does not contain the same fields.
     * @return string[]
     */
    public function getCmds() {
        // Which view is displayed (classic or json)?
        $view = $this->getCmdView();
        
        // Get the eqLogic id
        $eqLogicId = self::$wd->findElement(By::xpath("//*[contains(@class,'eqLogicAttr') and @data-l1key='id']"))->getAttribute("value");
        
        $cmds = array();
        $trs = $this->waitElemsAreVisible(By::xpath("//table[@id='table_cmd']/*/tr"));
        if (count($trs) == 1) {
            // Only one line in the tbody section => table is empty
            return $cmds;
        }
        
        $trs = $this->waitElemsAreVisible(By::xpath("//table[@id='table_cmd']/tbody/tr"));
        $i = 0;
        foreach ($trs as $tr) {
            $name = $tr->findElement(By::xpath(".//*[@data-l1key='name']"))->getAttribute("value");
            $type = $tr->findElement(By::xpath(".//*[@data-l1key='type']"))->getAttribute("value");
            $subtype = $tr->findElement(By::xpath(".//*[@data-l1key='subType']"))->getAttribute('value');
            $topic = $tr->findElement(By::xpath(".//*[@data-l2key='topic']"))->getAttribute('value');
            $val = $tr->findElement(By::xpath(".//*[@data-key='value']"))->getAttribute('value');
            $cmd = MqttEqptCmd::new($name, $topic, $eqLogicId, $val, $type, $subtype);
            $cmd->setId($tr->findElement(By::xpath(".//*[@data-l1key='id']"))->getText());
            $cmd->setUnit($tr->findElement(By::xpath(".//*[@data-l1key='unite']"))->getAttribute('value'));
            if ($view == self::VIEW_CLASSIC && $cmd->getId() != "") {
                $cmd->setIsHistorized($tr->findElement(By::xpath(".//*[@data-l1key='isHistorized']"))->isSelected() ? "1" : "0");
                $cmd->setIsVisible($tr->findElement(By::xpath(".//*[@data-l1key='isVisible']"))->isSelected() ? "1" : "0");
            }
            $cmd->setOrder($i++);
            $cmds[] = $cmd;
        }
        return $cmds;
    }
    
    /**
     * @return \Facebook\WebDriver\Remote\RemoteWebElement
     */
    private function getBrokersDiv() {
        return $this->waitElemIsVisible(By::xpath("//legend[text()=' Gestion plugin et brokers']//following-sibling::div"));
    }

    /**
     * @param $bname string broker name
     * @return \Facebook\WebDriver\Remote\RemoteWebElement
     */
    private function getBrokerModulesDiv(string $bname) {
        return $this->waitElemIsVisible(
            By::xpath("//legend[text()=' Equipements connectés à " . $bname  . "']//following-sibling::div"));
    }
    
    /**
     * Assert the include button of the given broker is in the given state
     *
     * @param $bname string broker name
     * @param $is_include bool expected state
     * @return WebDriverElement the include mode button
     */
    public function assertIncludeMode(string $bname, bool $is_include) {
        $div = $this->getBrokerModulesDiv($bname);
        $incl_bt = $div->findElement(By::xpath("./div[contains(@data-action,'changeIncludeMode')]"));
        if ($is_include) {
            $this->assertContains('include', $incl_bt->getAttribute('class'), 'Inclusion mode of broker ' . $bname . ' should be enabled');
            $this->assertEquals("Arrêter l'inclusion", $incl_bt->getText());
        }
        else {
            $this->assertNotContains('include', $incl_bt->getAttribute('class'), 'Inclusion mode of broker ' . $bname . ' should be disabled');
            $this->assertEquals("Mode inclusion", $incl_bt->getText());
        }
        return $incl_bt;
    }
    
    protected function logDbg($msg) {
        if (in_array('--debug', $_SERVER ['argv'], true)) {
            print_r($msg);
        }
    }
}

?>
