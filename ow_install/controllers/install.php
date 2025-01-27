<?php
class INSTALL_CTRL_Install extends INSTALL_ActionController
{
    public function init( $dispatchAttrs = null, $dbReady = null )
    {
        if ( $dbReady && $dispatchAttrs["action"] != "finish" )
        {
            $this->redirect(OW::getRouter()->urlForRoute("finish"));
        }
    }

    public function requirements()
    {
        $this->setPageHeading("نصب شبکه اجتماعی موتوشاب (مبتنی بر اکسوال)");
        
        $lines = file(INSTALL_DIR_FILES . 'requirements.txt');
        $ruleLines = array();

        foreach ( $lines as $line )
        {
            $line = trim($line);

            if ( empty($line) || strpos($line, '#') === 0 )
            {
                continue;
            }

            $ruleLines[] = $line;
        }

        $rulesContent = implode('', $ruleLines);
        $rules = explode(';', $rulesContent);
        $rules = array_filter($rules, 'trim');

        $fails = array();
        $current = array();

        foreach ( $rules as $ruleLine )
        {
            $rule = array_filter(explode(' ', $ruleLine), 'trim');

            if ( count($rule) < 2 )
            {
                continue;
            }

            $spacePos = strpos($ruleLine, ' ');
            $config = substr($ruleLine, 0, $spacePos);
            $value = substr($ruleLine, $spacePos + 1);

            switch (true)
            {
                case strpos($config, 'php.') === 0:

                    $fails['php'] = empty($fails['php']) ? null : $fails['php'];

                    $phpOption = substr($config, 4);

                    switch ( $phpOption )
                    {
                        case 'version':
                            $phpVersion = phpversion();
                            if ( version_compare($phpVersion, $value) < 1 )
                            {
                                $fails['php'][$phpOption] =  $value;
                                $current['php'][$phpOption] = $phpVersion;
                            }
                            break;

                        case 'extensions':
                            $requiredExtensions = array_map('trim', explode(',', $value));
                            $loadedExtensions = get_loaded_extensions();
                            $diff = array_values(array_diff($requiredExtensions, $loadedExtensions));
                            $checkDiff = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_BEFORE_INSTALL_EXTENSIONS_CHECK, array('diff' => $diff)));
                            if(isset($checkDiff->getData()['diff'])){
                                $diff = $checkDiff->getData()['diff'];
                            }
                            if ( !empty($diff) )
                            {
                                $fails['php'][$phpOption] = $diff;
                            }
                            break;
                    }

                break;

                case strpos($config ,'ini.') === 0:
                    $value = ( $value == 'off' || $value == '0' ) ? false : true;
                    $iniConfig = substr($config, 4);
                    $iniValue = (bool) ini_get($iniConfig);

                    if ( intval($iniValue) != intval($value) )
                    {
                        $fails['ini'][$iniConfig] = intval($value);
                        $current['ini'][$iniConfig] = intval($iniValue);
                    }
                    $fails['ini'] = empty($fails['ini']) ? null : $fails['ini'];
                    break;

                case strpos($config ,'gd.') === 0:
                    $gdOption = substr($config, 3);
                    $fails['gd'] = empty($fails['gd']) ? null : $fails['gd'];
                    
                    if ( !function_exists("gd_info") )
                    {
                        break;
                    }
                    
                    $gdInfo = gd_info();

                    switch ($gdOption)
                    {
                        case 'version':
                            preg_match( '/(\d)\.(\d)/', $gdInfo['GD Version'], $match );
                            $gdVersion = $match[1];

                            if ( $gdVersion < $value )
                            {
                                $fails['gd'][$gdOption] = $value;
                                $current['gd'][$gdOption] = $gdVersion;
                            }
                            break;

                        case 'support':

                            if ( empty($gdInfo[$value]) )
                            {
                                $fails['gd'][$gdOption] = $value;
                            }
                            break;
                    }
                    break;
            }
        }

        $this->assign('fails', $fails);
        $this->assign('current', $current);

        $checkRequirements = array_filter($fails);

        if ( empty($checkRequirements) )
        {
            $this->redirect( OW::getRouter()->urlForRoute('rules') );
        }
    }

    public function rules(){
        $this->setPageHeading("نصب شبکه اجتماعی موتوشاب (مبتنی بر اکسوال)");
        $this->setPageTitle('قوانین');
        INSTALL::getStepIndicator()->activate('rules');

        $errors = array();

        if (OW::getRequest()->isPost())
        {
            $data = $_POST;
            if(isset($data['rules_accepted'])){
                $data['rules_accepted'] = true;
            }
            $data = array_filter($data, 'trim');

            if ( empty($data['rules_accepted']) )
            {
                $errors[] = 'rules_accepted';
            }

            $this->processData($data);

            if (empty($errors))
            {
                $this->redirect( OW::getRouter()->urlForRoute('site') );
            }

            foreach ( $errors as $flag )
            {
                INSTALL::getFeedback()->errorFlag($flag);
            }

            $this->redirect();
        }
    }

    public function site()
    {
        $this->setPageHeading("نصب شبکه اجتماعی موتوشاب (مبتنی بر اکسوال)");
        $this->setPageTitle('سایت');
        INSTALL::getStepIndicator()->activate('site');

        $fieldData = array();
        $fieldData['site_url'] = OW_URL_HOME;
        $fieldData['site_path'] = OW_DIR_ROOT;

        $sessionData = INSTALL::getStorage()->getAll();
        $fieldData = array_merge($fieldData, $sessionData);

        $this->assign('data', $fieldData);

        $errors = array();

        if (OW::getRequest()->isPost())
        {
            $data = $_POST;
            $data = array_filter($data, 'trim');

            $success = true;

            if ( empty($data['site_title']) )
            {
                $errors[] = 'site_title';
            }

            if (!isset($data['site_tagline'])) {
                $data['site_tagline'] = null;
            }

            if ( empty($data['site_url']) || !trim($data['site_url']) )
            {
                $errors[] = 'site_url';
            }

            if ( empty($data['site_path']) || !is_dir($data['site_path']) )
            {
                $errors[] = 'site_path';
            }

            if ( empty($data['admin_username']) || !UTIL_Validator::isUserNameValid($data['admin_username']) )
            {
                $errors[] = 'admin_username';
            }

            if ( empty($data['admin_password']) || strlen($data['admin_password']) < 3 )
            {
                $errors[] = 'admin_password';
            }

            if ( empty($data['admin_email']) || !UTIL_Validator::isEmailValid($data['admin_email']) )
            {
                $errors[] = 'admin_email';
            }

            $this->processData($data);

            if (empty($errors))
            {
                $this->redirect( OW::getRouter()->urlForRoute('db') );
            }

            foreach ( $errors as $flag )
            {
                INSTALL::getFeedback()->errorFlag($flag);
            }

            $this->redirect();
        }
    }

    public function db()
    {
        $this->setPageTitle('پایگاه داده');
        INSTALL::getStepIndicator()->activate('db');

        $fieldData = array();
        $fieldData['db_prefix'] = 'ow_';

        $sessionData = INSTALL::getStorage()->getAll();
        $fieldData = array_merge($fieldData, $sessionData);

        $this->assign('data', $fieldData);

        $errors = array();

        if (OW::getRequest()->isPost())
        {
            $data = $_POST;
            $data = array_filter($data, 'trim');

            $success = true;

            if ( empty($data['db_host']) || !preg_match('/^[^:]+?(\:\d+)?$/', $data['db_host']))
            {
                $errors[] = 'db_host';
            }

            if ( empty($data['db_user']) )
            {
                $errors[] = 'db_user';
            }

            if ( empty($data['db_name']) )
            {
                $errors[] = 'db_name';
            }

            if ( empty($data['db_password']) )
            {
                $errors[] = 'db_password';
            }

            if ( empty($data['db_prefix']) )
            {
                $errors[] = 'db_prefix';
            }

            $this->processData($data);

            if (empty($errors))
            {
                $hostInfo = explode(':', $data['db_host']);

                try
                {
                    $dbo = OW_Database::getInstance(array(
                        'host' => $hostInfo[0],
                        'port' => empty($hostInfo[1]) ? null : $hostInfo[1],
                        'username' => $data['db_user'],
                        'password' => empty($data['db_password']) ? '' : $data['db_password'],
                        'dbname' => $data['db_name']
                    ));

                    $existingTables = $dbo->queryForColumnList("SHOW TABLES LIKE '{$data['db_prefix']}base_%'");

                    if ( !empty($existingTables) )
                    {
                        INSTALL::getFeedback()->errorMessage('پایگاه داده باید خالی باشد.');

                        $this->redirect();
                    }
                }
                catch ( InvalidArgumentException $e )
                {
                    INSTALL::getFeedback()->errorMessage('عدم توانایی در اتصال به پایگاه داده<div class="feedback_error">Error: ' . $e->getMessage() . '</div>');

                    $this->redirect();
                }
            }

            if (empty($errors))
            {
                $this->redirect( OW::getRouter()->urlForRoute('install') );
            }

            foreach ( $errors as $flag )
            {
                INSTALL::getFeedback()->errorFlag($flag);
            }

            $this->redirect();
        }
    }


    private function getConfigContent()
    {
        $configContent = file_get_contents(INSTALL_DIR_FILES . 'config.txt');
        $data = INSTALL::getStorage()->getAll();

        $hostInfo = explode(':', $data['db_host']);
        $data['db_host'] = $hostInfo[0];
        $data['db_port'] = empty($hostInfo[1]) ? 'null' : '"' . $hostInfo[1] . '"';
        $data['db_password'] = empty($data['db_password']) ? '' : $data['db_password'];
        $data['password_pepper'] = UTIL_String::getRandomString(16);

        $search = array();
        $replace = array();

        foreach ( $data as $name => $value )
        {
            $search[] = '{$' . $name . '}';
            $replace[] = $value;
        }

       return str_replace($search, $replace, $configContent);
    }

    private function isConfigFileWritable()
    {
        $configFile = OW_DIR_INC . 'config.php';
        return is_writable($configFile);
    }

    public function install( $params = array() )
    {
        $success = true;
        FRMSecurityProvider::copyInitialUsersAndPluginsFiles(OW_DIR_PLUGINFILES, OW_DIR_USERFILES);
        $configFile = OW_DIR_INC . 'config.php';

        $dirs = array(
            OW_DIR_PLUGINFILES,
            OW_DIR_USERFILES,
            OW_DIR_STATIC,
            OW_DIR_SMARTY . 'template_c' . DS,
            OW_DIR_LOG
        );

        $errorDirs = array();
        $this->checkWritable($dirs, $errorDirs);

        $doInstall = isset($params["action"]);

        if ( OW::getRequest()->isPost() || $doInstall )
        {
            if ( !empty($_POST['isConfigWritable']) )
            {
                OW::getStorage()->fileSetContent($configFile, $this->getConfigContent(), true);
                sleep(10);
                $this->redirect(OW::getRouter()->urlForRoute("install-action", array(
                    "action" => "install"
                )));
            }

            if ( !empty($errorDirs) )
            {
                //INSTALL::getFeedback()->errorMessage('Some directories are not writable');
                $this->redirect(OW::getRouter()->urlForRoute("install"));
            }

            try
            {
                OW::getDbo();
            }
            catch ( InvalidArgumentException $e )
            {
                INSTALL::getFeedback()->errorMessage('DBOError: <b>ow_includes/config.php</b> file is incorrect. Update it with details provided below: '.$e->getMessage());

                $this->redirect(OW::getRouter()->urlForRoute("install"));
            }

            try
            {
                $this->sqlImport(INSTALL_DIR_FILES . 'install.sql');
            }
            catch ( Exception $e )
            {
                INSTALL::getFeedback()->errorMessage($e->getMessage());

                $this->redirect(OW::getRouter()->urlForRoute("install"));
            }

            OW::getConfig()->saveConfig('base', 'site_installed', 0);

            BOL_LanguageService::getInstance()->updatePrefixForPlugin( 'admin', true, true);
            BOL_LanguageService::getInstance()->updatePrefixForPlugin( 'base', true, true);

            if ( isset($_POST['continue']) || $doInstall )
            {
                // allow to admin select additional plugins
                if ( $this->getPluginsForInstall(true) )
                {
                    $this->redirect(OW::getRouter()->urlForRoute('plugins'));
                }
                else
                {
                    // there are no any additional plugins
                    $installPlugins = array();
                    foreach ( $this->getPluginsForInstall() as $pluginKey => $pluginData )
                    {
                        $installPlugins[$pluginKey] = $pluginData['plugin'];
                    }

                    $this->installComplete($installPlugins);

                    return;
                }
            }
        }

        $this->setPageTitle('نصب');
        INSTALL::getStepIndicator()->activate('install');

        $this->assign('configContent', $this->getConfigContent());
        $this->assign('dirs', $errorDirs);

        $this->assign('isConfigWritable',$this->isConfigFileWritable());
    }

    private function checkWritable( $dirs, & $notWritableDirs )
    {
        foreach ( $dirs as $dir )
        {
            if ( !is_writable($dir) )
            {
                $notWritableDirs[] = substr($dir, 0, -1);

                continue;
            }

            $handle = opendir($dir);
            $subDirs = array();
            while ( ($item = readdir($handle)) !== false )
            {
                if ( $item === '.' || $item === '..' )
                {
                    continue;
                }

                $path = $dir . $item;

                if ( is_dir($path) )
                {
                    $subDirs[] = $path . DS;
                }
            }

            $this->checkWritable($subDirs, $notWritableDirs);
        }
    }

    public function plugins()
    {
        // get all plugin list
        $avaliablePlugins = $this->getPluginsForInstall();

        if ( OW::getRequest()->isPost() )
        {
            $plugins = empty($_POST['plugins']) ? array() : $_POST['plugins'];

            $installPlugins = array();

            foreach ( $plugins as $pluginKey )
            {
                if ( !empty($avaliablePlugins[$pluginKey]) )
                {
                    $installPlugins[$pluginKey] = $avaliablePlugins[$pluginKey]['plugin'];
                }
            }

            $this->installComplete($installPlugins);
        }

        INSTALL::getStepIndicator()->activate('plugins');
        $this->setPageTitle('افزونه‌ها');

        if ( empty($avaliablePlugins) )
        {
            $this->installComplete();
        }

        $this->assign('plugins', $avaliablePlugins);
    }

    public function finish()
    {
        INSTALL::getStepIndicator()->add('finish', 'مرحله امنیتی', true);
    }

    private function installComplete( $installPlugins = null )
    {
        $storage = INSTALL::getStorage();

        $username = $storage->get('admin_username');
        $password = $storage->get('admin_password');
        $email = $storage->get('admin_email');

        $user = BOL_UserService::getInstance()->createUser($username, $password, $email, null, true);

        $realName = ucfirst($username);
        BOL_QuestionService::getInstance()->saveQuestionsData(array( 'realname' => $realName ), $user->id);

        BOL_AuthorizationService::getInstance()->addAdministrator($user->id);
        OW::getUser()->login($user->id);

        OW::getConfig()->saveConfig('base', 'site_name', $storage->get('site_title'));
        OW::getConfig()->saveConfig('base', 'site_tagline', $storage->get('site_tagline'));
        OW::getConfig()->saveConfig('base', 'site_email', $email);

        $notInstalledPlugins = array();

        if ( !empty($installPlugins) )
        {
            OW::getPluginManager()->initPlugins(); // Init installed plugins ( base, admin ), to insure that all of their package pointers are added

            foreach ( $installPlugins as $plugin )
            {
                try
                {
                    BOL_PluginService::getInstance()->install($plugin['key']);
                    OW::getPluginManager()->readPluginsList();
                    OW::getPluginManager()->initPlugin(OW::getPluginManager()->getPlugin($plugin['key']));
                }
                catch ( LogicException $e )
                {
                    $notInstalledPlugins[] = $plugin['key'];
                }
            }

            if ( !empty($notInstalledPlugins) )
            {
                //Some plugins were not installed
                OW::getLogger()->writeLog(OW_Log::ERROR, 'plugins_not_installed_during_install', ['actionType'=>OW_Log::UPDATE, 'enType'=>'plugin', 'enId'=>$notInstalledPlugins]);
            }
        }

        OW::getConfig()->saveConfig('base', 'site_installed', 1);
        OW::getConfig()->saveConfig('base', 'dev_mode', 1);


        @UTIL_File::removeDir(OW_DIR_ROOT . "ow_install");

        OW::getConfig()->saveConfig("base", "install_complete", 1);
        OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_AFTER_INSTALLATION_COMPLETED));

        $this->redirect(OW::getRouter()->urlForRoute("base_page_install_completed") . "?redirect=1");
    }

    /**
     * Get plugins for install
     *
     * @param boolean $onlyOptional
     * @return array
     */
    private function getPluginsForInstall($onlyOptional = false)
    {
        $pluginForInstall = INSTALL::getPredefinedPluginList();
        $plugins = BOL_PluginService::getInstance()->getAvailablePluginsList();
        $resultPluginList = array();

        foreach ( $pluginForInstall as $pluginData )
        {
            $isAutoInstall = $pluginData['auto'];

            if ( empty($plugins[$pluginData['plugin']]) || ($onlyOptional && $isAutoInstall) )
            {
                continue;
            }

            $resultPluginList[$pluginData['plugin']] = array(
                'plugin' => $plugins[$pluginData['plugin']],
                'auto' =>  $pluginData['auto']
            );
        }

        return $resultPluginList;
    }

    /**
     * Executes an SQL dump file.
     *
     * @param $sqlFile path to file
     * @return bool
     */
    private static function sqlImport( $sqlFile )
    {
        if ( !($fd = @fopen($sqlFile, 'rb')) ) {
            throw new LogicException('SQL dump file `'.$sqlFile.'` not found');
        }

        $lineNo = 0;
        $query = '';
        while ( false !== ($line = fgets($fd, 10240)) )
        {
            $lineNo++;

            if ( !strlen(($line = trim($line)))
                || $line[0] == '#' || $line[0] == '-'
                || preg_match('~^/\*\!.+\*/;$~siu', $line) ) {
                continue;
            }

            $query .= $line;

            if ( $line[strlen($line)-1] != ';' ) {
                continue;
            }

            $query = str_replace('%%TBL-PREFIX%%', OW_DB_PREFIX, $query);

            try {
                OW::getDbo()->query($query);
            }
            catch ( Exception $e ) {
                OW::getLogger()->writeLog(OW_Log::CRITICAL, 'install import error', ['q'=>$query]);
                throw new LogicException('ImportError: <b>ow_includes/config.php</b> file is incorrect. Update it with details provided below: '
                    .$e->getMessage().'<br/>Q: '.$query);
            }

            $query = '';
        }

        fclose($fd);

        OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_AFTER_SQL_IMPORT_IN_INSTALLING));

        return true;
    }

    public function processData($data)
    {
        foreach ( $data as $name => $value )
        {
            INSTALL::getStorage()->set($name, $value);
        }
    }
}
