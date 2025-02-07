<?php
/**
 * BOL_ThemeService is main class for themes manipulation.
 *
 * @package ow_system_plugins.base.bol
 * @since 1.0
 */
class BOL_ThemeService
{
    const DEFAULT_THEME = 'frmsocialcity';
    const CSS_FILE_NAME = "base.css";
    const MOBILE_CSS_FILE_NAME = "mobile.css";
    const THEME_XML = "theme.xml";
    const PREVIEW_FILE = "theme_preview.jpg";
    const ICON_FILE = "theme.jpg";
    const CONTROL_IMAGE_MAX_FILE_SIZE_IN_MB = 2;
    const DIR_NAME_DECORATORS = "decorators";
    const DIR_NAME_IMAGES = "images";
    const DIR_NAME_MASTER_PAGES = "master_pages";
    const DIR_NAME_FONTS = "fonts";
    const DIR_NAME_MOBILE = "mobile";
    const THEME_STATUS_UP_TO_DATE = BOL_PluginDao::UPDATE_VAL_UP_TO_DATE;
    const THEME_STATUS_UPDATE = BOL_PluginDao::UPDATE_VAL_UPDATE;
    const THEME_STATUS_MANUAL_UPDATE = BOL_PluginDao::UPDATE_VAL_MANUAL_UPDATE;

    /**
     * @var BOL_ThemeDao
     */
    private $themeDao;

    /**
     * @var BOL_ThemeContentDao
     */
    private $themeContentDao;

    /**
     * @var BOL_ThemeMasterPageDao
     */
    private $themeMasterPageDao;

    /**
     * @var BOL_ThemeControlDao
     */
    private $themeControlDao;

    /**
     * @var BOL_ThemeControlValueDao
     */
    private $themeControlValueDao;

    /**
     * @var BOL_ThemeImageDao
     */
    private $themeImageDao;

    /**
     * Singleton instance.
     *
     * @var BOL_ThemeService
     */
    private static $classInstance;

    /**
     * Returns an instance of class (singleton pattern implementation).
     *
     * @return BOL_ThemeService
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
        $this->themeDao = BOL_ThemeDao::getInstance();
        $this->themeContentDao = BOL_ThemeContentDao::getInstance();
        $this->themeMasterPageDao = BOL_ThemeMasterPageDao::getInstance();
        $this->themeControlDao = BOL_ThemeControlDao::getInstance();
        $this->themeControlValueDao = BOL_ThemeControlValueDao::getInstance();
        $this->themeImageDao = BOL_ThemeImageDao::getInstance();
    }

    /**
     * Returns the name of selected theme
     * 
     * @return string
     */
    public function getSelectedThemeName()
    {
        return OW::getConfig()->getValue("base", "selectedTheme");
    }

    /**
     * Sets the name of selected theme
     * 
     * @param type $name
     */
    public function setSelectedThemeName( $name )
    {
        OW::getConfig()->saveConfig("base", "selectedTheme", trim($name));
    }

    /**
     * @return string
     */
    public function getUserfileImagesDir()
    {
        return OW_DIR_USERFILES . "themes" . DS;
    }

    /**
     * @return string
     */
    public function getUserfileImagesUrl()
    {
        return OW_URL_USERFILES . "themes/";
    }

    /**
     * Updates available theme list. Reads themes directory, adding new themes and removing deleted themes.
     */
    public function updateThemeList()
    {
        $dbThemes = $this->findAllThemes();
        $dbThemesArray = array();

        /* @var $value BOL_Theme */
        foreach ( $dbThemes as $value )
        {
            $dbThemesArray[$value->getId()] = $value->getKey();
        }

        $themes = array();
        $defaultThemeExists = false;
        $xmlFiles = UTIL_File::findFiles(OW_DIR_THEME, array("xml"), 1);

        foreach ( $xmlFiles as $themeXml )
        {
            if ( basename($themeXml) != self::THEME_XML )
            {
                continue;
            }

            $xmlInfo = $this->getThemeXmlInfo($themeXml);

            if ( !$xmlInfo )
            {
                continue;
            }

            unset($xmlInfo["masterPages"]);
            $themeKey = trim($xmlInfo["key"]);

            if ( $themeKey == self::DEFAULT_THEME )
            {
                $defaultThemeExists = true;
            }

            if ( in_array($themeKey, $dbThemesArray) )
            {
                unset($dbThemesArray[array_search($themeKey, $dbThemesArray)]);
                continue;
            }

            $result = OW::getEventManager()->call("admin.themes_list_theme_avail", array("name" => $themeKey));

            if ( $result === false )
            {
                continue;
            }

            if (!OW::getThemeManager()->getThemeService()->themeExists($themeKey)) {
                $newTheme = new BOL_Theme();
                $newTheme->setKey($themeKey);
                $newTheme->setTitle(trim($xmlInfo["name"]));
                $newTheme->setDescription(json_encode($xmlInfo));
                $newTheme->setSidebarPosition($xmlInfo["sidebarPosition"]);

                $this->themeDao->save($newTheme);
//              $this->processTheme($newTheme->getId());
                $this->updateThemeInfo($themeKey);
            }
        }

        if ( !empty($dbThemesArray) )
        {
            foreach ( $dbThemesArray as $id => $themeName )
            {
                $this->deleteTheme($id);
                if ( trim($themeName) === $this->getSelectedThemeName() )
                {
                    $this->setSelectedThemeName(self::DEFAULT_THEME);
                }
            }
        }

        if ( !$defaultThemeExists )
        {
            throw new LogicException("Cant find default theme `" . self::DEFAULT_THEME . "`!");
        }
    }

    /**
     * Deletes theme content by theme id.
     *
     * @param integer $themeId
     */
    public function deleteThemeContent( $themeId )
    {
        // delete master pages, css, decorators
        $this->themeContentDao->deleteByThemeId($themeId);

        // delete master page assignes
        $this->themeMasterPageDao->deleteByThemeId($themeId);

        // delete theme controls
        $this->themeControlDao->deleteThemeControls($themeId);
    }

    /**
     * Deletes theme by id.
     * @throws InvalidArgumentException
     *
     * @param integer $themeId
     */
    public function deleteTheme( $themeId, $deleteControlValues = false )
    {
        $theme = $this->getThemeById($themeId);

        if ( empty($theme) )
        {
            throw new InvalidArgumentException("Can't delete theme with id `" . $themeId . "`, not found!");
        }

        // delete theme static files
        $this->unlinkTheme($theme->getId());

        if ( $deleteControlValues )
        {
            $this->themeControlValueDao->deleteThemeControlValues($themeId);
            //TODO remove dirty hack
            $curentValue = json_decode(OW::getConfig()->getValue("base", "master_page_theme_info"), true);
            unset($curentValue[$themeId]);
            OW::getConfig()->getValue("base", "master_page_theme_info", json_encode($curentValue));
        }

        // delete theme DB entry
        $this->themeDao->deleteById($theme->getId());
    }

    /**
     * Removes theme static files and theme db content.
     *
     * @param integer $themeId
     * @throws InvalidArgumentException
     */
    public function unlinkTheme( $themeId )
    {
        $theme = $this->getThemeById($themeId);

        if ( empty($theme) )
        {
            throw new InvalidArgumentException("Can't unlink theme with id `" . $themeId . "`, not found!");
        }

        if ( OW::getStorage()->fileExists($this->getStaticDir($theme->getKey())) )
        {
            UTIL_File::removeDir($this->getStaticDir($theme->getKey()));
        }

        $this->deleteThemeContent($theme->getId());
    }

    /**
     * Updates content of all themes registered in DB.
     */
    public function processAllThemes()
    {
        $themes = $this->findAllThemes();

        /* @var $value BOL_Theme */
        foreach ( $themes as $value )
        {
            $this->processTheme($value->getId());
            $this->updateCustomCssFile($value->getId());
        }
    }

    public function processAllUpdatedThemes(){
        $this->updateThemeList();
        $themes = $this->findAllThemes();
        $themeArray = array();
        foreach ( $themes as $theme ) {
            $themeArray[$theme->key] = array("build" => $theme->build, "id" => $theme->id);
        }
        $xmlFiles = UTIL_File::findFiles(OW_DIR_THEME, array("xml"), 1);
        foreach ( $xmlFiles as $themeXml ) {
            if (basename($themeXml) != self::THEME_XML) {
                continue;
            }
            $xmlInfo = UTIL_String::xmlToArray(OW::getStorage()->fileGetContent($themeXml));
            if(!isset($xmlInfo["build"])){
                continue;
            }
            $build = (int) $xmlInfo["build"];
            $key = $xmlInfo["key"];
            if (!$build || !isset($themeArray[$key]["build"])) {
                continue;
            }

            if($build > $themeArray[$key]["build"]){
                $this->updateThemeInfo($key);
                $this->updateCustomCssFile($themeArray[$key]["id"]);
            }
        }
    }

    /**
     * Updates/adds whole theme content, generating static files and inserting theme content in DB.
     *
     * @param int $id
     */
    public function processTheme( $id )
    {
        $theme = $this->getThemeById($id);

        if ( empty($theme) )
        {
            throw new InvalidArgumentException("Can't process theme with id `" . $id . "`, not found!");
        }

        $themeName = $theme->getKey();

        if ( !OW::getStorage()->fileExists($this->getRootDir($themeName)) )
        {
            throw new LogicException("Can't find theme dir for `" . $themeName . "`!");
        }

        $themeStaticDir = $this->getStaticDir($themeName);
        $themeRootDir = $this->getRootDir($themeName);
        $mobileRootDir = $this->getRootDir($themeName, true);

        $deleteAllFiles = true;
        $deleteAllFilesEvent = OW::getEventManager()->trigger(new OW_Event('base.on_before_theme_files_delete'));
        if(isset($deleteAllFilesEvent->getData()['doNotDelete'])){
            $deleteAllFiles = false;
        }

        if($deleteAllFiles) {
            // deleting DB entries and files
            $this->unlinkTheme($theme->getId());
            OW::getStorage()->mkdir($themeStaticDir);

            // copy all static files
            UTIL_File::copyDir($themeRootDir, $this->getStaticDir($themeName),
                function ($itemPath) {
                    if (substr($itemPath, 0, 1) == ".") {
                        return false;
                    }

                    if (OW::getStorage()->isDir($itemPath)) {
                        return true;
                    }

                    $fileExtension = strtolower(UTIL_File::getExtension(basename($itemPath)));

                    if (in_array($fileExtension, array("psd", "html"))) {
                        return false;
                    }

                    return true;
                }
            );
        }

        $themeControls = array();

        // copy main css file
        if ( OW::getStorage()->fileExists($themeRootDir . self::CSS_FILE_NAME) )
        {
            $controlsContent = OW::getStorage()->fileGetContent($themeRootDir . self::CSS_FILE_NAME);
            $themeControls = $this->getThemeControls($controlsContent);
            $mobileControls = array();

            if ( OW::getStorage()->fileExists($mobileRootDir . self::CSS_FILE_NAME) )
            {
                $mobileControls = $this->getThemeControls(OW::getStorage()->fileGetContent($mobileRootDir . self::CSS_FILE_NAME));

                foreach ( $mobileControls as $key => $val )
                {
                    $mobileControls[$key]["mobile"] = true;
                }
            }

            $themeControls = array_merge($mobileControls, $themeControls);

            // adding theme controls in DB
            if ( !empty($themeControls) )
            {
                foreach ( $themeControls as $value )
                {
                    $themeControl = new BOL_ThemeControl();
                    $themeControl->setAttribute($value["attrName"]);
                    $themeControl->setKey($value["key"]);
                    $themeControl->setSection($value["section"]);
                    $themeControl->setSelector($value["selector"]);
                    $themeControl->setThemeId($theme->getId());
                    $themeControl->setDefaultValue($value["defaultValue"]);
                    $themeControl->setType($value["type"]);
                    $themeControl->setLabel($value["label"]);
                    if ( isset($value["description"]) )
                    {
                        $themeControl->setDescription(trim($value["description"]));
                    }

                    $themeControl->setMobile(!empty($value["mobile"]));
                    $this->themeControlDao->save($themeControl);
                }
            }
        }

        // decorators
        if ( OW::getStorage()->fileExists($this->getDecoratorsDir($themeName)) )
        {
            $files = UTIL_File::findFiles($this->getDecoratorsDir($themeName), array("html"), 0);

            foreach ( $files as $value )
            {
                $decoratorEntry = new BOL_ThemeContent();
                $decoratorEntry->setThemeId($theme->getId());
                $decoratorEntry->setType(BOL_ThemeContentDao::VALUE_TYPE_ENUM_DECORATOR);
                $decoratorEntry->setValue(UTIL_File::stripExtension(basename($value)));
                $this->themeContentDao->save($decoratorEntry);
            }
        }

        // master pages
        if ( OW::getStorage()->fileExists($this->getMasterPagesDir($themeName)) )
        {
            $files = UTIL_File::findFiles($this->getMasterPagesDir($themeName), array("html"), 0);

            foreach ( $files as $value )
            {
                $masterPageEntry = new BOL_ThemeContent();
                $masterPageEntry->setThemeId($theme->getId());
                $masterPageEntry->setType(BOL_ThemeContentDao::VALUE_TYPE_ENUM_MASTER_PAGE);
                $masterPageEntry->setValue(UTIL_File::stripExtension(basename($value)));
                $this->themeContentDao->save($masterPageEntry);
            }
        }

        if ( OW::getStorage()->fileExists($this->getMasterPagesDir($themeName, true)) )
        {
            $files = UTIL_File::findFiles($this->getMasterPagesDir($themeName, true), array("html"), 0);

            foreach ( $files as $value )
            {
                $masterPageEntry = new BOL_ThemeContent();
                $masterPageEntry->setThemeId($theme->getId());
                $masterPageEntry->setType(BOL_ThemeContentDao::VALUE_TYPE_ENUM_MOBILE_MASTER_PAGE);
                $masterPageEntry->setValue(UTIL_File::stripExtension(basename($value)));
                $this->themeContentDao->save($masterPageEntry);
            }
        }

        // xml master page assignes
        $xml = simplexml_load_file($this->getRootDir($themeName) . self::THEME_XML);
        $masterPages = (array) $xml->masterPages;

        foreach ( $masterPages as $key => $value )
        {
            $masterPageLinkEntry = new BOL_ThemeMasterPage();
            $masterPageLinkEntry->setThemeId($theme->getId());
            $masterPageLinkEntry->setDocumentKey(trim($key));
            $masterPageLinkEntry->setMasterPage(trim($value));
            $this->themeMasterPageDao->save($masterPageLinkEntry);
        }

        //update child themes in frmthememanagerplugin
        OW::getEventManager()->trigger(new OW_Event('frmthememanager.update.all.child.themes.from.parent.theme', array('themeKey'=>$id)));
    }

    /**
     * Returns theme object by name.
     *
     * @param string $key
     * @return OW_Theme
     */
    public function getThemeObjectByKey( $key, $mobile = false )
    {
        $theme = $this->themeDao->findByKey($key);

        if ( $theme === null )
        {
            throw new InvalidArgumentException('Cant find theme `' . $key . '` in DB!');
        }

        return $this->getThemeObject($theme, $mobile);
    }

    /**
     * Generates theme object for theme manager (OW_Theme).
     *
     * @param BOL_Theme $theme
     * @return OW_Theme
     */
    private function getThemeObject( BOL_Theme $theme, $mobile = false )
    {
        $themeContentArray = $this->themeContentDao->findByThemeId($theme->getId());
        $documentMasterPagesArray = $this->themeMasterPageDao->findByThemeId($theme->getId());

        $decorators = array();
        $masterPages = array();
        $cssFiles = array();
        $documentMasterPages = array();

        /* @var $value BOL_ThemeContent */
        foreach ( $themeContentArray as $value )
        {
            if ( $value->getType() === BOL_ThemeContentDao::VALUE_TYPE_ENUM_DECORATOR )
            {
                $decorators[$value->getValue()] = $this->getDecoratorsDir($theme->getKey()) . $value->getValue() . ".html";
            }
            else if ( $value->getType() === BOL_ThemeContentDao::VALUE_TYPE_ENUM_MASTER_PAGE )
            {
                if ( !$mobile )
                {
                    $masterPages[$value->getValue()] = $this->getMasterPagesDir($theme->getKey()) . $value->getValue() . ".html";
                }
            }
            else if ( $value->getType() === BOL_ThemeContentDao::VALUE_TYPE_ENUM_MOBILE_MASTER_PAGE )
            {
                if ( $mobile )
                {
                    $masterPages[$value->getValue()] = $this->getMasterPagesDir($theme->getKey(), true) . $value->getValue() . ".html";
                }
            }
            else
            {
                throw new LogicException("Invalid theme content type `" . $value->getType() . "`");
            }
        }

        /* @var $value BOL_ThemeMasterPage */
        foreach ( $documentMasterPagesArray as $value )
        {
            $documentMasterPages[$value->getDocumentKey()] = $value->getMasterPage();
        }

        $themeObj = new OW_Theme($theme);
        $themeObj->setDecorators($decorators);
        $themeObj->setDocumentMasterPages($documentMasterPages);
        $themeObj->setMasterPages($masterPages);

        return $themeObj;
    }

    /**
     * Returns list of theme controls.
     *
     * @param string $fileContents
     * @return array
     */
    public function getThemeControls( $fileContents )
    {
        $pattern = "/\/\*\*[ ]*OW_Control(.*?)[ ]*\*\*\//";

        $pockets = array();

        $resultArray = array();

        if ( !preg_match_all($pattern, $fileContents, $pockets) )
        {
            return array();
        }

        foreach ( $pockets[0] as $key => $value )
        {
            $controlPosition = strpos($fileContents, $value);
            $fileContents = substr_replace($fileContents, '', strpos($fileContents, $value), strlen($value));

            $firstSemicolon = true;
            $firstSemicolonPosition = false;
            $firstColon = true;

            for ( $i = $controlPosition; $i >= 0; $i-- )
            {
                $char = substr($fileContents, $i, 1);

                // first semicolon is attr devider
                if ( $firstSemicolon && $char === ":" )
                {
                    $attrValue = trim(str_replace(";", "",
                            substr($fileContents, ($i + 1), ($controlPosition - ($i + 1)))));
                    $firstSemicolon = false;
                    $firstSemicolonPosition = $i;
                    continue;
                }

                if ( $firstSemicolonPosition && $firstColon && ( $char === ";" || $char === "{" ) )
                {
                    $attrName = trim(substr($fileContents, ($i + 1), ($firstSemicolonPosition - ($i + 1))));
                    $firstColon = false;
                }

                // selector start position
                if ( $char === "{" )
                {
                    $selectorEndPos = $i;
                }

                // selector end position
                if ( $char === "}" )
                {
                    $selector = trim(substr($fileContents, ($i + 1), ($selectorEndPos - ($i + 1))));
                    break;
                }
            }

            $tempStr = substr(trim($pockets[1][$key]), ( strpos(trim($pockets[1][$key]), "key") + 4));

            $controlKey = trim(strstr($tempStr, ",") ? substr($tempStr, 0, strpos($tempStr, ",")) : trim($tempStr));

            if ( empty($controlKey) )
            {
                continue;
            }

            if(!isset($selector)){
                $selector = '';
            }

            $itemArray = array(
                "attrName" => $this->removeCssComments($attrName),
                "defaultValue" => $this->removeCssComments($attrValue),
                "selector" => $this->removeCssComments($selector)
            );

            $params = explode(",", $pockets[1][$key]);

            foreach ( $params as $value )
            {
                $tempArray = explode(":", $value);
                $itemArray[trim($tempArray[0])] = trim($tempArray[1]);
            }

            if ( array_key_exists($controlKey, $resultArray) )
            {
                $resultArray[$controlKey]["selector"] .= ", " . $itemArray["selector"];

                continue;
            }

            if ( empty($itemArray["type"]) )
            {
                continue;
            }

            // temp fix to get rid of quotes
            if ( $itemArray["type"] == "image" )
            {
                $itemArray["defaultValue"] = str_replace("'", "", $itemArray["defaultValue"]);
            }

            $resultArray[$controlKey] = $itemArray;
        }

        return $resultArray;
    }

    /**
     * @param integer $themeId
     * @return array
     */
    public function findThemeControls( $themeId )
    {
        return $this->themeControlDao->findThemeControls($themeId);
    }

    /**
     *
     * @param integer $themeId
     * @param array $values
     */
    public function importThemeControls( $themeId, $values )
    {
        $controls = $this->findThemeControls($themeId);
        $namedControls = array();

        foreach ( $controls as $value )
        {
            $namedControls[$value['key']] = $value;
        }

        foreach ( $values as $key => $value )
        {
            if ( !array_key_exists($key, $namedControls) )
            {
                continue;
            }

            $obj = $this->themeControlValueDao->findByTcNameAndThemeId($namedControls[$key]['key'], $themeId);

            if ( $obj === null )
            {
                $obj = new BOL_ThemeControlValue();
                $obj->setThemeControlKey($namedControls[$key]['key']);
            }

            $obj->setValue(trim($value));
            $obj->setThemeId($themeId);
            $this->themeControlValueDao->save($obj);
        }
    }

    /**
     * @param integer $themeId
     * @param array $values
     */
    public function saveThemeControls( $themeId, $values )
    {
        $controls = $this->findThemeControls($themeId);
        $namedControls = array();

        foreach ( $controls as $value )
        {
            $namedControls[$value["key"]] = $value;
        }

        foreach ( $values as $key => $value )
        {
            if ( !array_key_exists($key, $namedControls) || ( is_array($value) && empty($value) ) )
            {
                continue;
            }

            if ( is_string($value) && in_array(trim($value),
                    array("default", trim($namedControls[$key]["defaultValue"]))) )
            {
                $this->themeControlValueDao->deleteByTcNameAndThemeId($namedControls[$key]["key"], $themeId);
                continue;
            }

            $obj = $this->themeControlValueDao->findByTcNameAndThemeId($namedControls[$key]["key"], $themeId);

            if ( $namedControls[$key]["type"] == "image" )
            {
                list($width, $height) = getimagesize($value["tmp_name"]);

                $image = $this->addImage($value);

                if ( $image === null )
                {
                    continue;
                }

                $value = "url(" . OW::getStorage()->getFileUrl($this->getUserfileImagesDir() . $image->getFilename()) . ")";

                //TODO remove hotfix temp solution for assigning theme img data in master pages
                $curentValue = json_decode(OW::getConfig()->getValue("base", "master_page_theme_info"), true);
                $curentValue[$themeId][$namedControls[$key]["key"]] = array("src" => OW::getStorage()->getFileUrl($this->getUserfileImagesDir() . $image->getFilename()),
                    "width" => $width, "height" => $height);
                OW::getConfig()->saveConfig("base", "master_page_theme_info", json_encode($curentValue));
            }

            if ( $obj === null )
            {
                $obj = new BOL_ThemeControlValue();
                $obj->setThemeControlKey($namedControls[$key]["key"]);
            }

            $obj->setValue(trim($value));
            $obj->setThemeId($themeId);
            $this->themeControlValueDao->save($obj);
        }
    }

    /**
     * 
     * @param string $fileArr
     * @return \BOL_ThemeImage
     */
    public function addImage( $fileArr )
    {
        $result = UTIL_File::checkUploadedFile($fileArr, self::CONTROL_IMAGE_MAX_FILE_SIZE_IN_MB * 1024 * 1024);

        if ( !$result["result"] )
        {
            if($fileArr["error"] != UPLOAD_ERR_NO_FILE){
                OW::getFeedback()->error($result["message"]);
            }
            return null;
        }

        if ( !UTIL_File::validateImage($fileArr["name"]) )
        {
            return null;
        }

        $image = new BOL_ThemeImage();
        $image->addDatetime = time();
        $image->title = $fileArr["name"];
        $this->themeImageDao->save($image);

        $ext = UTIL_File::getExtension($fileArr["name"]);
        $imageName = "theme_image_" . $image->getId() . "." . $ext;

        //cloudfiles header fix for amazon : need right extension to upload file with right header
        $newTempName = $fileArr["tmp_name"] . "." . $ext;
        OW::getStorage()->renameFile($fileArr['tmp_name'], $newTempName);
        $imagePath = $this->getUserfileImagesDir() . $imageName;
        OW::getStorage()->copyFile($newTempName, $imagePath);

        if ( OW::getStorage()->fileExists($newTempName) )
        {
            OW::getStorage()->removeFile($newTempName);
        }

        $image->setFilename($imageName);
        if(OW::getStorage()->fileExists($imagePath)){
            $dimensions = getimagesize($imagePath);
            $image->dimensions = "{$dimensions[0]}x{$dimensions[1]}";
            $image->filesize = UTIL_File::getFileSize($imagePath);;
        }
        $this->themeImageDao->save($image);

        return $image;
    }

    public function moveTemporaryFile( $tmpId, $title = '' )
    {
        $tmp = BOL_FileTemporaryDao::getInstance()->findById($tmpId);
        $tmpPath = BOL_FileTemporaryService::getInstance()->getTemporaryFilePath($tmpId);

        if ( !$tmp )
        {
            throw new LogicException();
        }

        if ( !UTIL_File::validateImage($tmp->filename) )
        {
            throw new LogicException();
        }

        $image = new BOL_ThemeImage();
        $image->addDatetime = time();
        $image->title = $title;
        $dimensions = getimagesize($tmpPath);
        $image->dimensions = "{$dimensions[0]}x{$dimensions[1]}";
        $image->filesize = UTIL_File::getFileSize($tmpPath);
        $this->themeImageDao->save($image);

        $ext = UTIL_File::getExtension($tmp->filename);
        $imageName = 'theme_image_' . $image->getId() . '.' . $ext;

        $newTempName = $tmp->filename . '.' . $ext;
        OW::getStorage()->renameFile($tmp->filename, $newTempName);
        OW::getStorage()->copyFile($tmpPath, $this->getUserfileImagesDir() . $imageName);
        if ( OW::getStorage()->fileExists($newTempName) )
        {
            OW::getStorage()->removeFile($newTempName);
        }

        BOL_FileTemporaryDao::getInstance()->deleteById($tmpId);

        $image->setFilename($imageName);
        $this->themeImageDao->save($image);

        return $image;
    }

    /**
     * @return array
     */
    public function findAllCssImages()
    {
        return $this->themeImageDao->findGraphics();
    }

    /**
     * @param array $params
     * @return array
     */
    public function filterCssImages( $params )
    {
        $storage = OW::getStorage();
        $images = $this->themeImageDao->filterGraphics($params);
        foreach ( $images as $key => $photo )
        {
            $images[$key]->url = $storage->getFileUrl($this->getUserfileImagesDir() . $photo->filename);
        }
        return $images;
    }

    /**
     *
     * @param integer $id
     * @return BOL_ThemeImage
     */
    public function findImageById( $id )
    {
        return $this->themeImageDao->findById($id);
    }

    /**
     * @param $id
     * @param $params
     * @return array
     */
    public function getPrevImageIdList( $id, $params )
    {
        $images = $this->themeImageDao->getPrevImageList($id, $params);
        return array_map(function($i)
        {
            return $i->id;
        }, $images);
    }

    /**
     * @param $id
     * @param $params
     * @return array
     */
    public function getNextImageIdList( $id, $params )
    {
        $images = $this->themeImageDao->getNextImageList($id, $params);
        return array_map(function($i)
        {
            return $i->id;
        }, $images);
    }

    /**
     *
     * @param integer $id
     */
    public function deleteImage( $id )
    {
        $image = $this->themeImageDao->findById($id);

        if ( $image !== null )
        {
            if ( OW::getStorage()->fileExists($this->getUserfileImagesDir() . $image->getFilename()) )
            {
                OW::getStorage()->removeFile($this->getUserfileImagesDir() . $image->getFilename());
            }

            $this->themeImageDao->delete($image);
        }
    }

    /**
     * @param BOL_Theme $themeDto
     */
    public function saveTheme( BOL_Theme $themeDto )
    {
        $this->themeDao->save($themeDto);
    }

    /**
     * Saves and updates BOL_ThemeContent objects
     *
     * @param BOL_ThemeContent $dto
     */
    public function saveThemeContent( BOL_ThemeContent $dto )
    {
        $this->themeContentDao->save($dto);
    }

    /**
     * Returns all available themes
     * @return array<BOL_Theme>
     */
    public function findAllThemes()
    {
        return $this->themeDao->findAll();
    }

    /**
     *
     * @param integer $themeId
     */
    public function updateCustomCssFile( $themeId )
    {
        $theme = $this->themeDao->findById($themeId);

        if ( $theme->getCustomCssFileName() !== null )
        {
            $oldCssFilePath = $this->getUserfileImagesDir() . $theme->getCustomCssFileName();
            $oldMobileCssFilePath = $this->getUserfileImagesDir() . "mobile_" . $theme->getCustomCssFileName();

            if ( OW::getStorage()->fileExists($oldCssFilePath) )
            {
                OW::getStorage()->removeFile($oldCssFilePath);
            }

            if ( OW::getStorage()->fileExists($oldMobileCssFilePath) )
            {
                OW::getStorage()->removeFile($oldMobileCssFilePath);
            }
        }

        if ( $theme === null )
        {
            throw new InvalidArgumentException("Can't find theme `" . $themeId . "` !");
        }

        $controls = $this->themeControlDao->findThemeControls($theme->getId());

        if ( !$this->themeControlValueDao->findByThemeId($themeId) && !$theme->getCustomCss()  && !$theme->getMobileCustomCss() )
        {
            $theme->setCustomCssFileName(null);
            $this->themeDao->save($theme);
            return;
        }

        $cssString = "";
        $mobileCssString = "";

        foreach ( $controls as $control )
        {
            if ( $control["value"] !== null && trim($control["value"]) !== "default" )
            {
                $controlString = $control["selector"] . "{" . $control["attribute"] . ":" . $control["value"] . "}" . PHP_EOL;

                if ( (bool) $control["mobile"] )
                {
                    $mobileCssString .= $controlString;
                }
                else
                {
                    $cssString .= $controlString;
                }
            }
        }

        if ( $theme->getCustomCss() !== null && strlen(trim($theme->getCustomCss())) > 0 )
        {
            $cssString .= trim($theme->getCustomCss());
        }

        if ( $theme->getMobileCustomCss() !== null && strlen(trim($theme->getMobileCustomCss())) > 0 )
        {
            $mobileCssString .= trim($theme->getMobileCustomCss());
        }

        $newCssFileName = FRMSecurityProvider::generateUniqueId($theme->getName()) . ".css";

        $theme->setCustomCssFileName($newCssFileName);
        $this->themeDao->save($theme);

        $newCssFilePath = $this->getUserfileImagesDir() . $newCssFileName;
        $tempCssFilePath = $this->getUserfileImagesDir() . "temp.css";
        OW::getStorage()->fileSetContent($tempCssFilePath, $cssString);
        OW::getStorage()->copyFile($tempCssFilePath, $newCssFilePath);
        OW::getStorage()->removeFile($tempCssFilePath);

        $tempCssFilePath = $this->getUserfileImagesDir() . "tempmobile.css";
        $newCssFileName = 'mobile_' . $newCssFileName;
        $newCssFilePath = $this->getUserfileImagesDir() . $newCssFileName;
        OW::getStorage()->fileSetContent($tempCssFilePath, $mobileCssString);
        OW::getStorage()->copyFile($tempCssFilePath, $newCssFilePath);
        OW::getStorage()->removeFile($tempCssFilePath);

        OW::getEventManager()->trigger(new OW_Event("base.update_custom_css_file", array("name" => $theme->getName())));
    }

    /**
     *
     * @param string $themeName
     * @return string
     */
    public function getCustomCssFileUrl( $themeName, $mobile = false )
    {
        $theme = $this->themeDao->findByKey(trim($themeName));

        if ( $theme === null )
        {
            return null;
        }

        return OW::getStorage()->getFileUrl($this->getUserfileImagesDir() . ( $mobile ? "mobile_" : '' ) . $theme->getCustomCssFileName());
    }

    /**
     *
     * @param string $key
     * @return BOL_Theme
     */
    public function findThemeByKey( $key )
    {
        return $this->themeDao->findByKey(trim($key));
    }

    /**
     * Checks if theme exists.
     *
     * @param string $key
     * @return boolean
     */
    public function themeExists( $key )
    {
        $dto = $this->findThemeByKey(trim($key));

        return ($dto !== null);
    }

    /**
     * Removes all css comments and returns result string.
     *
     * @param strign $string
     * @return string
     */
    private function removeCssComments( $string )
    {
        return trim(preg_replace("/[\s\S]*?\*\//", "", preg_replace("/\/\*[\s\S]*?\*\//", '', $string)));
    }

    /**
     *
     * @param integer $themeId
     */
    public function resetTheme( $themeId )
    {
        $this->themeControlValueDao->deleteThemeControlValues($themeId);
        $controls = $this->themeControlValueDao->findByThemeId($themeId);

        /* @var $control BOL_ThemeControlValue */
        foreach ( $controls as $control )
        {
            if ( strstr($control->getValue(), "url") )
            {
                $this->unlinkControlValueImage($control->getValue());
            }
        }
        //TODO remake temp fix
        $curentValue = json_decode(OW::getConfig()->getValue("base", "master_page_theme_info"), true);
        unset($curentValue[$themeId]);
        OW::getConfig()->saveConfig("base", "master_page_theme_info", json_encode($curentValue));
        $this->updateCustomCssFile($themeId);
    }

    /**
     * Returns theme root path in static dir.
     *
     * @param string $themeName
     * @return string
     */
    public function getStaticDir( $themeName, $mobile = false )
    {
        return OW_DIR_STATIC_THEME . $themeName . ($mobile ? DS . self::DIR_NAME_MOBILE : '') . DS;
    }

    /**
     * Returns theme static root url.
     *
     * @param string $themeName
     * @return string
     */
    public function getStaticUrl( $themeName, $mobile = false )
    {
        return OW_URL_STATIC_THEMES . $themeName . ($mobile ? '/' . self::DIR_NAME_MOBILE : '') . '/';
    }

    /**
     * Returns theme images path in static dir.
     *
     * @param $themeName
     * @return string
     */
    public function getStaticImagesDir( $themeName, $mobile = false )
    {
        return $this->getStaticDir($themeName, $mobile) . self::DIR_NAME_IMAGES . DS;
    }

    /**
     * Returns theme images url.
     *
     * @param string $themeName
     * @return string
     */
    public function getStaticImagesUrl( $themeName, $mobile = false )
    {
        return $this->getStaticUrl($themeName, $mobile) . self::DIR_NAME_IMAGES . '/';
    }

    /**
     * Returns root dir path in themes dir.
     *
     * @param string $themeName
     * @return string
     */
    public function getRootDir( $themeName, $mobile = false )
    {
        return OW_DIR_THEME . $themeName . ($mobile ? DS . self::DIR_NAME_MOBILE : '') . DS;
    }

    /**
     * Returns decorators dir path in themes dir.
     *
     * @param string $themeName
     * @return string
     */
    public function getDecoratorsDir( $themeName )
    {
        return $this->getRootDir($themeName) . "decorators" . DS;
    }

    /**
     * Returns master page dir path in themes dir.
     *
     * @param string $themeName
     * @return string
     */
    public function getMasterPagesDir( $themeName, $mobile = false )
    {
        return $this->getRootDir($themeName, $mobile) . "master_pages" . DS;
    }

    /**
     * Returns images dir path in themes dir.
     *
     * @param string $themeName
     * @return string
     */
    public function getImagesDir( $themeName, $mobile = false )
    {
        return $this->getRootDir($themeName, $mobile) . "images" . DS;
    }

    /**
     * Removes image control value.
     *
     * @param integer $themeId
     * @param string $controlName
     */
    public function resetImageControl( $themeId, $controlName )
    {
        $controlValue = $this->themeControlValueDao->findByTcNameAndThemeId($controlName, $themeId);

        if ( $controlValue !== null )
        {
            $this->unlinkControlValueImage($controlValue->getValue());
            $this->themeControlValueDao->delete($controlValue);
        }

        //TODO remove dirty hack
        $curentValue = json_decode(OW::getConfig()->getValue("base", "master_page_theme_info"), true);
        unset($curentValue[$themeId][$controlName]);
        OW::getConfig()->saveConfig("base", "master_page_theme_info", json_encode($curentValue));

        $this->updateCustomCssFile($themeId);
    }

    /**
     * Checks if theme exists.
     *
     * @param type $themeId
     * @return BOL_Theme
     */
    private function getThemeById( $id )
    {
        $theme = $this->themeDao->findById($id);

        if ( $theme === null )
        {
            throw new InvalidArgumentException("Can't find theme `" . $id . "` in DB!");
        }

        return $theme;
    }

    /**
     * Update theme info in the [OW_DB_PREFIX]_base_theme table according to the theme.xml file and force theme rebuilding if necessary
     * @param $name
     * @param bool $processTheme
     */
    public function updateThemeInfo( $name, $processTheme = false )
    {
        $xmlInfo = $this->getThemeXmlInfoForKey($name);

        if ( empty($xmlInfo) )
        {
            return;
        }

        $themeDto = $this->findThemeByKey($name);

        if ( empty($themeDto) )
        {
            return;
        }

        $themeDto->setKey($xmlInfo["key"]);
        $themeDto->setTitle($xmlInfo["name"]);
        $themeDto->setDescription(json_encode($xmlInfo));
        $themeDto->setSidebarPosition($xmlInfo["sidebarPosition"]);
        $themeDto->setDeveloperKey($xmlInfo["developerKey"]);

        if ( $themeDto->getBuild() < $xmlInfo["build"] )
        {
            $themeDto->setBuild($xmlInfo["build"]);
            $themeDto->setUpdate(self::THEME_STATUS_UP_TO_DATE);
            $processTheme = true;
        }

        $this->themeDao->save($themeDto);

        if ( $processTheme )
        {
            $this->processTheme($themeDto->getId());
        }
    }

    /**
     * Update theme info for all themes
     */
    public function updateThemesInfo()
    {
        $dbThemes = $this->findAllThemes();

        /* @var $theme BOL_Theme */
        foreach ( $dbThemes as $theme )
        {
            $this->updateThemeInfo($theme->getKey());
        }
    }

    /**
     * Checks if source of any theme was updated and rebuilds them.
     */
    public function checkManualUpdates()
    {
        $themes = $this->findAllThemes();

        /* @var $theme BOL_Theme */
        foreach ( $themes as $theme )
        {
            $themeInfo = $this->getThemeXmlInfoForKey($theme->getKey());

            if ( empty($themeInfo) )
            {
                continue;
            }

            if ( $themeInfo["build"] > $theme->getBuild() )
            {
                $this->updateThemeInfo($theme->getKey());
            }
        }
    }

    /**
     * Returns the number of themes with available updates 
     * 
     * @return int
     */
    public function getThemesToUpdateCount()
    {
        return $this->themeDao->findThemesForUpdateCount();
    }

    /**
     * Returns themes with invalid license key
     * 
     * @return array
     */
    public function findItemsWithInvalidLicense()
    {
        return $this->themeDao->findItemsWithInvalidLicense();
    }

    /**
     * Returns theme xml info for provided key
     * 
     * @param string $key
     * @return array
     */
    public function getThemeXmlInfoForKey( $key )
    {
        return $this->getThemeXmlInfo($this->getRootDir(trim($key)) . self::THEME_XML);
    }
    /* -------------------------------------------------------------------------------------------------------------- */

    private function getThemeXmlInfo( $themeXmlPath )
    {
        if ( !OW::getStorage()->fileExists($themeXmlPath) )
        {
            OW::getLogger()->writeLog(OW_Log::WARNING, "getThemeXmlInfo: " . __CLASS__ . "::" . __FUNCTION__ . " - `" . $themeXmlPath . "` not found", ['actionType'=>OW_Log::READ, 'enType'=>'theme', 'path'=>$themeXmlPath]);
            return null;
        }

        //$propList = array("key", "developerKey", "name", "description", "license", "author", "build", "copyright", "licenseUrl");
        $propList = array("key", "name", "description");
        $xmlInfo = UTIL_String::xmlToArray(OW::getStorage()->fileGetContent($themeXmlPath));

        //TODO refactor
        if ( empty($xmlInfo["developerKey"]) )
        {
            $xmlInfo["developerKey"] = null;
        }

        if ( empty($xmlInfo["build"]) )
        {
            $xmlInfo["build"] = 0;
        }

        if ( !$xmlInfo )
        {
            OW::getLogger()->writeLog( OW_Log::WARNING, "getThemeXmlInfo: " . __CLASS__ . "::" . __FUNCTION__ . " - invalid `" . $themeXmlPath . "`", ['actionType'=>OW_Log::READ, 'enType'=>'theme', 'path'=>$themeXmlPath]);
            return null;
        }

        foreach ( $propList as $prop )
        {
            if ( empty($xmlInfo[$prop]) )
            {
                OW::getLogger()->writeLog(OW_Log::WARNING, "getThemeXmlInfo: " . __CLASS__ . "::" . __FUNCTION__ . " - in `" . $themeXmlPath . "` property `" . $prop . "` not found", ['actionType'=>OW_Log::READ, 'enType'=>'theme', 'path'=>$themeXmlPath]);
                return null;
            }
        }

        $sidebarPositions = array(BOL_ThemeDao::VALUE_SIDEBAR_POSITION_LEFT, BOL_ThemeDao::VALUE_SIDEBAR_POSITION_RIGHT,
            BOL_ThemeDao::VALUE_SIDEBAR_POSITION_NONE);

        if ( empty($xmlInfo["sidebarPosition"]) || !in_array($xmlInfo["sidebarPosition"], $sidebarPositions) )
        {
            $xmlInfo["sidebarPosition"] = BOL_ThemeDao::VALUE_SIDEBAR_POSITION_NONE;
        }

        $xmlInfo["build"] = (int) $xmlInfo["build"];

        return $xmlInfo;
    }

    private function unlinkControlValueImage( $controlValue )
    {
        $fileName = basename(str_replace(")", "", $controlValue));

        if ( OW::getStorage()->fileExists($this->getUserfileImagesDir() . $fileName) )
        {
            OW::getStorage()->removeFile($this->getUserfileImagesDir() . $fileName);
        }
    }

    /***
     * @param $category
     * @param string $defaultCategory
     * @return array
     */
    public function getByCategory($category, $defaultCategory='private'){
        $themes = $this->findAllThemes();
        $themeKeys = [];
        foreach ($themes as $themeDto) {
            $key = $themeDto->key;
            $themeXmlInfo = BOL_ThemeService::getInstance()->getThemeXmlInfoForKey($key);
            $categories = [];
            if( ! isset($themeXmlInfo['category']['item']) ){
                $categories[] = $defaultCategory;
            }else{
                if ( is_string($themeXmlInfo['category']['item'])){
                    $categories[] = $themeXmlInfo['category']['item'];
                }else{
                    foreach($themeXmlInfo['category']['item'] as $child){
                        $categories[] = $child;
                    }
                }
            }

            if( in_array($category, $categories)){
                $themeKeys[] = $key;
            }
        }

        return ($themeKeys);
    }

    /***
    ---- for development only ----
    this function increases all themes build numbers
    ***/
    public function themeBuildNumberUpdater(){
        $themes = UTIL_File::findFiles(OW_DIR_THEME, array('xml'), 1);
        foreach ($themes as $themeXml) {
            if (basename($themeXml) === BOL_ThemeService::THEME_XML) {
                $theme = simplexml_load_file($themeXml);
                $theme->build = $theme->build + 1;
                file_put_contents($themeXml, $theme->asXML());
            }
        }
    }
}
