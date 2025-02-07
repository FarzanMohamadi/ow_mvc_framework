<?php
/**
 * @package ow_system_plugins.base.bol
 * @since 1.8.1
 */
class BOL_StorageService
{
    const UPDATE_SERVER = "https:///server/";
    /* ---------------------------------------------------------------------- */
    const URI_CHECK_ITEMS_FOR_UPDATE = "get-items-update-info";
    const URI_GET_ITEM_INFO = "get-item-info";
    const URI_GET_PLATFORM_INFO = "platform-info";
    const URI_DOWNLOAD_PLATFORM_ARCHIVE = "download-platform";
    const URI_DOWNLOAD_ITEM = "get-item";
    const URI_CHECK_LECENSE_KEY = "check-license-key";
    /* ---------------------------------------------------------------------- */
    const URI_VAR_KEY = "key";
    const URI_VAR_DEV_KEY = "developerKey";
    const URI_VAR_BUILD = "build";
    const URI_VAR_LICENSE_KEY = "licenseKey";
    const URI_VAR_ITEM_TYPE = "type";
    const URI_VAR_BACK_URI = "back-uri";
    const URI_VAR_LICENSE_CHECK_COMPLETE = "license-check-complete";
    const URI_VAR_LICENSE_CHECK_RESULT = "license-check-result";
    const URI_VAR_FREEWARE = "freeware";
    const URI_VAR_NOT_FOUND = "notFound";
    const URI_VAR_RETURN_RESULT = "returnResult";
    /* ---------------------------------------------------------------------- */
    const URI_VAR_ITEM_TYPE_VAL_PLUGIN = "plugin";
    const URI_VAR_ITEM_TYPE_VAL_THEME = "theme";
    /* ---------------------------------------------------------------------- */
    const ITEM_DEACTIVATE_TIMEOUT_IN_DAYS = 5;

    /**
     * @var BOL_ThemeService
     */
    private $themeService;

    /**
     * @var BOL_PluginService
     */
    private $pluginService;

    /**
     * Singleton instance.
     *
     * @var BOL_StorageService
     */
    private static $classInstance;

    /**
     * Returns an instance of class (singleton pattern implementation).
     *
     * @return BOL_StorageService
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
        $this->pluginService = BOL_PluginService::getInstance();
        $this->themeService = BOL_ThemeService::getInstance();
    }

    /**
     * Retrieves update information for all plugins and themes.
     *
     * @return bool
     */
    public function checkUpdates()
    {
        $event = OW::getEventManager()->trigger(new OW_Event('base.on_before_update'));
        if(isset($event->getData()['disable'])){
            return false;
        }
        $requestArray = array("platform" => array(self::URI_VAR_BUILD => OW::getConfig()->getValue("base", "soft_build")),
            "items" => array());

        $plugins = $this->pluginService->findRegularPlugins();

        /* @var $plugin BOL_Plugin */
        foreach ( $plugins as $plugin )
        {
            $requestArray["items"][] = array(
                self::URI_VAR_KEY => $plugin->getKey(),
                self::URI_VAR_DEV_KEY => $plugin->getDeveloperKey(),
                self::URI_VAR_BUILD => $plugin->getBuild(),
                self::URI_VAR_LICENSE_KEY => $plugin->getLicenseKey(),
                self::URI_VAR_ITEM_TYPE => self::URI_VAR_ITEM_TYPE_VAL_PLUGIN
            );
        }

        //check all manual updates before reading builds in DB
        $this->themeService->checkManualUpdates();
        $themes = $this->themeService->findAllThemes();

        /* @var $dto BOL_Theme */
        foreach ( $themes as $dto )
        {
            $requestArray["items"][] = array(
                self::URI_VAR_KEY => $dto->getKey(),
                self::URI_VAR_DEV_KEY => $dto->getDeveloperKey(),
                self::URI_VAR_BUILD => $dto->getBuild(),
                self::URI_VAR_LICENSE_KEY => $dto->getLicenseKey(),
                self::URI_VAR_ITEM_TYPE => self::URI_VAR_ITEM_TYPE_VAL_THEME
            );
        }

        $data = $this->triggerEventBeforeRequest();
        $data["info"] = json_encode($requestArray);
        OW::getConfig()->saveConfig("base", "update_soft", 0, null, false);

        $params = new UTIL_HttpClientParams();
        $params->addParams($data);
        $response = UTIL_HttpClient::post($this->getStorageUrl(self::URI_CHECK_ITEMS_FOR_UPDATE), $params);

        if ( !$response || $response->getStatusCode() != UTIL_HttpClient::HTTP_STATUS_OK )
        {
            OW::getLogger()->writeLog(OW_Log::WARNING, "core.update: ". __CLASS__ . "::" . __METHOD__ . "#" . __LINE__ . " storage request status is not OK", ['actionType'=>OW_Log::UPDATE, 'enType'=>'core']);

            return false;
        }

        $resultArray = array();

        if ( $response->getBody() )
        {
            $resultArray = json_decode($response->getBody(), true);
        }

        if ( empty($resultArray) || !is_array($resultArray) )
        {
            OW::getLogger()->writeLog(OW_Log::WARNING, "core.update: ".__CLASS__ . "::" . __METHOD__ . "#" . __LINE__ . " remote request returned empty result", ['actionType'=>OW_Log::UPDATE, 'enType'=>'core']);

            return false;
        }

        if ( !empty($resultArray["update"]) )
        {
            if ( !empty($resultArray["update"]["platform"]) && (bool) $resultArray["update"]["platform"] )
            {
                OW::getConfig()->saveConfig("base", "update_soft", 1);
            }

            if ( !empty($resultArray["update"]["items"]) )
            {
                $this->updateItemsUpdateStatus($resultArray["update"]["items"]);
            }
        }

        $items = !empty($resultArray["invalidLicense"]) ? $resultArray["invalidLicense"] : array();

        $this->updateItemsLicenseStatus($items);

        return true;
    }

    /**
     * Returns information from remote storage for store item.
     *
     * @param string $key
     * @param string $devKey
     * @param int $currentBuild
     * @return array
     */
    public function getItemInfoForUpdate( $key, $devKey, $currentBuild = 0 )
    {
        $params = array(
            self::URI_VAR_KEY => trim($key),
            self::URI_VAR_DEV_KEY => trim($devKey),
            self::URI_VAR_BUILD => (int) $currentBuild
        );

        $data = array_merge($params, $this->triggerEventBeforeRequest($params));
        $requestUrl = OW::getRequest()->buildUrlQueryString($this->getStorageUrl(self::URI_GET_ITEM_INFO), $data);

        return $this->requestGetResultAsJson($this->getStorageUrl(self::URI_GET_ITEM_INFO), $data);
    }

    /**
     * Returns information from remote storage for platform.
     *
     * @return array
     */
    public function getPlatformInfoForUpdate()
    {
        $data = $this->triggerEventBeforeRequest();

        return $this->requestGetResultAsJson($this->getStorageUrl(self::URI_GET_PLATFORM_INFO), $data);
    }

    /**
     * Downloads platform update archive and puts it to the provided path.
     *
     * @return string
     * @throws LogicException
     */
    public function downloadPlatform()
    {
        $params = array(
            "platform-version" => OW::getConfig()->getValue("base", "soft_version"),
            "platform-build" => OW::getConfig()->getValue("base", "soft_build"),
            "site-url" => OW::getRouter()->getBaseUrl()
        );

        $data = array_merge($params, $this->triggerEventBeforeRequest($params));
        $requestUrl = OW::getRequest()->buildUrlQueryString($this->getStorageUrl(self::URI_DOWNLOAD_PLATFORM_ARCHIVE),
            $data);

        $paramsObj = new UTIL_HttpClientParams();
        $paramsObj->addParams($data);
        $response = UTIL_HttpClient::get($this->getStorageUrl(self::URI_DOWNLOAD_PLATFORM_ARCHIVE), $paramsObj);

        if ( !$response || $response->getStatusCode() != UTIL_HttpClient::HTTP_STATUS_OK || !$response->getBody() )
        {
            throw new LogicException("Can't download file. Server returned empty file.");
        }

        $fileName = UTIL_String::getRandomStringWithPrefix("platform_archive_", 8, UTIL_String::RND_STR_NUMERIC) . ".zip";
        $archivePath = OW_DIR_PLUGINFILES . $fileName;
        OW::getStorage()->fileSetContent($archivePath, $response->getBody());

        return $archivePath;
    }

    /**
     * Downloads item archive and returns it's local path.
     *
     * @param string $key
     * @param string $devKey
     * @param string $licenseKey
     * @return string
     * @throws LogicException
     */
    public function downloadItem( $key, $devKey, $licenseKey = null )
    {
        $params = array(
            self::URI_VAR_KEY => trim($key),
            self::URI_VAR_DEV_KEY => trim($devKey),
            self::URI_VAR_LICENSE_KEY => $licenseKey != null ? trim($licenseKey) : null,
            "site-url" => OW::getRouter()->getBaseUrl()
        );

        $data = array_merge($params, $this->triggerEventBeforeRequest($params));

        $paramsObj = new UTIL_HttpClientParams();
        $paramsObj->addParams($data);
        $response = UTIL_HttpClient::get($this->getStorageUrl(self::URI_DOWNLOAD_ITEM), $paramsObj);

        if ( !$response || $response->getStatusCode() != UTIL_HttpClient::HTTP_STATUS_OK || !$response->getBody() )
        {
            throw new LogicException("Can't download file. Server returned empty file.");
        }

        $fileName = UTIL_String::getRandomStringWithPrefix("plugin_archive_", 8, UTIL_String::RND_STR_NUMERIC) . ".zip";
        $archivePath = OW_DIR_PLUGINFILES . $fileName;
        OW::getStorage()->fileSetContent($archivePath, $response->getBody());

        return $archivePath;
    }

    /**
     * Checks if license key is valid for store item.
     *
     * @param string $key
     * @param string $developerKey
     * @param string $licenseKey
     * @return bool
     */
    public function checkLicenseKey( $key, $devKey, $licenseKey )
    {
        if ( empty($key) || empty($devKey) || empty($licenseKey) )
        {
            return null;
        }

        $params = array(
            self::URI_VAR_KEY => trim($key),
            self::URI_VAR_DEV_KEY => trim($devKey),
            self::URI_VAR_LICENSE_KEY => trim($licenseKey)
        );

        $data = array_merge($params, $this->triggerEventBeforeRequest($params));
        $result = $this->requestGetResultAsJson($this->getStorageUrl(self::URI_CHECK_LECENSE_KEY), $data);

        if ( $result === null )
        {
            return null;
        }

        return $result === true ? true : false;
    }

    /**
     * Returns platform xml info.
     *
     * @return array
     */
    public function getPlatformXmlInfo()
    {
        $filePath = OW_DIR_ROOT . "ow_version.xml";

        if ( !OW::getStorage()->fileExists($filePath) )
        {
            return null;
        }

        return (array) simplexml_load_file($filePath);
    }

    /**
     * Returns inited and checked ftp connection.
     *
     * @throws LogicException
     * @return UTIL_Ftp
     */
    public function getFtpConnection()
    {
        $event = OW_EventManager::getInstance()->trigger(new OW_Event('base.before_get_ftp_connection'));
        if(isset($event->getData()['ftpConnection'])){
            return $event->getData()['ftpConnection'];
        }

        $language = OW::getLanguage();
        $errorMessageKey = null;
        $ftp = null;

        $ftpAttrsIsNotExist = !OW::getSession()->isKeySet("ftpAttrs") || !is_array(OW::getSession()->get("ftpAttrs"));
        $event = OW_EventManager::getInstance()->trigger(new OW_Event('base.check_ftp_not_exist', array("checkFtpAttrs" => true)));
        if($ftpAttrsIsNotExist && isset($event->getData()['ftpAttrsIsNotExist'])){
            $ftpAttrsIsNotExist = $event->getData()['ftpAttrsIsNotExist'];
        }
        if ( $ftpAttrsIsNotExist ){
            $errorMessageKey = "plugins_manage_need_ftp_attrs_message";
        }
        else
        {
            $ftpAttrs = null;
            $ftpAttrsIsNotExist = !OW::getSession()->isKeySet("ftpAttrs") || !is_array(OW::getSession()->get("ftpAttrs"));
            if ( $ftpAttrsIsNotExist ){
                $event = OW_EventManager::getInstance()->trigger(new OW_Event('base.get_ftp_attrs', array("getFtpAttrs" => true)));
                if(isset($event->getData()['getFtpAttrs'])){
                    $ftpAttrs = $event->getData()['getFtpAttrs'];
                }
            }else{
                $ftpAttrs = OW::getSession()->get("ftpAttrs");
            }
            if($ftpAttrs == null){
                $errorMessageKey = "plugins_manage_need_ftp_attrs_message";
                throw new LogicException($language->text("admin", $errorMessageKey));
            }
            $ftp = null;
            try
            {
                $ftp = UTIL_Ftp::getConnection($ftpAttrs);
            }
            catch ( Exception $ex )
            {
                $errorMessageKey = $ex->getMessage();
            }

            if ( $ftp !== null )
            {
                $testDir = OW_DIR_CORE . "test";

                $ftp->mkDir($testDir);

                if ( OW::getStorage()->fileExists($testDir) )
                {
                    $ftp->rmDir($testDir);
                }
                else
                {
                    $errorMessageKey = "plugins_manage_ftp_attrs_invalid_user";
                }
            }
        }

        if ( $errorMessageKey !== null )
        {
            throw new LogicException($language->text("admin", $errorMessageKey));
        }

        return $ftp;
    }

    /**
     * Returns URL of local generic update script.
     *
     * @return string
     */
    public function getUpdaterUrl()
    {
        return OW_URL_HOME . "ow_updates/index.php";
    }

    /**
     * Returns the list of items with invalid license.
     *
     * @return type
     */
    public function findItemsWithInvalidLicense()
    {
        return array_merge($this->pluginService->findPluginsWithInvalidLicense(),
            $this->themeService->findItemsWithInvalidLicense());
    }

    /**
     * @param int $timeStamp
     * @return bool
     */
    public function isItemLicenseCheckPeriodExpired( $timeStamp )
    {
        return (intval($timeStamp) + self::ITEM_DEACTIVATE_TIMEOUT_IN_DAYS * 24 * 3600) <= time();
    }

    /**
     * @param BOL_StoreItem $item
     */
    public function saveStoreItem( BOL_StoreItem $item )
    {
        if ( $item instanceof BOL_Plugin )
        {
            $this->pluginService->savePlugin($item);
        }
        else
        {
            $this->themeService->saveTheme($item);
        }
    }

    /**
     * @param string $key
     * @param string $devKey
     * @param string $type
     * @return BOL_StoreItem
     */
    public function findStoreItem( $key, $devKey, $type )
    {
        if ( $type == self::URI_VAR_ITEM_TYPE_VAL_PLUGIN )
        {
            return $this->pluginService->findPluginByKey($key, $devKey);
        }

        if ( $type == self::URI_VAR_ITEM_TYPE_VAL_THEME )
        {
            return $this->themeService->findThemeByKey($key);
        }

        return null;
    }
    /* ---------------------------------------------------------------------- */

    private function getStorageUrl( $uri )
    {
        return self::UPDATE_SERVER . UTIL_String::removeFirstAndLastSlashes($uri) . "/";
    }

    private function triggerEventBeforeRequest( $params = array() )
    {
        $event = OW::getEventManager()->trigger(new OW_Event('base.on_plugin_info_update', $params));
        $data = $event->getData();

        return (!empty($data) && is_array($data) ) ? $data : array();
    }

    private function updateItemsUpdateStatus( array $items )
    {
        if ( empty($items) )
        {
            return;
        }

        foreach ( $items as $item )
        {
            if ( $item[self::URI_VAR_ITEM_TYPE] == self::URI_VAR_ITEM_TYPE_VAL_PLUGIN )
            {
                $dto = $this->pluginService->findPluginByKey($item[self::URI_VAR_KEY], $item[self::URI_VAR_DEV_KEY]);

                if ( $dto != null )
                {
                    $dto->setUpdate(BOL_PluginService::PLUGIN_STATUS_UPDATE);
                    $this->pluginService->savePlugin($dto);
                }
            }
            else if ( $item[self::URI_VAR_ITEM_TYPE] == self::URI_VAR_ITEM_TYPE_VAL_THEME )
            {
                $dto = $this->themeService->findThemeByKey($item[self::URI_VAR_KEY]);

                if ( $dto != null && $dto->getDeveloperKey() == $item[self::URI_VAR_DEV_KEY] )
                {
                    $dto->setUpdate(BOL_ThemeService::THEME_STATUS_UPDATE);
                    $this->themeService->saveTheme($dto);
                }
            }
        }
    }

    private function updateItemsLicenseStatus( array $items )
    {
        $invalidItems = array(self::URI_VAR_ITEM_TYPE_VAL_PLUGIN => array(), self::URI_VAR_ITEM_TYPE_VAL_THEME => array());

        foreach ( $items as $item )
        {
            $invalidItems[$item[self::URI_VAR_ITEM_TYPE]][$item[self::URI_VAR_KEY]] = $item[self::URI_VAR_DEV_KEY];
        }

        $itemsToCheck = array_merge($this->pluginService->findAllPlugins(), $this->themeService->findAllThemes());
        $dataForNotification = array();

        /* @var $item BOL_StoreItem */
        foreach ( $itemsToCheck as $item )
        {
            $type = ($item instanceof BOL_Plugin) ? self::URI_VAR_ITEM_TYPE_VAL_PLUGIN : self::URI_VAR_ITEM_TYPE_VAL_THEME;

            // if the item is on DB
            if ( isset($invalidItems[$type][$item->getKey()]) && $invalidItems[$type][$item->getKey()] == $item->getDeveloperKey() )
            {
                if ( (int) $item->getLicenseCheckTimestamp() == 0 )
                {
                    $dataForNotification[] = array("type" => $type, "title" => $item->getTitle());
                    $item->setLicenseCheckTimestamp(time());
                    $this->saveStoreItem($item);
                }
                else if ( $this->isItemLicenseCheckPeriodExpired($item->getLicenseCheckTimestamp()) )
                {
                    if ( $type == self::URI_VAR_ITEM_TYPE_VAL_THEME && $this->themeService->getSelectedThemeName() == $item->getKey() )
                    {
                        $defaultTheme = OW::getEventManager()->call("base.get_default_theme");

                        if( !$defaultTheme )
                        {
                            $defaultTheme = BOL_ThemeService::DEFAULT_THEME;
                        }

                        $this->themeService->setSelectedThemeName($defaultTheme);
                    }
                    else if ( $type == self::URI_VAR_ITEM_TYPE_VAL_PLUGIN && $item->isActive )
                    {
                        $this->pluginService->deactivate($item->getKey());
                    }
                }
            }
            else if ( $item->getLicenseCheckTimestamp() != null && $item->getLicenseCheckTimestamp() > 0 )
            {
                $item->setLicenseCheckTimestamp(null);
                $this->saveStoreItem($item);
            }
        }

        $this->notifyAdminAboutInvalidItems($dataForNotification);
    }

    private function notifyAdminAboutInvalidItems( array $items )
    {
        if ( empty($items) )
        {
            return;
        }

        $titleList = array();

        foreach ( $items as $item )
        {
            $titleList[] = "\"{$item["title"]}\"";
        }

        $params = array(
            "itemList" => implode("<br />", $titleList),
            "siteURL" => OW::getRouter()->getBaseUrl(),
            "adminUrl" => OW::getRouter()->urlForRoute("admin_plugins_installed")
        );

        $language = OW::getLanguage();
        $mail = OW::getMailer()->createMail();
        $mail->addRecipientEmail(OW::getConfig()->getValue("base", "site_email"));
        $mail->setSubject($language->text("admin", "mail_template_admin_invalid_license_subject"));
        $mail->setHtmlContent($language->text("admin", "mail_template_admin_invalid_license_content_html", $params));
        $params["itemList"] = implode(PHP_EOL, $titleList);
        $mail->setTextContent($language->text("admin", "mail_template_admin_invalid_license_content_text", $params));

        OW::getMailer()->send($mail);
    }

    private function requestGetResultAsJson( $url, $data )
    {
        $data["site-url"] = OW::getRouter()->getBaseUrl();
        $params = new UTIL_HttpClientParams();
        $params->addParams($data);
        $response = UTIL_HttpClient::get($url, $params);

        if ( !$response || $response->getStatusCode() != UTIL_HttpClient::HTTP_STATUS_OK || !$response->getBody() )
        {
            return null;
        }

        return json_decode($response->getBody(), true);
    }
}
