<?php
/**
 * @package ow_system_plugins.base.bol
 * @since 1.0
 */
class BOL_PluginService
{
    /**
     * @deprecated since version 1.8.1
     */
    const UPDATE_SERVER = BOL_StorageService::UPDATE_SERVER;

    /* list of plugin scripts */
    const SCRIPT_INIT = "init.php";
    const SCRIPT_INSTALL = "install.php";
    const SCRIPT_UNINSTALL = "uninstall.php";
    const SCRIPT_ACTIVATE = "activate.php";
    const SCRIPT_DEACTIVATE = "deactivate.php";
    const PLUGIN_INFO_XML = "plugin.xml";
    /* ---------------------------------------------------------------------- */
    const PLUGIN_STATUS_UP_TO_DATE = BOL_PluginDao::UPDATE_VAL_UP_TO_DATE;
    const PLUGIN_STATUS_UPDATE = BOL_PluginDao::UPDATE_VAL_UPDATE;
    const PLUGIN_STATUS_MANUAL_UPDATE = BOL_PluginDao::UPDATE_VAL_MANUAL_UPDATE;
    const MANUAL_UPDATES_CHECK_INTERVAL_IN_SECONDS = 30;

    /**
     * @var BOL_PluginDao
     */
    private $pluginDao;

    /**
     * @var array
     */
    private $pluginListCache;

    /**
     * Singleton instance.
     *
     * @var BOL_PluginService
     */
    private static $classInstance;

    /**
     * Returns an instance of class (singleton pattern implementation).
     *
     * @return BOL_PluginService
     */
    public static function getInstance()
    {
        if ( self::$classInstance === null )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        $this->pluginDao = BOL_PluginDao::getInstance();
    }

    /**
     * Saves and updates plugin items.
     *
     * @param BOL_Plugin $pluginItem
     */
    public function savePlugin( BOL_Plugin $pluginItem )
    {
        $this->pluginDao->save($pluginItem);
        $this->updatePluginListCache();
    }

    /**
     * Removes plugin entry in DB.
     *
     * @param integer $id
     */
    public function deletePluginById( $id )
    {
        $this->pluginDao->deleteById($id);
        $this->updatePluginListCache();
    }

    /**
     * Returns all installed plugins.
     *
     * @return array<BOL_Plugin>
     */
    public function findAllPlugins()
    {
        return $this->getPluginListCache();
    }

    /**
     * Finds plugin item for provided key.
     *
     * @param string $key
     * @return BOL_Plugin
     */
    public function findPluginByKey( $key, $developerKey = null )
    {
        $key = strtolower($key);
        $pluginList = $this->getPluginListCache();

        if ( !array_key_exists($key, $pluginList) || ( $developerKey !== null && $pluginList[$key]->getDeveloperKey() != strtolower($developerKey) ) )
        {
            return null;
        }

        return $pluginList[$key];
    }

    /**
     * Returns list of active plugins.
     *
     * @return array
     */
    public function findActivePlugins()
    {
        $activePlugins = array();
        $pluginList = $this->getPluginListCache();

        /* @var $plugin BOL_Plugin */
        foreach ( $pluginList as $plugin )
        {
            if ( $plugin->isActive() )
            {
                $activePlugins[] = $plugin;
            }
        }

        return $activePlugins;
    }

    /**
     * Returns list of plugins available for installation.
     *
     * @return array
     */
    public function getAvailablePluginsList()
    {
        $availPlugins = array();
        $dbPluginsArray = array_keys($this->getPluginListCache());

        $xmlPlugins = $this->getPluginsXmlInfo();

        foreach ( $xmlPlugins as $key => $plugin )
        {
            if ( !in_array($plugin["key"], $dbPluginsArray) )
            {
                $availPlugins[$key] = $plugin;
            }
        }

        return $availPlugins;
    }

    /**
     * Returns all plugins XML info.
     */
    public function getPluginsXmlInfo()
    {
        $resultArray = array();

        $xmlFiles = UTIL_File::findFiles(OW_DIR_PLUGIN, array("xml"), 1);

        foreach ( $xmlFiles as $pluginXml )
        {
            if ( basename($pluginXml) == self::PLUGIN_INFO_XML )
            {
                $pluginInfo = $this->readPluginXmlInfo($pluginXml);

                if ( $pluginInfo !== null )
                {
                    $resultArray[$pluginInfo["key"]] = $pluginInfo;
                }
            }
        }

        return $resultArray;
    }

    /**
     * Updates plugin meta info in DB using data in plugin.xml
     */
    public function updatePluginsXmlInfo()
    {
        $info = $this->getPluginsXmlInfo();

        foreach ( $info as $key => $pluginInfo )
        {
            $dto = $this->pluginDao->findPluginByKey($key);

            if ( $dto !== null )
            {
                $dto->setTitle($pluginInfo["title"]);
                $dto->setDescription($pluginInfo["description"]);
                $dto->setDeveloperKey($pluginInfo["developerKey"]);
                $this->pluginDao->save($dto);
            }
        }
    }

    /**
     * Reads provided XML file and returns plugin info array.
     *
     * @param string $pluginXmlPath
     * @return array|null
     */
    public function readPluginXmlInfo( $pluginXmlPath )
    {
        if ( !OW::getStorage()->fileExists($pluginXmlPath) )
        {
            return null;
        }

        $propList = array("key", "name", "description", "license", "author", "build", "copyright", "licenseUrl");
        $xmlInfo = (array) simplexml_load_file($pluginXmlPath);

        if ( !$xmlInfo )
        {
            return null;
        }

        foreach ( $propList as $prop )
        {
            if ( empty($xmlInfo[$prop]) )
            {
                return null;
            }
        }

        $xmlInfo["title"] = $xmlInfo["name"];
        $xmlInfo["path"] = dirname($pluginXmlPath);
        return $xmlInfo;
    }

    /**
     * Returns all regular (non system) plugins.
     *
     * @return array
     */
    public function findRegularPlugins()
    {
        $regularPlugins = array();

        /* @var $plugin BOL_Plugin */
        foreach ( $this->getPluginListCache() as $plugin )
        {
            if ( !$plugin->isSystem() )
            {
                $regularPlugins[] = $plugin;
            }
        }

        return $regularPlugins;
    }

    /**
     * Installs plugins.
     * Installs all available system plugins
     */
    public function installSystemPlugins()
    {
        $files = UTIL_File::findFiles(OW_DIR_SYSTEM_PLUGIN, array("xml"), 1);
        $pluginData = array();
        $tempPluginData = array();

// first element should be BASE plugin
        foreach ( $files as $file )
        {
            $tempArr = $this->readPluginXmlInfo($file);
            $pathArr = explode(DS, dirname($file));
            $tempArr["dir_name"] = array_pop($pathArr);

            if ( $tempArr["key"] == "base" )
            {
                $pluginData[$tempArr["key"]] = $tempArr;
            }
            else
            {
                $tempPluginData[$tempArr["key"]] = $tempArr;
            }
        }

        foreach ( $tempPluginData as $key => $val )
        {
            $pluginData[$key] = $val;
        }

        if ( !array_key_exists("base", $pluginData) )
        {
            throw new LogicException("Base plugin is not found in `basePluginRootDir`!");
        }

// install plugins list
        foreach ( $pluginData as $pluginInfo )
        {
            $pluginDto = new BOL_Plugin();
            $pluginDto->setTitle((!empty($pluginInfo["title"]) ? trim($pluginInfo["title"]) : "No Title"));
            $pluginDto->setDescription((!empty($pluginInfo["description"]) ? trim($pluginInfo["description"]) : "No Description"));
            $pluginDto->setKey(trim($pluginInfo["key"]));
            $pluginDto->setModule($pluginInfo["dir_name"]);
            $pluginDto->setIsActive(true);
            $pluginDto->setIsSystem(true);
            $pluginDto->setBuild((int) $pluginInfo["build"]);

            if ( !empty($pluginInfo["developerKey"]) )
            {
                $pluginDto->setDeveloperKey(trim($pluginInfo["developerKey"]));
            }

            $this->pluginListCache[$pluginDto->getKey()] = $pluginDto;

            $plugin = new OW_Plugin($pluginDto);

            $this->includeScript($plugin->getRootDir() . BOL_PluginService::SCRIPT_INSTALL);

            $this->pluginDao->save($pluginDto);
            $this->updatePluginListCache();
            $this->addPluginDirs($pluginDto);
        }
    }

    /**
     * Installs plugins.
     *
     * @param string $key
     * @param bool $generateCache
     * @return BOL_Plugin|null
     */
    public function install( $key, $generateCache = true )
    {
        OW::getLogger()->writeLog(OW_Log::NOTICE, 'plugin_install', ['actionType'=>OW_Log::CREATE, 'enType'=>'plugin', 'enId'=>$key]);
        $availablePlugins = $this->getAvailablePluginsList();

        if ( empty($key) || !array_key_exists($key, $availablePlugins) )
        {
            throw new LogicException("Invalid plugin key - `{$key}` provided for install!");
        }

        $pluginInfo = $availablePlugins[$key];
        $dirArray = explode(DS, $pluginInfo["path"]);
        $moduleName = array_pop($dirArray);

        $this->checkPrerequisite($key);

        OW::getEventManager()->trigger(new OW_Event('core.before_plugin_install', array("pluginKey" => $key)));

        try {
            // Install plugin
            $pluginDto = new BOL_Plugin();
            $pluginDto->setTitle((!empty($pluginInfo["title"]) ? trim($pluginInfo["title"]) : "No Title"));
            $pluginDto->setDescription((!empty($pluginInfo["description"]) ? trim($pluginInfo["description"]) : "No Description"));
            $pluginDto->setKey(trim($pluginInfo["key"]));
            $pluginDto->setModule($moduleName);
            $pluginDto->setIsActive(false);
            $pluginDto->setIsSystem(false);
            $pluginDto->setBuild((int)$pluginInfo["build"]);

            if (!empty($pluginInfo["developerKey"])) {
                $pluginDto->setDeveloperKey(trim($pluginInfo["developerKey"]));
            }

            // remove old plugin configs
            OW::getConfig()->deletePluginConfigs($pluginDto->getKey());

            $this->addPluginDirs($pluginDto);
            $plugin = new OW_Plugin($pluginDto);
            try{
                $this->includeScript($plugin->getRootDir() . BOL_PluginService::SCRIPT_INSTALL);
            }catch (Exception $ex){
                OW::getLogger()->writeLog(OW_Log::ERROR, 'plugin_install_failed', ['actionType'=>OW_Log::CREATE, 'enType'=>'plugin', 'enId'=>$key, 'error'=>'Install script error', 'exception'=>$ex]);
                return null;
            }
            $this->pluginDao->save($pluginDto);
            $this->updatePluginListCache();
            
            $pluginDto = $this->findPluginByKey($pluginDto->getKey());
            if (!isset($pluginDto)){
                OW::getLogger()->writeLog(OW_Log::ERROR, 'plugin_install_failed', ['actionType'=>OW_Log::CREATE, 'enType'=>'plugin', 'enId'=>$key, 'error'=>'$pluginDto not found!']);
                return null;
            }
            BOL_LanguageService::getInstance()->updatePrefixForPlugin($pluginDto->getKey(), true, $generateCache);

            OW::getEventManager()->trigger(new OW_Event(OW_EventManager::ON_AFTER_PLUGIN_INSTALL, array("pluginKey" => $pluginDto->getKey())));

            // Activate plugin
            try{
                $this->includeScript($plugin->getRootDir() . BOL_PluginService::SCRIPT_ACTIVATE);
            }catch (Exception $ex){
                OW::getLogger()->writeLog(OW_Log::ERROR, 'plugin_activate_failed', ['actionType'=>OW_Log::UPDATE, 'enType'=>'plugin', 'enId'=>$key, 'error'=>'Activate script error in plugin install.', 'exception'=>$ex]);
                return $pluginDto;
            }
            $pluginDto->setIsActive(true);
            $this->pluginDao->save($pluginDto);
            $this->updatePluginListCache();

            OW::getLogger()->writeLog(OW_Log::NOTICE, 'plugin_install', ['actionType'=>OW_Log::CREATE, 'enType'=>'plugin', 'enId'=>$key, 'log'=>'plugin installed and activated success']);
            return $pluginDto;
        }catch (Exception $ex){
            OW::getLogger()->writeLog(OW_Log::ERROR, 'plugin_install_failed', ['actionType'=>OW_Log::CREATE, 'enType'=>'plugin', 'enId'=>$key, 'error'=>$ex, 'trace'=>debug_backtrace()]);
            return null;
        }
    }

    /**
     * Creates platform reserved dirs for plugin, copies all plugin static data
     * 
     * @param BOL_Plugin $pluginDto
     */
    public function addPluginDirs( BOL_Plugin $pluginDto )
    {
        $plugin = new OW_Plugin($pluginDto);

        if ( OW::getStorage()->fileExists($plugin->getStaticDir()) )
        {
            UTIL_File::copyDir($plugin->getStaticDir(), $plugin->getPublicStaticDir());
        }

        // create dir in pluginfiles
        if( OW::getStorage()->fileExists($plugin->getInnerPluginFilesDir()) )
        {
            UTIL_File::copyDir($plugin->getInnerPluginFilesDir(), $plugin->getPluginFilesDir());
        }
        else if ( !OW::getStorage()->fileExists($plugin->getPluginFilesDir()) )
        {
            OW::getStorage()->mkdir($plugin->getPluginFilesDir());
//            OW::getStorage()->chmod($plugin->getPluginFilesDir(), 0777);
        }

        // create dir in userfiles
        if( OW::getStorage()->fileExists($plugin->getInnerUserFilesDir()) )
        {
            OW::getStorage()->copyDir($plugin->getInnerUserFilesDir(), $plugin->getUserFilesDir());
        }
        else if ( !OW::getStorage()->fileExists($plugin->getUserFilesDir()) )
        {
            OW::getStorage()->mkdir($plugin->getUserFilesDir());
        }
    }

    /**
     * Uninstalls plugin
     *
     * @param string $pluginKey
     */
    public function uninstall( $pluginKey )
    {
        OW::getLogger()->writeLog(OW_Log::NOTICE, 'plugin_uninstall', ['actionType'=>OW_Log::DELETE, 'enType'=>'plugin', 'enId'=>$pluginKey]);
        if ( empty($pluginKey) )
        {
            throw new LogicException("Empty plugin key provided for uninstall");
        }

        $pluginDto = $this->findPluginByKey(trim($pluginKey));

        if ( $pluginDto === null )
        {
            throw new LogicException("Invalid plugin key - `{$pluginKey}` provided for uninstall!");
        }

        $plugin = new OW_Plugin($pluginDto);

        // trigger event
        OW::getEventManager()->trigger(new OW_Event(OW_EventManager::ON_BEFORE_PLUGIN_UNINSTALL,
            array("pluginKey" => $pluginDto->getKey())));

        // plugin root directory for trigger data
        $pluginRootDir = OW::getPluginManager()->getPlugin($pluginKey)->getRootDir();

        $this->includeScript($plugin->getRootDir() . BOL_PluginService::SCRIPT_DEACTIVATE);
        $this->includeScript($plugin->getRootDir() . BOL_PluginService::SCRIPT_UNINSTALL);

        // delete plugin work dirs
        $dirsToRemove = array(
            $plugin->getPluginFilesDir(),
            $plugin->getUserFilesDir(),
            $plugin->getPublicStaticDir()
        );

        foreach ( $dirsToRemove as $dir )
        {
            if ( OW::getStorage()->fileExists($dir) )
            {
                UTIL_File::removeDir($dir);
            }
        }

        // remove plugin configs
        OW::getConfig()->deletePluginConfigs($pluginDto->getKey());

        // delete language prefix
        $prefixId = BOL_LanguageService::getInstance()->findPrefixId($pluginDto->getKey());

        if ( !empty($prefixId) )
        {
            BOL_LanguageService::getInstance()->deletePrefix($prefixId, true);
        }

        //delete authorization stuff
        BOL_AuthorizationService::getInstance()->deleteGroup($pluginDto->getKey());

        // drop plugin tables
        $tables = OW::getDbo()->queryForColumnList("SHOW TABLES LIKE '" . str_replace('_', '\_', OW_DB_PREFIX) . $pluginDto->getKey() . "\_%'");

        if ( !empty($tables) )
        {
            $query = "DROP TABLE ";

            foreach ( $tables as $table )
            {
                $query .= "`" . $table . "`,";
            }

            $query = substr($query, 0, -1);

            OW::getDbo()->query($query);
        }

        //remove entry in DB
        $this->deletePluginById($pluginDto->getId());
        $this->updatePluginListCache();

        // trigger event
        OW::getEventManager()->trigger(new OW_Event(OW_EventManager::ON_AFTER_PLUGIN_UNINSTALL,
            array("pluginKey" => $pluginDto->getKey(), 'tables' => $tables, "pluginRootDir" => $pluginRootDir)));
    }

    /**
     * Activates plugin
     *
     * @param $key
     * @param bool $addPackagePointers
     */
    public function activate( $key, $addPackagePointers = true )
    {
        OW::getLogger()->writeLog(OW_Log::NOTICE, 'plugin_activate', ['actionType'=>OW_Log::UPDATE, 'enType'=>'plugin', 'enId'=>$key]);
        $pluginDto = $this->pluginDao->findPluginByKey($key);

        if ( $pluginDto == null )
        {
            throw new LogicException("Can't activate {$key} plugin!");
        }

        $this->checkPrerequisite($key);

        $this->includeScript(OW_DIR_PLUGIN . $pluginDto->getModule() . DS . self::SCRIPT_ACTIVATE);

        $pluginDto->setIsActive(true);
        $this->pluginDao->save($pluginDto);
        if($addPackagePointers){
            OW::getPluginManager()->addPackagePointers($pluginDto);
        }
        $this->updatePluginListCache();

        // trigger event
        $event = new OW_Event(OW_EventManager::ON_AFTER_PLUGIN_ACTIVATE, array("pluginKey" => $pluginDto->getKey()));
        OW::getEventManager()->trigger($event);
    }

    /**
     * Deactivates plugin
     * 
     * @param string $key
     */
    public function deactivate( $key )
    {
        OW::getLogger()->writeLog(OW_Log::NOTICE, 'plugin_deactivate', ['actionType'=>OW_Log::UPDATE, 'enType'=>'plugin', 'enId'=>$key]);
        $pluginDto = $this->pluginDao->findPluginByKey($key);

        if ( $pluginDto == null )
        {
            throw new LogicException("Can't deactivate {$key} plugin!");
        }

        // trigger event
        $event = new OW_Event(OW_EventManager::ON_BEFORE_PLUGIN_DEACTIVATE, array("pluginKey" => $pluginDto->getKey()));
        OW::getEventManager()->trigger($event);

        $this->includeScript(OW::getPluginManager()->getPlugin($pluginDto->getKey())->getRootDir() . self::SCRIPT_DEACTIVATE);

        // remove from sitemap
        BOL_SeoService::getInstance()->removeSitemapEntity($key);

        $pluginDto->setIsActive(false);
        $this->pluginDao->save($pluginDto);

        $this->updatePluginListCache();

        $event = new OW_Event(OW_EventManager::ON_AFTER_PLUGIN_DEACTIVATE, array("pluginKey" => $pluginDto->getKey()));
        OW::getEventManager()->trigger($event);
    }

    /**
     * Returns the count of plugins available for update
     * 
     * @return int
     */
    public function getPluginsToUpdateCount()
    {
        return $this->pluginDao->findPluginsForUpdateCount();
    }

    /**
     * Checks if plugin source code was updated, if yes changes the update status in DB
     * 
     * @return void
     */
    public function checkManualUpdates()
    {
        $timestamp = OW::getConfig()->getValue("base", "check_mupdates_ts");

        if ( ( time() - (int) $timestamp ) < self::MANUAL_UPDATES_CHECK_INTERVAL_IN_SECONDS )
        {
            return;
        }

        $plugins = $this->pluginDao->findAll();
        $xmlInfo = $this->getPluginsXmlInfo();

        /* @var $plugin BOL_Plugin */
        foreach ( $plugins as $plugin )
        {
            if ( !empty($xmlInfo[$plugin->getKey()]) && (int) $plugin->getBuild() < (int) $xmlInfo[$plugin->getKey()]['build'] )
            {
                $plugin->setUpdate(BOL_PluginDao::UPDATE_VAL_MANUAL_UPDATE);
                $this->pluginDao->save($plugin);
            }
        }

        OW::getConfig()->saveConfig("base", "check_mupdates_ts", time(), null, false);
    }

    /**
     * Returns next plugin for manual update if it's available
     * 
     * @return BOL_Plugin
     */
    public function findNextManualUpdatePlugin()
    {
        return $this->pluginDao->findPluginForManualUpdate();
    }

    /**
     * Returns plugins with invalid license
     * 
     * @return array
     */
    public function findPluginsWithInvalidLicense()
    {
        return $this->pluginDao->findPluginsWithInvalidLicense();
    }
    /* ---------------------------------------------------------------------- */

    public function updatePluginListCache()
    {
        $this->pluginListCache = array();
        $dbData = $this->pluginDao->findAll();

        /* @var $plugin BOL_Plugin */
        foreach ( $dbData as $plugin )
        {
            $this->pluginListCache[$plugin->getKey()] = $plugin;
        }
    }

    private function getPluginListCache()
    {
        if ( !$this->pluginListCache )
        {
            $this->updatePluginListCache();
        }

        return $this->pluginListCache;
    }

    /**
     * @param string $scriptPath
     */
    public function includeScript( $scriptPath )
    {
        if ( OW::getStorage()->fileExists($scriptPath) )
        {
            include_once $scriptPath;
        }
    }

    /***
     * @param $category
     * @param string $defaultCategory
     * @return array
     */
    public function getByCategory($category, $defaultCategory='private'){
        $xmlPlugins = BOL_PluginService::getInstance()->getPluginsXmlInfo();
        $pluginKeys = [];
        foreach ($xmlPlugins as $plugin) {
            if (in_array($plugin['key'], ['base','admin'])){
                continue;
            }
            $key = $plugin['key'];
            $pluginXmlInfo = $this->readPluginXmlInfo(OW_DIR_ROOT . 'ow_plugins' . DS . $key . DS . 'plugin.xml');
            if ( !isset($pluginXmlInfo) ){
                $pluginDto = OW::getPluginManager()->getPlugin($key);
                if(isset($pluginDto)){
                    $pluginXmlInfo = $this->readPluginXmlInfo($pluginDto->getRootDir() . 'plugin.xml');
                }else{
                    continue;
                }
            }

            $categories = [];
            if( ! isset($pluginXmlInfo['category']) ){
                $categories[] = $defaultCategory;
            }else{
                /* @var $categoryXml SimpleXMLElement  */
                $categoryXml = $pluginXmlInfo['category'];
                foreach($categoryXml->children() as $child){
                    $categories[] = strip_tags($child->asXml());
                }
            }

            if( in_array($category, $categories)){
                $pluginKeys[] = $key;
            }
        }

        return ($pluginKeys);
    }

    /***
     * Check if plugin's prerequisites are installed and activated
     *
     * @param string $key
     */
    public function checkPrerequisite($key)
    {
        $pluginXmlInfo = $this->readPluginXmlInfo(OW_DIR_ROOT . 'ow_plugins' . DS . $key . DS . 'plugin.xml');
        if (isset($pluginXmlInfo["prerequisite"])) {
            $prerequisiteXml = $pluginXmlInfo['prerequisite'];
            foreach($prerequisiteXml->children() as $child){
                $prerequisitePluginKey = strip_tags($child->asXml());
                if (!FRMSecurityProvider::checkPluginActive($prerequisitePluginKey, true)){
                    OW::getLogger()->writeLog(OW_Log::WARNING, 'plugin_check_prerequisite_failed',
                        ['plugin_key'=>$key, 'prerequisite_key'=>$prerequisitePluginKey]);
                    throw new LogicException(OW::getLanguage()->text('admin', 'plugin_required', array('pluginName'=>$prerequisitePluginKey)));
                }
            }
        }
    }
}
