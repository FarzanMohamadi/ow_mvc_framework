<?php
/**
 * Theme manage admin controller class.
 *
 * @package ow_system_plugins.admin.controllers
 * @since 1.0
 */
class ADMIN_CTRL_Theme extends ADMIN_CTRL_Abstract
{
    /**
     * @var BOL_ThemeService
     *
     */
    private $themeService;
    /**
     * @var BASE_CMP_ContentMenu
     */
    private $menu;

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->themeService = BOL_ThemeService::getInstance();
        $this->setDefaultAction('settings');
    }

    public function init()
    {
        $router = OW_Router::getInstance();

        $pageActions = array(array('name' => 'settings', 'iconClass' => 'ow_ic_gear_wheel ow_dynamic_color_icon'), array('name' => 'css', 'iconClass' => 'ow_ic_files ow_dynamic_color_icon'), array('name' => 'graphics', 'iconClass' => 'ow_ic_picture ow_dynamic_color_icon'));

        $menuItems = array();

        foreach ( $pageActions as $key => $item )
        {
            $menuItem = new BASE_MenuItem();
            $menuItem->setKey($item['name'])->setLabel(OW::getLanguage()->text('admin', 'sidebar_menu_item_' . $item['name']))->setOrder($key)->setUrl($router->urlForRoute('admin_theme_' . $item['name']));
            $menuItem->setIconClass($item['iconClass']);
            $menuItems[] = $menuItem;
        }

        $this->menu = new BASE_CMP_ContentMenu($menuItems);

        $this->addComponent('contentMenu', $this->menu);

        OW::getNavigation()->activateMenuItem(OW_Navigation::ADMIN_APPEARANCE, 'admin', 'sidebar_menu_item_theme_edit');
        $this->setPageHeading(OW::getLanguage()->text('admin', 'themes_settings_page_title'));
    }

    public function settings()
    {
        $dto = $this->themeService->findThemeByKey(OW::getConfig()->getValue('base', 'selectedTheme'));

        if ( $dto === null )
        {
            throw new LogicException("Can't find theme `" . OW::getConfig()->getValue('base', 'selectedTheme') . "`");
        }

        $assignArray = (array) json_decode($dto->getDescription());

        $assignArray['iconUrl'] = $this->themeService->getStaticUrl($dto->getKey()) . BOL_ThemeService::ICON_FILE;
        $assignArray['name'] = $dto->getKey();
        $assignArray['title'] = $dto->getTitle();
        $this->assign('themeInfo', $assignArray);
        $this->assign('resetUrl', OW::getRouter()->urlFor(__CLASS__, 'reset'));
        $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
            array('senderId'=>OW::getUser()->getId(),'receiverId'=>$dto->getId(),'isPermanent'=>true,'activityType'=>'reset_theme')));
        if(isset($frmSecuritymanagerEvent->getData()['code'])){
            $code = $frmSecuritymanagerEvent->getData()['code'];
            $this->assign('resetUrl', OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlFor(__CLASS__, 'reset'),
                array('code' =>$code)));
        }
        $controls = $this->themeService->findThemeControls($dto->getId());

        $frmThemeManagerThemes = false;
        $customThemeEvent = OW::getEventManager()->trigger(new OW_Event('frmthememanager.get.all.themes', array()));
        if (  (isset($customThemeEvent) && isset($customThemeEvent->getData()['allThemes'])) ){
            $frmThemeManagerThemes = $customThemeEvent->getData();
            if (isset($frmThemeManagerThemes['activeTheme']) && $customThemeEvent->getData()['allThemes'] != null){
                $this->addComponent('frmThemeManagerThemeFormCMP', new FRMTHEMEMANAGER_CMP_ThemeForm(array('key'=>$frmThemeManagerThemes['activeTheme'],'controller'=>$this)));
                OW::getDocument()->addScript(OW::getPluginManager()->getPlugin('frmthememanager')->getStaticJsUrl() . 'frmthememanager.js');
                OW::getDocument()->addStyleSheet(OW::getPluginManager()->getPlugin('frmthememanager')->getStaticCssUrl() . 'frmthememanager.css');
                $frmThemeManagerThemes = true;
            }
        }

        if ( empty($controls) )
        {
            $this->assign('noControls', true);
        }
        else
        {
            $form = new ThemeEditForm($controls);

            $this->assign('inputArray', $form->getFormElements());

            $this->addForm($form);

            if ( OW::getRequest()->isPost() )
            {
                if ( $form->isValid($_POST) && !$frmThemeManagerThemes)
                {
                    $this->themeService->saveThemeControls($dto->getId(), $form->getValues());
                    $this->themeService->updateCustomCssFile($dto->getId());
                    $this->redirect();
                }
            }
        }



        $this->assign('frmThemeManagerThemes',$frmThemeManagerThemes);

        $this->menu->setItemActive('settings');
    }

    public function css()
    {
        if ( OW::getRequest()->isAjax() )
        {
            $dto = $this->themeService->findThemeByKey(OW::getConfig()->getValue('base', 'selectedTheme'));
            $newStyle = isset($_POST['style']) ? ($_POST['style']) : '';
            switch ($_POST['form_name']){
                case 'desktop_css':
                    $dto->setCustomCss($newStyle);
                    break;
                case 'mobile_css':
                    $dto->setMobileCustomCss($newStyle);
                    break;
            }
            $this->themeService->saveTheme($dto);
            OW::getEventManager()->trigger(new OW_Event('admin.css.custom.save', array('form_name'=>$_POST['form_name'], 'style'=>$newStyle)));

            $this->themeService->updateCustomCssFile($dto->getId());
            echo json_encode(array('message' => OW::getLanguage()->text('admin', 'css_edit_success_message')));
        }

        if ( !OW::getRequest()->isAjax() )
        {
            OW::getDocument()->getMasterPage()->getMenu(OW_Navigation::ADMIN_APPEARANCE)->setItemActive('sidebar_menu_item_themes_customize');
        }

        OW::getDocument()->addScript(OW::getPluginManager()->getPlugin('admin')->getStaticJsUrl() . 'prettify.js');
        OW::getDocument()->addScript(OW::getPluginManager()->getPlugin('admin')->getStaticJsUrl() . 'lang-css.js');
        OW::getDocument()->addStyleSheet(OW::getPluginManager()->getPlugin('admin')->getStaticCssUrl() . 'prettify.css');
        OW::getDocument()->addOnloadScript("prettyPrint();");

        $fileString = OW::getStorage()->fileGetContent(OW::getThemeManager()->getSelectedTheme()->getRootDir() . BOL_ThemeService::CSS_FILE_NAME);

        $this->assign('code', '<pre class="prettyprint lang-css">' . $fileString . '</pre>');

        $dto = BOL_ThemeService::getInstance()->findThemeByKey(OW::getConfig()->getValue('base', 'selectedTheme'));
        $formValues = [
            'desktop_css' => $dto->getCustomCss(),
            'mobile_css' => $dto->getMobileCustomCss()
        ];
        if(FRMSecurityProvider::checkPluginActive('frmadvancedstyles', true)) {
            $this->assign('scss', true);
            $scssService = FRMADVANCEDSTYLES_BOL_Service::getInstance();
            $formValues['desktop_scss'] = $scssService->getDesktopCustomScss();
            $formValues['mobile_scss'] = $scssService->getMobileCustomScss();
        }

        foreach($formValues as $formName => $formValue){
            $this->addForm(new AddCustomStyleForm($formName, $formValue));
        }
    }

    private function imageObjToArray(BOL_ThemeImage $image)
    {
        return array(
            'url' => OW::getStorage()->getFileUrl($this->themeService->getUserfileImagesDir() . $image->getFilename()),
            'delUrl' => OW::getRouter()->urlFor(__CLASS__, 'deleteImage', array('image-id' => $image->getId())),
            'cssUrl' => $this->themeService->getUserfileImagesUrl() . $image->getFilename(),
            'id' => $image->getId(),
            'dimensions' => $image->dimensions,
            'filesize' => $image->filesize,
            'title' => $image->title,
            'uploaddate' => UTIL_DateTime::formatSimpleDate($image->addDatetime, true)
        );
    }

    public function graphics()
    {
        if ( !OW::getRequest()->isAjax() )
        {
            OW::getDocument()->getMasterPage()->getMenu(OW_Navigation::ADMIN_APPEARANCE)->setItemActive('sidebar_menu_item_themes_customize');
        }

        $images = $this->themeService->findAllCssImages();
        $assignArray = array();

        /* @var $image BOL_ThemeImage */
        foreach ( $images as $image )
        {
            $assignArray[] = $this->imageObjToArray($image);
        }

        $this->assign('images', $assignArray);

        $form = new UploadGraphicsForm();
        $form->setEnctype(Form::ENCTYPE_MULTYPART_FORMDATA);
        $this->addForm($form);

        $this->assign('confirmMessage', OW::getLanguage()->text('admin', 'theme_graphics_image_delete_confirm_message'));

        $cmp = OW::getClassInstance('ADMIN_CMP_UploadedFileList');
        $this->initFloatbox(array('layout' => 'floatbox'));
        $this->addComponent('filelist', $cmp);

        if ( OW::getRequest()->isPost() )
        {
            try
            {
                $this->themeService->addImage($_FILES['file']);
            }
            catch ( Exception $e )
            {
                OW::getFeedback()->error(OW::getLanguage()->text('admin', 'theme_graphics_upload_form_fail_message'));
                $this->redirect();
            }

            OW::getFeedback()->info(OW::getLanguage()->text('admin', 'theme_graphics_upload_form_success_message'));
            $this->redirect();
        }
    }

    public function bulkOptions()
    {
        $action = isset($_POST['action']) ? $_POST['action'] : null;
        switch ($action)
        {
            case 'delete':
                $items = isset($_POST['delete']) ? $_POST['delete'] : null;
                if (is_null($items))
                {
                    $result = json_encode(array('error' => OW::getLanguage()->text('admin', 'not_enough_params')));
                }
                else
                {
                    foreach ($items as $item)
                    {
                        $this->themeService->deleteImage((int) $item);
                        OW::getFeedback()->info(OW::getLanguage()->text('admin', 'theme_graphics_delete_success_message'));
                    }
                    $result = json_encode(array(
                        'success' => OW::getLanguage()->text('admin', 'theme_graphics_delete_multiple_success_message'),
                        'reload' => true
                    ));
                }
                break;
            default:
                $result = json_encode(array('error' => OW::getLanguage()->text('admin', 'undefined_action')));
                break;
        }
        echo $result;
        exit();
    }

    public function ajaxResponder()
    {
        if ( !OW::getRequest()->isAjax() )
        {
            throw new Redirect404Exception();
        }
        if(FRMSecurityProvider::checkPluginActive('frmsecurityessentials', true) && $_POST['ajaxFunc']=='ajaxDeleteImage') {
            $code =$_GET['code'];
            if(!isset($code)){
                throw new Redirect404Exception();
            }
            OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.check.request.manager',
                array('senderId' => OW::getUser()->getId(), 'code'=>$code,'activityType'=>'ajaxResponder')));
        }
        if ( isset($_POST['ajaxFunc']) )
        {
            $callFunc = (string)$_POST['ajaxFunc'];

            $result = call_user_func(array($this, $callFunc), $_POST);
        }
        else
        {
            throw new Redirect404Exception();
        }

        header('Content-Type: application/json');
        exit(json_encode($result));
    }

    public function getFloatbox( $params )
    {
        if ( empty($params['photoId']) || !$params['photoId'] )
        {
            throw new Redirect404Exception();
        }

        $photoId = (int)$params['photoId'];
        if ( ($photo = $this->themeService->findImageById($photoId)) === null )
        {
            return array('result' => 'error');
        }

        $data = array();
        if ( isset($_POST['date']) && !empty($_POST['date']) )
        {
            $tmpDateArray = explode('-', $_POST['date']);
            $isJalali = false;
            $lbChangeEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_AFTER_DEFAULT_DATE_VALUE_SET, array('changeNewsJalaliToGregorian' => true, 'faYear' =>  (int)$tmpDateArray[0], 'faMonth'=> (int)$tmpDateArray[1] ,'faDay'=> 1)));
            if($lbChangeEvent->getData() && isset($lbChangeEvent->getData()['gregorianYearNews'])){
                $year = $lbChangeEvent->getData()['gregorianYearNews'];
                $isJalali=true;
            }
            if($lbChangeEvent->getData() && isset($lbChangeEvent->getData()['gregorianMonthNews'])){
                $lbmonth = $lbChangeEvent->getData()['gregorianMonthNews'];
                $isJalali=true;
            }
            if($lbChangeEvent->getData() && isset($lbChangeEvent->getData()['gregorianDayNews'])){
                $lbday = $lbChangeEvent->getData()['gregorianDayNews'];
                $isJalali=true;
            }
            $ubChangeEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_AFTER_DEFAULT_DATE_VALUE_SET, array('changeNewsJalaliToGregorian' => true, 'faYear' =>  (int)$tmpDateArray[0], 'faMonth'=> (int)$tmpDateArray[1] ,'faDay'=> (int)$tmpDateArray[2])));
            if($ubChangeEvent->getData() && isset($ubChangeEvent->getData()['gregorianMonthNews'])){
                $ubmonth = $ubChangeEvent->getData()['gregorianMonthNews'];
                $isJalali=true;
            }
            if($ubChangeEvent->getData() && isset($ubChangeEvent->getData()['gregorianDayNews'])){
                $ubday = $ubChangeEvent->getData()['gregorianDayNews'];
                $isJalali=true;
            }
            if( $isJalali)
            {
                $data['end'] = strtotime($year.'-'.$ubmonth.'-'.$ubday  . ' 23:59:59');
                $data['start'] = strtotime($year.'-'.$lbmonth.'-'.$lbday  . ' 00:00:00');
            }
            else {
                $data['end'] = strtotime($_POST['date'] . ' 23:59:59');
                $data['start'] = strtotime(date('Y-m-01 00:00:00',  $data['end']));
            }

        }

        $resp = array('result' => true);

        if ( !empty($params['photos']) )
        {
            foreach ( array_unique($params['photos']) as $photoId )
            {
                $p = $this->themeService->findImageById($photoId);
                $resp['photos'][$photoId] = $this->prepareMarkup($p, $params['layout']);
            }
        }

        if ( !empty($params['loadPrevList']) || !empty($params['loadPrevPhoto']) )
        {
            $resp['prevList'] = $prevIdList = BOL_ThemeService::getInstance()->getPrevImageIdList($photo->id, $data);

            if ( !empty($params['loadPrevPhoto']) )
            {
                $prevId = !empty($prevIdList) ? min($prevIdList) : (!empty($firstIdList) ? min($firstIdList) : null);

                if ( $prevId && !isset($resp['photos'][$prevId]) )
                {
                    $prevImage = $this->themeService->findImageById($prevId);
                    $resp['photos'][$prevId] = $this->prepareMarkup($prevImage, $params['layout']);
                }
            }
        }

        if ( !empty($params['loadNextList']) || !empty($params['loadNextPhoto']) )
        {
            $resp['nextList'] = $prevIdList = BOL_ThemeService::getInstance()->getNextImageIdList($photo->id, $data);

            if ( !empty($params['loadNextPhoto']) )
            {
                $nextId = !empty($nextIdList) ? max($nextIdList) : (!empty($lastIdList) ? max($lastIdList) : null);

                if ( $nextId && !isset($resp['photos'][$nextId]) )
                {
                    $nextImage = $this->themeService->findImageById($nextId);
                    $resp['photos'][$nextId] = $this->prepareMarkup($nextImage, $params['layout']);
                }
            }
        }

        return $resp;
    }

    private function prepareMarkup( $photo, $layout = null )
    {
        $markup = array();

        $photo->title = UTIL_HtmlTag::autoLink($photo->title);
        $photo->url = OW::getStorage()->getFileUrl($this->themeService->getUserfileImagesDir() . $photo->getFilename());

        $photo->addDatetime = UTIL_DateTime::formatSimpleDate($photo->addDatetime, true);

        $markup['photo'] = $photo;

        $action = new BASE_ContextAction();
        $action->setKey('photo-moderate');

        $context = new BASE_CMP_ContextAction();
        $context->addAction($action);

        $lang = OW::getLanguage();

        $action = new BASE_ContextAction();
        $action->setKey('delete');
        $action->setParentKey('photo-moderate');
        $action->setLabel($lang->text('base', 'delete'));
        $action->setId('photo-delete');
        $action->addAttribute('rel', $photo->id);

        $context->addAction($action);

        $markup['contextAction'] = $context->render();

        $document = OW::getDocument();

        $onloadScript = $document->getOnloadScript();

        if ( !empty($onloadScript) )
        {
            $markup['onloadScript'] = $onloadScript;
        }

        $scriptFiles = $document->getScripts();

        if ( !empty($scriptFiles) )
        {
            $markup['scriptFiles'] = $scriptFiles;
        }

        $css = $document->getStyleDeclarations();

        if ( !empty($css) )
        {
            $markup['css'] = $css;
        }

        $cssFiles = $document->getStyleSheets();

        if ( !empty($cssFiles) )
        {
            $markup['cssFiles'] = $cssFiles;
        }

        $meta = $document->getMeta();

        if ( !empty($meta) )
        {
            $markup['meta'] = $meta;
        }

        return $markup;
    }

    public function getPhotoList( $params )
    {
        $page = !empty($params['offset']) ? abs((int)$params['offset']) : 1;
        $imagesLimit = 20;

        if ( isset($_POST['date']) && !empty($_POST['date']) )
        {
            $date = $_POST['date'];
            $tmpDateArray = explode('-', $date);
            $faDay='1';
            if(isset($tmpDateArray[2]) && $tmpDateArray[2]!=''){
                $faDay = $tmpDateArray[2];
            }
            $isJalali = false;
            $lbChangeEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_AFTER_DEFAULT_DATE_VALUE_SET, array('changeNewsJalaliToGregorian' => true, 'faYear' =>  (int)$tmpDateArray[0], 'faMonth'=> (int)$tmpDateArray[1] ,'faDay'=> $faDay)));
            if($lbChangeEvent->getData() && isset($lbChangeEvent->getData()['gregorianYearNews'])){
                $year = $lbChangeEvent->getData()['gregorianYearNews'];
                $isJalali=true;
            }
            if($lbChangeEvent->getData() && isset($lbChangeEvent->getData()['gregorianMonthNews'])){
                $lbmonth = $lbChangeEvent->getData()['gregorianMonthNews'];
                $isJalali=true;
            }
            if($lbChangeEvent->getData() && isset($lbChangeEvent->getData()['gregorianDayNews'])){
                $lbday = $lbChangeEvent->getData()['gregorianDayNews'];
                $isJalali=true;
            }
            $ubChangeEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_AFTER_DEFAULT_DATE_VALUE_SET, array('changeNewsJalaliToGregorian' => true, 'faYear' =>  (int)$tmpDateArray[0], 'faMonth'=> (int)$tmpDateArray[1] ,'faDay'=> (int)$tmpDateArray[2])));
            if($ubChangeEvent->getData() && isset($ubChangeEvent->getData()['gregorianMonthNews'])){
                $ubmonth = $ubChangeEvent->getData()['gregorianMonthNews'];
                $isJalali=true;
            }
            if($ubChangeEvent->getData() && isset($ubChangeEvent->getData()['gregorianDayNews'])){
                $ubday = $ubChangeEvent->getData()['gregorianDayNews'];
                $isJalali=true;
            }
            if( $isJalali)
            {
                $end = strtotime($year.'-'.$ubmonth.'-'.$ubday  . ' 23:59:59');
                //$end = strtotime($date . ' 23:59:59');
                $start = strtotime($year.'-'.$lbmonth.'-'.$lbday  . ' 00:00:00');
                //$start = strtotime(date('Y-m-01 00:00:00', $end));
            }
            else {
                $end = strtotime($date . ' 23:59:59');
                $start = strtotime(date('Y-m-01 00:00:00', $end));
            }
        }
        else
        {
            $start = null;
            $end = null;
        }

        $result = BOL_ThemeService::getInstance()->filterCssImages(array(
            'start' => $start,
            'end' => $end,
            'page' => $page,
            'limit' => $imagesLimit,
        ));

        return $this->generatePhotoList($result);
    }

    public function generatePhotoList( $photos )
    {
        $unique = FRMSecurityProvider::generateUniqueId(time(), true);

        if ( $photos )
        {
            foreach ( $photos as $key => $photo )
            {
                $entityIdList[] = $photo->id;
				$photos[$key]->title = UTIL_HtmlTag::autoLink($photos[$key]->title);
				$photos[$key]->unique = $unique;
				$photos[$key]->addDatetime = UTIL_DateTime::formatSimpleDate($photos[$key]->addDatetime, true);
            }
        }

        return array('status' => 'success', 'data' => array(
            'photoList' => $photos,
            'unique' => $unique
        ));
    }

    public function resetGraphics()
    {
        if(FRMSecurityProvider::checkPluginActive('frmsecurityessentials', true)) {
            $code =$_GET['code'];
            if(!isset($code)){
                throw new Redirect404Exception();
            }
            OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.check.request.manager',
                array('senderId' => OW::getUser()->getId(), 'code'=>$code,'activityType'=>'reset_graphic')));
        }
        $this->themeService->resetImageControl(OW::getThemeManager()->getSelectedTheme()->getDto()->getId(), trim($_GET['name']));
        $this->redirect(OW::getRouter()->urlForRoute('admin_themes_edit'));
    }

    public function reset()
    {
        if(FRMSecurityProvider::checkPluginActive('frmsecurityessentials', true)) {
            $code =$_GET['code'];
            if(!isset($code)){
                throw new Redirect404Exception();
            }
            OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.check.request.manager',
                array('senderId' => OW::getUser()->getId(), 'code'=>$code,'activityType'=>'reset_theme')));
        }
        $dto = $this->themeService->findThemeByKey(OW::getConfig()->getValue('base', 'selectedTheme'));
        $this->themeService->resetTheme($dto->getId());
        $this->redirect(OW::getRouter()->urlForRoute('admin_themes_edit'));
    }

    public function deleteImage( $params )
    {
        $this->themeService->deleteImage((int) $params['image-id']);
        OW::getFeedback()->info(OW::getLanguage()->text('admin', 'theme_graphics_delete_success_message'));
        $this->redirect(OW::getRouter()->urlForRoute('admin_theme_graphics'));
    }

    public function ajaxDeleteImage( $params )
    {
        $imageId = (int) $params['entityId'];
        $this->themeService->deleteImage($imageId);
        return array(
            'result' => true,
            'msg' => OW::getLanguage()->text('admin', 'theme_graphics_delete_success_message'),
            'imageId' => $imageId
        );
    }

    public function ajaxSaveImageData( $params )
    {
        $imageId = (int) $params['entityId'];
        $image = $this->themeService->findImageById($imageId);
        if ( isset($params['title']) && !empty($params['title']) )
        {
            $image->title = $params['title'];
        }
        BOL_ThemeImageDao::getInstance()->save($image);
        return array(
            'result' => true,
            'imageId' => $imageId
        );
    }

    private function initFloatbox( $params )
    {
        static $isInitialized = false;

        if ( $isInitialized )
        {
            return;
        }

        $layout = (!empty($params['layout']) && in_array($params['layout'], array('page', 'floatbox'))) ? $params['layout'] : 'floatbox';

        $document = OW::getDocument();
        $basePlugin = OW::getPluginManager()->getPlugin('base');

        $document->addStyleSheet($basePlugin->getStaticCssUrl() . 'photo_floatbox.css');
        $document->addScript($basePlugin->getStaticJsUrl() . 'jquery-ui.min.js');
        $document->addScript($basePlugin->getStaticJsUrl() . 'slider.min.js', 'text/javascript', 1000000);
        $document->addScript($basePlugin->getStaticJsUrl() . 'photo.js');

        $language = OW::getLanguage();

        $language->addKeyForJs('admin', 'tb_edit_photo');
        $language->addKeyForJs('admin', 'confirm_delete');
        $language->addKeyForJs('admin', 'mark_featured');
        $language->addKeyForJs('admin', 'remove_from_featured');
        $language->addKeyForJs('admin', 'rating_total');
        $language->addKeyForJs('admin', 'rating_your');
        $language->addKeyForJs('admin', 'of');
        $language->addKeyForJs('admin', 'album');
        $language->addKeyForJs('base', 'rate_cmp_owner_cant_rate_error_message');
        $language->addKeyForJs('base', 'rate_cmp_auth_error_message');
        $language->addKeyForJs('admin', 'slideshow_interval');
        $language->addKeyForJs('admin', 'pending_approval');

        $document->addScriptDeclarationBeforeIncludes(
            UTIL_JsGenerator::composeJsString('
                ;window.photoViewParams = Object.defineProperties({}, {
                    ajaxResponder:{value: {$ajaxResponder}, enumerable: true},
                    rateUserId: {value: {$rateUserId}, enumerable: true},
                    layout: {value: {$layout}, enumerable: true},
                    isClassic: {value: {$isClassic}, enumerable: true},
                    urlHome: {value: {$urlHome}, enumerable: true},
                    isDisabled: {value: {$isDisabled}, enumerable: true},
                    isEnableFullscreen: {value: {$isEnableFullscreen}, enumerable: true}
                });', array(
                    'ajaxResponder' => OW::getRouter()->urlFor('ADMIN_CTRL_Theme', 'ajaxResponder'),
                    'rateUserId' => OW::getUser()->getId(),
                    'layout' => $layout,
                    'isClassic' => false,
                    'urlHome' => OW_URL_HOME,
                    'isDisabled' => false,
                    'isEnableFullscreen' => true
                )
            )
        );

        $document->addOnloadScript(';window.photoView.init();');

        $cmp = new ADMIN_CMP_UploadedFilesFloatbox($layout);
        $document->appendBody($cmp->render());

        $isInitialized = true;
    }


}

class UploadGraphicsForm extends Form
{

    public function __construct()
    {
        parent::__construct('upload_graphics');

        $this->addElement(new FileField('file'));

        $submit = new Submit('submit');
        $submit->setValue(OW::getLanguage()->text('admin', 'theme_graphics_upload_form_submit_label'));
        $this->addElement($submit);
    }
}

class AddCustomStyleForm extends Form
{

    public function __construct($prefix, $currentValue)
    {
        parent::__construct($prefix);

        $text = new Textarea('style');
        $text->setValue($currentValue);
        $this->addElement($text);

        $submit = new Submit('submit');
        $submit->setValue(OW::getLanguage()->text('admin', 'theme_css_edit_submit_label'));
        $this->addElement($submit);

        $this->setAjax(true);
        $this->setAjaxResetOnSuccess(false);
        $this->bindJsFunction(Form::BIND_SUCCESS, 'function(data){OW.info(data.message)}');
    }
}


class ThemeEditForm extends Form
{
    private $formElements = array();

    public function __construct( $controls )
    {
        $this->setEnctype(Form::ENCTYPE_MULTYPART_FORMDATA);

        parent::__construct('theme-edit');

        $typeArray = array(
            'text' => 'TextField',
            'color' => 'ColorField',
            'font' => 'FontFamilyField',
            'image' => 'ImageField'        
        );

        $inputArray = array();
        
        foreach ( $controls as $value )
        {
            if( !array_key_exists($value["type"], $typeArray) )
            {
                continue;
            }
            
            $refField = new ReflectionClass($typeArray[$value['type']]);
            $field = $refField->newInstance($value['key']);
            
            if(method_exists($field, "setMobile") )
            {
                call_user_func(array($field, "setMobile"), $value["mobile"]);
            }

            if ( $this->getElement($field->getName()) !== null )
            {
                continue;
            }

            $field->setValue($value['value'] !== null ? trim($value['value']) : trim($value['defaultValue']));
            $this->addElement($field);

            if ( !array_key_exists(trim($value['section']), $this->formElements) )
            {
                $this->formElements[trim($value['section'])] = array();
            }

            $this->formElements[trim($value['section'])][] = array('name' => $value['key'], 'title' => $value['label'], 'desc' => $value['description']);
        }

        ksort($this->formElements);

        $submit = new Submit('submit');
        $submit->setValue(OW::getLanguage()->text('admin', 'theme_settings_form_submit_label'));

        $this->addElement($submit);
    }

    public function getFormElements()
    {
        return $this->formElements;
    }
}

class FontFamilyField extends Selectbox
{

    public function __construct( $name )
    {
        parent::__construct($name);

        $this->setOptions(
            array(
                'default' => 'Default',
                'Arial, Helvetica, sans-serif' => 'Arial, Helvetica, sans-serif',
                'Times New Roman, Times, serif' => 'Times New Roman, Times, serif',
                'Courier New, Courier, monospace' => 'Courier New, Courier, monospace',
                'Georgia, Times New Roman, Times, serif' => 'Georgia, Times New Roman, Times, serif',
                'Verdana, Arial, Helvetica, sans-serif' => 'Verdana, Arial, Helvetica, sans-serif',
                'Geneva, Arial, Helvetica, sans-serif' => 'Geneva, Arial, Helvetica, sans-serif'
            )
        );

        $this->setHasInvitation(false);
    }
}

class ImageField extends FormElement
{
    private $mobile;

    public function __construct( $name )
    {
        parent::__construct($name);        
    }

    function setMobile($mobile) {
        $this->mobile = (bool)$mobile;
    }
        
    public function getValue()
    {
        return isset($_FILES[$this->getName()]) ? $_FILES[$this->getName()] : null;
    }

    /**
     * @see FormElement::renderInput()
     *
     * @param array $params
     * @return string
     */
    public function renderInput( $params = null )
    {
        parent::renderInput($params);

        $output = '';

        if ( $this->value !== null && ( trim($this->value) !== 'none' ) )
        {
            if ( !strstr($this->value, 'http') )
            {
                $resultString = substr($this->value, (strpos($this->value, 'images/') + 7));
                $this->value = 'url(' . OW::getThemeManager()->getSelectedTheme()->getStaticImagesUrl($this->mobile) . substr($resultString, 0, strpos($resultString, ')')) . ')';
            }

            $randId = 'if' . rand(10, 10000000);

            $script = "$('#" . $randId . "').click(function(){
                new OW_FloatBox({\$title:'" . OW::getLanguage()->text('admin', 'themes_settings_graphics_preview_cap_label') . "', \$contents:$('#image_view_" . $this->getName() . "'), width:'550px'});
            });";

            OW::getDocument()->addOnloadScript($script);
            $code='';
            $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                array('senderId'=>OW::getUser()->getId(),'receiverId'=>$randId,'isPermanent'=>true,'activityType'=>'reset_graphic')));
            if(isset($frmSecuritymanagerEvent->getData()['code'])){
                $code = $frmSecuritymanagerEvent->getData()['code'];
            }
            $output .= '<div class="clearfix"><a id="' . $randId . '" href="javascript://" class="theme_control theme_control_image" style="background-image:' . $this->value . ';"></a>
                <div style="float:left;padding:10px 0 0 10px;"><a href="javascript://" onclick="window.location=\'' . OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlFor('ADMIN_CTRL_Theme', 'resetGraphics'), array('name' => $this->getName(),'code' =>$code)) . '\'">' . OW::getLanguage()->text('admin', 'themes_settings_reset_label') . '</a></div></div>
                <div style="display:none;"><div class="preview_graphics" id="image_view_' . $this->getName() . '" style="background-image:' . $this->value . '"></div></div>';
        }

        $output .= '<input type="file" name="' . $this->getName() . '" />';

        return $output;
    }
}