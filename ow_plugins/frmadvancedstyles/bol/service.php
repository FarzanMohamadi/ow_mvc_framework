<?php
/**
 * frmadvancedstyles
 */
/**
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow_plugins.frmadvancedstyles
 * @since 1.0
 */

class FRMADVANCEDSTYLES_BOL_Service
{
    private static $classInstance;
    public static function getInstance()
    {
        if (self::$classInstance === null) {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }
    private function __construct()
    {
    }

    public function convertSCSStoCSS($scss){
        $scss_compiler = new Leafo\ScssPhp\Compiler();
        $result = "/* GENERATED BY frmadvancedstyles */
        ";
        try {
            $tmpFilePath = OW_DIR_PLUGINFILES . "ow" . DS . 'scss' . rand(100) . '.scss';
            file_put_contents($tmpFilePath, $scss);
            if (OW::getStorage()->fileExists($tmpFilePath)) {
                $scss_code = file_get_contents($tmpFilePath);
                $result = $scss_compiler->compile($scss_code);
            }
        }catch (Exception $e){
            $result = '/* SCSS code cannot be converted to CSS ===========> ERROR message: '. $e->getMessage().' */';
            OW::getLogger()->writeLog(OW_Log::ERROR, 'SCSS_to_CSS_error', ['message' => $e->getMessage()]);
        }
        return $result;
    }

    /***
     * @param bool $mobile
     * @return string
     */
    public function getScssFile($mobile=false){
        if($mobile){
            return BOL_ThemeService::getInstance()->getUserfileImagesDir() . 'scss_mobile.css';
        }
        return BOL_ThemeService::getInstance()->getUserfileImagesDir() . 'scss_desktop.css';
    }

    /***
     * @param bool $mobile
     * @return string|null
     */
    public function getScssURL($mobile=false){
        $filename = $mobile?'scss_mobile.css':'scss_desktop.css';
        if (file_exists(BOL_ThemeService::getInstance()->getUserfileImagesDir(). $filename)){
            return BOL_ThemeService::getInstance()->getUserfileImagesUrl(). $filename;
        }
        return null;
    }

    /***
     * @param $style
     */
    public function setDesktopCustomScss($style){
        OW::getConfig()->saveConfig('frmadvancedstyles', 'desktop_scss', $style);
    }

    /***
     * @param $style
     */
    public function setMobileCustomScss($style){
        OW::getConfig()->saveConfig('frmadvancedstyles', 'mobile_scss', $style);
    }

    /***
     * @return string
     */
    public function getDesktopCustomScss(){
        return OW::getConfig()->getValue('frmadvancedstyles', 'desktop_scss', '');
    }

    /***
     * @return string
     */
    public function getMobileCustomScss(){
        return OW::getConfig()->getValue('frmadvancedstyles', 'mobile_scss', '');
    }
}