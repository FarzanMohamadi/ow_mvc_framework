<?php
/**
 * Controller class to work with the remote store.
 *
 * @package ow_system_plugins.admin.controllers
 * @since 1.7.7
 */
class ADMIN_CTRL_Storage extends ADMIN_CTRL_StorageAbstract
{

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Generic action to get the license key for items.
     */
    public function checkItemLicense()
    {
        $params = $_GET;
        $language = OW::getLanguage();
        $params[BOL_StorageService::URI_VAR_LICENSE_CHECK_COMPLETE] = 0;
        $params[BOL_StorageService::URI_VAR_LICENSE_CHECK_RESULT] = 0;

        if ( empty($params[BOL_StorageService::URI_VAR_KEY]) || empty($params[BOL_StorageService::URI_VAR_ITEM_TYPE]) || empty($params[BOL_StorageService::URI_VAR_DEV_KEY]) )
        {
            $errMsg = $language->text("admin", "check_license_invalid_params_err_msg");
            OW::getFeedback()->error($errMsg);
            $this->redirectToBackUri($params);
            $this->assign("message", $errMsg);

            return;
        }

        $key = trim($params[BOL_StorageService::URI_VAR_KEY]);
        $devKey = trim($params[BOL_StorageService::URI_VAR_DEV_KEY]);
        $type = trim($params[BOL_StorageService::URI_VAR_ITEM_TYPE]);

        $data = $this->storageService->getItemInfoForUpdate($key, $devKey);

        if ( !$data )
        {
            $this->assign("backButton", true);
            $errMsg = $language->text("admin", "check_license_invalid_server_responce_err_msg");
            OW::getFeedback()->error($errMsg);
            $this->redirectToBackUri($params);
            $this->assign("message", $errMsg);

            return;
        }

        // if item is freeware reset check ts and redirect to back uri
        // we have not licence yet. (Author: Yaser alimardany)
        if ( true || (bool) $data[BOL_StorageService::URI_VAR_FREEWARE] )
        {
            $params[BOL_StorageService::URI_VAR_LICENSE_CHECK_COMPLETE] = 1;
            $params[BOL_StorageService::URI_VAR_LICENSE_CHECK_RESULT] = 1;
            $params[BOL_StorageService::URI_VAR_FREEWARE] = 1;
            $this->assign("message", $language->text("admin", "check_license_item_is_free_msg"));

            $dto = $this->storageService->findStoreItem($key, $devKey, $params[BOL_StorageService::URI_VAR_ITEM_TYPE]);

            if ( $dto != null )
            {
                $dto->setLicenseCheckTimestamp(null);
                $this->storageService->saveStoreItem($dto);
            }

            $this->redirectToBackUri($params);

            return;
        }

        $this->assign("text", $language->text("admin", "license_request_text", array("type" => $type, "title" => $data["title"])));

        $form = new Form("license-key");
        $licenseKey = new TextField("key");
        $licenseKey->setRequired();
        $licenseKey->setLabel($language->text("admin", "com_plugin_request_key_label"));
        $form->addElement($licenseKey);

        $submit = new Submit("submit");
        $submit->setValue($language->text("admin", "license_form_button_label"));
        $form->addElement($submit);

        if ( isset($params["back-button-uri"]) )
        {
            $button = new Button("button");
            $button->setValue($language->text("admin", "license_form_back_label"));
            $redirectUrl = UTIL_HtmlTag::escapeJs(OW_URL_HOME . urldecode($params["back-button-uri"]));
            $button->addAttribute("onclick", "window.location='{$redirectUrl}'");
            $form->addElement($button);
            $this->assign("backButton", true);
        }

        $this->addForm($form);

        if ( OW::getRequest()->isPost() )
        {
            if ( $form->isValid($_POST) )
            {
                $data = $form->getValues();
                $licenseKey = $data["key"];
                $result = $this->storageService->checkLicenseKey($key, $devKey, $licenseKey);
                $params[BOL_StorageService::URI_VAR_LICENSE_CHECK_COMPLETE] = 1;

                if ( $result )
                {
                    $params[BOL_StorageService::URI_VAR_LICENSE_CHECK_RESULT] = 1;
                    $params[BOL_StorageService::URI_VAR_LICENSE_KEY] = urlencode($licenseKey);

                    $dto = $this->storageService->findStoreItem($key, $devKey, $params[BOL_StorageService::URI_VAR_ITEM_TYPE]);

                    if ( $dto != null )
                    {
                        $dto->setLicenseKey($licenseKey);
                        $dto->setLicenseCheckTimestamp(null);
                        $this->storageService->saveStoreItem($dto);
                    }

                    OW::getFeedback()->info($language->text("admin", "plugins_manage_license_key_check_success"));
                    $this->redirectToBackUri($params);
                    $this->redirect();
                }
                else
                {
                    OW::getFeedback()->error($language->text('admin', 'plugins_manage_invalid_license_key_error_message'));
                    $this->redirect();
                }
            }
        }
    }

    /**
     * Confirm action before platform update.
     */
    public function platformUpdateRequestManually()
    {
        if(!OW::getUser()->isAuthenticated() || !OW::getUser()->isAdmin()){
            throw new Redirect404Exception();
        }
        $event = OW::getEventManager()->trigger(new OW_Event('base.on_before_update'));
        if(isset($event->getData()['disable'])){
            throw new Redirect404Exception();
        }
        $newPlatformInfo = FRMSecurityProvider::checkCoreUpdate(OW::getDbo());
        if ( $newPlatformInfo == null )
        {
            OW::getFeedback()->warning(OW::getLanguage()->text('admin', 'core_is_already_up_to_date'));
            $this->redirect(OW::getRouter()->urlForRoute("admin_default"));
        }

        $currentBuild = $newPlatformInfo['currentDbBuild'];
        $currentXmlInfo = $newPlatformInfo['currentXmlInfo'];

        $params = array(
            "oldVersion" => $currentBuild,
            "newVersion" => (int) $currentXmlInfo['build'],
            "info" => ""
        );
        $this->assign("text", OW::getLanguage()->text("admin", "manage_plugins_core_update_request_text_manually", $params));

        $urlToRedirect = OW::getRouter()->urlForRoute("admin_plugins_installed");
        if (!empty($_GET['back_uri'])) {
            $urlToRedirect = OW_URL_HOME . urldecode($_GET['back_uri']);
        }
        $this->assign("redirectUrl", OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlFor(__CLASS__, "platformUpdateManually"), array("back-uri" => $urlToRedirect)));

        $this->assign("returnUrl", OW::getRouter()->urlForRoute("admin_default"));
    }

    public function platformUpdateManually(){
        if(!OW::getUser()->isAuthenticated() || !OW::getUser()->isAdmin()){
            throw new Redirect404Exception();
        }
        $event = OW::getEventManager()->trigger(new OW_Event('base.on_before_update'));
        if(isset($event->getData()['disable'])){
            throw new Redirect404Exception();
        }
        FRMSecurityProvider::updateCoreWithDefaultDb();
        OW::getFeedback()->info(OW_Language::getInstance()->text('admin', 'updated_msg'));

        $urlToRedirect = OW::getRouter()->urlForRoute('admin_plugins_installed');
        if (!empty($_GET['back-uri'])) {
            $urlToRedirect = urldecode($_GET['back-uri']);
        }
        OW::getApplication()->redirect($urlToRedirect);
    }

    /**
     * Confirm action before platform update.
     */
    public function platformUpdateRequest()
    {
        if ( !(bool) OW::getConfig()->getValue("base", "update_soft") )
        {
            //TODO replace 404 redirect with message saying that update is not available.
            throw new Redirect404Exception();
        }
        $event = OW::getEventManager()->trigger(new OW_Event('base.on_before_update'));
        if(isset($event->getData()['disable'])){
            throw new Redirect404Exception();
        }

        $newPlatformInfo = $this->storageService->getPlatformInfoForUpdate();

        if ( !$newPlatformInfo )
        {
            return;
        }
//TODO check if the result is false | null
        $params = array(
            "oldVersion" => '(' . OW::getConfig()->getValue("base", "soft_version") . " - نسخه " . OW::getConfig()->getValue("base", "soft_build") . ')',
            "newVersion" => '(' . $newPlatformInfo["version"] . " - نسخه " . $newPlatformInfo["build"] . ')',
            "info" => $newPlatformInfo["info"]
        );
        $this->assign("text", OW::getLanguage()->text("admin", "manage_plugins_core_update_request_text", $params));
        $this->assign("redirectUrl", OW::getRouter()->urlFor(__CLASS__, "platformUpdate"));
        $this->assign("returnUrl", OW::getRouter()->urlForRoute("admin_default"));
        $this->assign("changeLog", $newPlatformInfo["log"]);

        if ( !empty($newPlatformInfo["minPhpVersion"]) && version_compare(PHP_VERSION, trim($newPlatformInfo["minPhpVersion"])) < 0 )
        {
            $this->assign("phpVersionInvalidText", OW::getLanguage()->text("admin", "plugin_update_platform_invalid_php_version_msg",
                array("version" => trim($newPlatformInfo["minPhpVersion"]))));
        }
    }

    /**
     * Updates platform.
     *
     * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
     * remove temp folders + fixed uploaded folders permissions
     */
    public function platformUpdate()
    {
        if ( !(bool) OW::getConfig()->getValue("base", "update_soft") )
        {
            throw new Redirect404Exception();
        }
        $event = OW::getEventManager()->trigger(new OW_Event('base.on_before_update'));
        if(isset($event->getData()['disable'])){
            throw new Redirect404Exception();
        }
        $language = OW::getLanguage();
        $tempDir = OW_DIR_PLUGINFILES . "ow" . DS . "core" . DS;

        $ftp = $this->getFtpConnection();

        $errorMessage = false;

        OW::getApplication()->setMaintenanceMode(true);
        $archivePath = $this->storageService->downloadPlatform();

        if ( !OW::getStorage()->fileExists($archivePath) )
        {
            $errorMessage = $language->text("admin", "core_update_download_error");
        }
        else
        {
            //remove old temp files
            if ( OW::getStorage()->fileExists($tempDir) )
            {
                UTIL_File::removeDir($tempDir);
                // in case www-data didn't have permission to remove Dir
                if ( OW::getStorage()->fileExists($tempDir) )
                {
                    $ftp->chmod_r($tempDir, 0775, 0664);
                    UTIL_File::removeDir($tempDir);
                }
            }
            if ( OW::getStorage()->fileExists($tempDir) ){
                $errorMessage = $language->text("admin", "core_update_unzip_error");
                OW::getApplication()->setMaintenanceMode(false);
                OW::getFeedback()->error($errorMessage);
                $this->redirect(OW::getRouter()->urlFor("ADMIN_CTRL_Index", "index"));
            }


            OW::getStorage()->mkdir($tempDir);
            $zip = new ZipArchive();
            $zopen = $zip->open($archivePath);

            if ( $zopen === true && OW::getStorage()->fileExists($tempDir) )
            {
                $zip->extractTo($tempDir);
                $zip->close();
                $ftp->chmod_r($tempDir, 0775, 0664);
                $ftp->uploadDir($tempDir, OW_DIR_ROOT, 0775, 0664);
                $ftp->chmod_r(OW_DIR_ROOT, 0775, 0664);
            }
            else
            {
                $errorMessage = $language->text("admin", "core_update_unzip_error");
            }
        }

        if ( OW::getStorage()->fileExists($tempDir) )
        {
            UTIL_File::removeDir($tempDir);
        }

        if ( OW::getStorage()->fileExists($archivePath) )
        {
            OW::getStorage()->removeFile($archivePath);
        }

        if ( $errorMessage !== false )
        {
            OW::getApplication()->setMaintenanceMode(false);
            OW::getFeedback()->error($errorMessage);
            $this->redirect(OW::getRouter()->urlFor("ADMIN_CTRL_Index", "index"));
        }

        $this->redirect($this->storageService->getUpdaterUrl());
    }

    /**
     * Synchronizes with update server and redirects to back URI.
     */
    public function checkUpdates()
    {
        if ( $this->storageService->checkUpdates() )
        {
            OW::getFeedback()->info(OW::getLanguage()->text("admin", "check_updates_success_message"));
        }
        else
        {
            OW::getFeedback()->error(OW::getLanguage()->text("admin", "check_updates_fail_error_message"));
        }

        $backUrl = OW::getRouter()->urlForRoute("admin_default");

        if ( isset($_GET[BOL_StorageService::URI_VAR_BACK_URI]) )
        {
            $backUrl = OW_URL_HOME . urldecode($_GET[BOL_StorageService::URI_VAR_BACK_URI]);
        }

        $this->redirect($backUrl);
    }

    /**
     * Requests local FTP attributes to update items/platform source code.
     */
    public function ftpAttrs()
    {
        OW::getEventManager()->trigger(new OW_Event('base.on_before_ftp_handle'));
        $language = OW::getLanguage();

        $this->setPageHeading($language->text("admin", "page_title_manage_plugins_ftp_info"));
        $this->setPageHeadingIconClass("ow_ic_gear_wheel");

        $ftpAttrs = null;
        if(OW::getSession()->isKeySet("ftpAttrs")){
            $ftpAttrs = OW::getSession()->get("ftpAttrs");
        }
        $form = new Form("ftp");

        $login = new TextField("host");
        $login->setValue("localhost");
        if($ftpAttrs!=null && isset($ftpAttrs['host'])){
            $login->setValue($ftpAttrs['host']);
        }
        $login->setRequired(true);
        $login->setLabel($language->text("admin", "plugins_manage_ftp_form_host_label"));
        $form->addElement($login);

        $login = new TextField("login");
        $login->setHasInvitation(true);
        $login->setInvitation("login");
        $login->setRequired(true);
        $login->setLabel($language->text("admin", "plugins_manage_ftp_form_login_label"));
        if($ftpAttrs!=null && isset($ftpAttrs['login'])){
            $login->setValue($ftpAttrs['login']);
        }
        $form->addElement($login);

        $password = new PasswordField("password");
        $password->setHasInvitation(true);
        $password->setInvitation("password");
        $password->setRequired(true);
        $password->setLabel($language->text("admin", "plugins_manage_ftp_form_password_label"));
        if($ftpAttrs!=null && isset($ftpAttrs['password'])){
            $password->setValue($ftpAttrs['password']);
        }
        $form->addElement($password);

        $port = new TextField("port");
        $port->setValue(21);
        $port->addValidator(new IntValidator());
        $port->setLabel($language->text("admin", "plugins_manage_ftp_form_port_label"));
        if($ftpAttrs!=null && isset($ftpAttrs['port'])){
            $port->setValue($ftpAttrs['port']);
        }
        $form->addElement($port);

        $submit = new Submit("submit");
        $submit->setValue($language->text("admin", "plugins_manage_ftp_form_submit_label"));
        $form->addElement($submit);

        $this->addForm($form);

        if ( OW::getRequest()->isPost() )
        {
            if ( $form->isValid($_POST) )
            {
                $data = $form->getValues();

                $ftpAttrs = array(
                    "host" => trim($data["host"]),
                    "login" => trim($data["login"]),
                    "password" => trim($data["password"]),
                    "port" => (int) $data["port"]);
                $event = OW_EventManager::getInstance()->trigger(new OW_Event('base.save_ftp_attr', array("ftpAttrs" => $ftpAttrs)));
                OW::getSession()->set("ftpAttrs", $ftpAttrs);
                $this->redirectToBackUri($_GET);
                $this->redirectToAction('index');
            }
        }
    }
}
