<?php
class FRMSHASTA_CMP_ManageAccessCategoryFloatBox extends OW_Component
{

    private $categoryId;

    private $buttonLabel;

    private $headingLabel;

    public function __construct($categoryId)
    {
        if(!isset($categoryId))
        {
            throw new Redirect404Exception();
        }
        if(!OW::getUser()->isAuthenticated())
        {
            throw new Redirect404Exception();
        }
        $service = FRMSHASTA_BOL_Service::getInstance();

        if(!$service->hasUserAccessManager())
        {
            throw new Redirect404Exception();
        }
        $this->categoryId=$categoryId;
        parent::__construct();
        $this->buttonLabel = OW::getLanguage()->text('frmshasta', 'save_button_label');

    }

    /**
     * @param string $buttonLabel
     */
    public function setButtonLabel( $buttonLabel )
    {
        $this->buttonLabel = $buttonLabel;
    }

    /**
     * @param string $generalLabel
     */
    public function setGeneralLabel( $generalLabel )
    {
        $this->headingLabel = $generalLabel;
    }

    public function onBeforeRender()
    {
        $service = FRMSHASTA_BOL_Service::getInstance();
        if (!$service->hasUserAccessManager()) {
            throw new Redirect404Exception();
        }
        parent::onBeforeRender();
        $serviceIisShasta=FRMSHASTA_BOL_Service::getInstance();
        $contexId = UTIL_HtmlTag::generateAutoId('cmp');
        $this->assign('contexId', $contexId);
        $numberOfUsers = BOL_UserService::getInstance()->count(true);
        $users = BOL_UserService::getInstance()->findList(0, $numberOfUsers, true);
        $userIds = array();
        $userApproveConfig = OW::getConfig()->getValue('base', 'mandatory_user_approve');
        $usersEmailVerifyConfig = OW::getConfig()->getValue('base', 'confirm_email');
        foreach ($users as $user) {
            $userEmailStatus = $user->emailVerify == '0';
            $userDisapproveStatus = BOL_UserService::getInstance()->findUnapprovedStatusForUserList(array($user->getId()));
            if ($user->getId() == OW::getUser()->getId() ||
                ($userApproveConfig && $userDisapproveStatus[$user->getId()] == true) ||
                ($usersEmailVerifyConfig && $userEmailStatus)) {
                continue;
            }
            $userIds[] = $user->getId();
        }
        $this->idList = $userIds;
        if (empty($this->idList)) {
            return;
        }

        $avatars = BOL_AvatarService::getInstance()->getDataForUserAvatars($this->idList, true, false, false);
        $this->assign('avatars', $avatars);
        $displayNames = BOL_UserService::getInstance()->getDisplayNamesForList($this->idList);
        $usernames = BOL_UserService::getInstance()->getUserNamesForList($this->idList);


        $arrayToAssign = array();
        $jsArray = array();
        $accessDeniedUsers= $serviceIisShasta->findUserIdsDeniedAccessToCategory($this->categoryId);
        foreach ($this->idList as $id) {
            $selected=true;
            $linkId = UTIL_HtmlTag::generateAutoId('user-select');
            $cssClass = "ow_item_set2  ow_mild_green ";

            if(in_array($id,$accessDeniedUsers))
            {
                $cssClass=str_replace('ow_mild_green','',$cssClass);
                $selected=false;
            }
            if (!empty($avatars[$id])) {
                $avatars[$id]['url'] = 'javascript://';
            }

            $arrayToAssign[$id] = array(
                'id' => $id,
                'title' => empty($displayNames[$id]) ? '_DISPLAY_NAME_' : $displayNames[$id],
                'linkId' => $linkId,
                'username' => $usernames[$id],
                'class' => $cssClass
            );

            $jsArray[$id] = array(
                'linkId' => $linkId,
                'entityId' => $id,
                'selected' => $selected
            );
        }

        $manageAccessCategoryResponder = OW::getRouter()->urlFor('FRMSHASTA_CTRL_Service', 'manageAccessCategory');
        OW::getDocument()->addOnloadScript("
            var cmp = new manageCategoryAccessSelect(" . json_encode($jsArray) . ", '" . $this->categoryId . "', '" . $manageAccessCategoryResponder . "');
            cmp.init();  ");
        $event = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::IS_MOBILE_VERSION, array('check' => true)));
        if (isset($event->getData()['isMobileVersion']) && $event->getData()['isMobileVersion'] == true) {
            OW::getDocument()->addOnloadScript("
                    $('#instant_search_txt_input').on('change input',function () {
                        var q = $(this).val();
                        $('.asl_users .owm_user_list_item').each(function(i,obj){
                            if(obj.innerText.indexOf(q)>=0)
                                obj.style.display = 'block'
                            else
                                obj.style.display = 'none'
                        });
                    });
                ");
        } else {
            OW::getDocument()->addOnloadScript("
                    $('#instant_search_txt_input').on('change input',function () {
                        var q = $(this).val();
                        $('.asl_users .ow_user_list_item').each(function(i,obj){
                            if(obj.innerText.indexOf(q)>=0)
                                obj.style.display = 'inline-block'
                            else
                                obj.style.display = 'none'
                        });
                    });
                ");
        }

        $this->assign('users', $arrayToAssign);

        $langs = array(
            'buttonLabel' => $this->buttonLabel
        );
        $this->assign('langs', $langs);
    }
}

