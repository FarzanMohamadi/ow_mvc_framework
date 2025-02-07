<?php
/**
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow_plugin.mailbox.mobile.controllers
 * @since 1.6.1
 * */
class MAILBOX_MCTRL_Messages extends OW_MobileActionController
{
    public function chatConversation($params)
    {
        $this->setPageTitle(OW::getLanguage()->text('mailbox', 'chat'));
        $this->setPageHeading(OW::getLanguage()->text('mailbox', 'chat'));

        OW::getDocument()->setHeading(OW::getLanguage()->text('mailbox', 'chat'));
        if (!OW::getUser()->isAuthenticated())
        {
            throw new AuthenticateException();
        }

        $userId = OW::getUser()->getId();
        $opponentId = (int)$params['userId'];
        $opponentUser = BOL_UserService::getInstance()->findUserById($opponentId);
        if(!isset($opponentUser))
        {
            throw new Redirect404Exception();
        }
        /* $actionName = 'use_chat';

        $isAuthorized = OW::getUser()->isAuthorized('mailbox', $actionName);
        if ( !$isAuthorized )
        {
            $status = BOL_AuthorizationService::getInstance()->getActionStatus('mailbox', $actionName);
            if ( $status['status'] == BOL_AuthorizationService::STATUS_PROMOTED )
            {
                throw new AuthorizationException($status['msg']);
            }
            else
            {
                throw new AuthorizationException();
            }
        } */

        $conversationService = MAILBOX_BOL_ConversationService::getInstance();

        $conversationId = $conversationService->getChatConversationIdWithUserById($userId, $opponentId);
        if ( empty($conversationId) )
        {
            $conversation = $conversationService->createChatConversation($userId, $opponentId);

            $conversationId = $conversation->getId();
        }

        $cachedParams = array();
        $cachedParams['cache']['conversations_items'] = MAILBOX_BOL_ConversationDao::getInstance()->getConversationsItem(array($conversationId));
        $cachedParams['cache']['conversations'] = MAILBOX_BOL_ConversationDao::getInstance()->findByConversationIds(array($conversationId));
        $cachedParams['cache']['users_info'] = BOL_AvatarService::getInstance()->getDataForUserAvatars([$userId, $opponentId]);
        $cachedParams['cache']['unread_conversation_count'] = MAILBOX_BOL_ConversationService::getInstance()->countUnreadMessagesForConversationByIds(array($conversationId), $userId);
        $conversationService->markRead(array($conversationId), $userId, null, $cachedParams);
        $data = $conversationService->getConversationDataAndLog($conversationId, 0, 16, $cachedParams);

        $cmp = new MAILBOX_MCMP_ChatConversation($data);
        OW::getLanguage()->addKeyForJs('mailbox','edited_message_tag');

        $this->addComponent('cmp', $cmp);
    }

    public function mailConversation($params)
    {
        $this->setPageTitle(OW::getLanguage()->text('mailbox', 'chat'));
        $this->setPageHeading(OW::getLanguage()->text('mailbox', 'chat'));

        if (!OW::getUser()->isAuthenticated())
        {
            throw new AuthenticateException();
        }

        $userId = OW::getUser()->getId();
        $conversationId = (int)$params['convId'];

        $conversationService = MAILBOX_BOL_ConversationService::getInstance();

        $conversation = $conversationService->getConversation($conversationId);
        if (empty($conversation))
        {
            throw new Redirect404Exception();
        }

        $conversationService->markRead(array($conversationId), $userId);
        $data = $conversationService->getConversationDataAndLog($conversationId);
        if(isset($data['close_dialog']) && $data['close_dialog']){
            throw new Redirect404Exception();
        }
        $cmp = new MAILBOX_MCMP_MailConversation($data);

        $this->addComponent('cmp', $cmp);
    }

    public function composeMailConversation($params)
    {

        $this->setPageTitle(OW::getLanguage()->text('mailbox', 'chat'));
        $this->setPageHeading(OW::getLanguage()->text('mailbox', 'chat'));

        OW::getDocument()->setHeading(OW::getLanguage()->text('mailbox', 'mailbox'));
        if (!OW::getUser()->isAuthenticated())
        {
            throw new Redirect404Exception();
        }

        $conversationService = MAILBOX_BOL_ConversationService::getInstance();

        $this->assign('defaultAvatarUrl', BOL_AvatarService::getInstance()->getDefaultAvatarUrl());
        $opponentId = $params['opponentId'];

        $profileDisplayname = BOL_UserService::getInstance()->getDisplayName($opponentId);
        $this->assign('displayName', empty($profileDisplayname) ? BOL_UserService::getInstance()->getUserName($opponentId) : $profileDisplayname);

        $profileUrl = BOL_UserService::getInstance()->getUserUrl($opponentId);
        $this->assign('profileUrl', $profileUrl);

        $avatarUrl = BOL_AvatarService::getInstance()->getAvatarUrl($opponentId);
        $this->assign('avatarUrl', $avatarUrl);

        $this->assign('status', $conversationService->getUserStatus($opponentId));
        $this->assign('backUrl', $profileUrl);
        $userSendMessageIntervalOk = $conversationService->checkUserSendMessageInterval(OW::getUser()->getId());
        if (!$userSendMessageIntervalOk)
        {
            $send_message_interval = (int)OW::getConfig()->getValue('mailbox', 'send_message_interval');
            /*
             *  these codes have been deactivated because exit is used in the echoOut which is proper for ajax requests not for post reqauests, and caused error with blank page
             */
            /*
            $this->echoOut(
                array('error'=>OW::getLanguage()->text('mailbox', 'feedback_send_message_interval_exceed', array('send_message_interval'=>$send_message_interval)))
            );
            */
            $errorText = OW::getLanguage()->text('mailbox', 'feedback_send_message_interval_exceed', array('send_message_interval'=>$send_message_interval));
            $this->assign('errorText',$errorText);
        }


        $params = array(
            'profileUrl' => $profileUrl
        );

        $js = UTIL_JsGenerator::composeJsString(' OWM.composeMessageForm = new MAILBOX_ComposeMessageFormView({$params})', array('params'=>$params));
        OW::getDocument()->addOnloadScript($js, 3001);

        $form = new MAILBOX_MCLASS_ComposeMessageForm($opponentId);

        if (OW::getRequest()->isPost())
        {
            if ($form->isValid($_POST))
            {
                $result = $form->process();
                if ($result['result'])
                {
                    $this->redirect(OW::getRouter()->urlForRoute('mailbox_mail_conversation', array('convId'=>$result['conversationId'])));
                }
                else
                {
                    OW::getFeedback()->error($result['error']);
                    $this->addForm($form);
                }
            }
            else
            {
                exit(json_encode(array($form->getErrors())));
            }
        }
        else
        {
            $this->addForm($form);
        }
    }

    private function echoOut2( $out )
    {
        echo '<script>window.parent.OWM.conversation.afterAttachment(' . json_encode($out) . ');</script>';
    }
    private function echoOut( $out )
    {
        echo '<script>window.parent.OWM.conversation.afterAttachment(' . json_encode($out) . ');</script>';
        exit;
    }

    public function attachment($params)
    {
        if ( empty($_FILES['attachment']["tmp_name"]) || empty($_POST['conversationId']) || empty($_POST['opponentId']) || empty($_POST['uid']) )
        {
            $this->echoOut(array(
                "error" => OW::getLanguage()->text('base', 'form_validate_common_error_message')
            ));
        }

        if ( !OW::getUser()->isAuthenticated() )
        {
            $this->echoOut(array(
                "error" => "You need to sign in to send attachment."
            ));
        }

        $conversationService = MAILBOX_BOL_ConversationService::getInstance();

//        $userSendMessageIntervalOk = $conversationService->checkUserSendMessageInterval(OW::getUser()->getId());
//        if (!$userSendMessageIntervalOk)
//        {
//            $send_message_interval = (int)OW::getConfig()->getValue('mailbox', 'send_message_interval');
//            $this->echoOut(
//                array('error'=>OW::getLanguage()->text('mailbox', 'feedback_send_message_interval_exceed', array('send_message_interval'=>$send_message_interval)))
//            );
//        }

        if ( !empty($_FILES['attachment']["tmp_name"]) ) {

            $fileCount=sizeof($_FILES['attachment']["tmp_name"]);
            $filesPosted = $_FILES;
            for($i=0;$i<$fileCount;$i++)
            {
                $_FILES['attachment']['name']=$filesPosted['attachment']['name'][$i];
                $_FILES['attachment']['type']=$filesPosted['attachment']['type'][$i];
                $_FILES['attachment']['tmp_name']=$filesPosted['attachment']['tmp_name'][$i];
                $_FILES['attachment']['error']=$filesPosted['attachment']['error'][$i];
                $_FILES['attachment']['size']=$filesPosted['attachment']['size'][$i];

                $attachmentService = BOL_AttachmentService::getInstance();

                $conversationId = $_POST['conversationId'];
                $userId = OW::getUser()->getId();
                $uid = $_POST['uid'];

                try {
                    $maxUploadSize = OW::getConfig()->getValue('base', 'attch_file_max_size_mb');
                    $validFileExtensions = json_decode(OW::getConfig()->getValue('base', 'attch_ext_list'), true);

                    $dtoArr = $attachmentService->processUploadedFile(
                        'mailbox',
                        $_FILES['attachment'],
                        $uid,
                        $validFileExtensions,
                        $maxUploadSize
                    );
                } catch (Exception $e) {
                    $this->echoOut(
                        array(
                            "error" => $e->getMessage()
                        )
                    );
                }

                $files = $attachmentService->getFilesByBundleName('mailbox', $uid);

                if (!empty($files)) {
                    $conversation = $conversationService->getConversation($conversationId);
                    try {
                        if($i==0)
                        {
                            $caption = !empty($_POST['caption']) ? $_POST['caption'] : OW::getLanguage()->text(
                                'mailbox',
                                'attachment'
                            );
                        }else{
                            $caption = OW::getLanguage()->text('mailbox', 'attachment');
                        }
                        $message = $conversationService->createMessage($conversation, $userId, $caption);
                        $conversationService->addMessageAttachments($message->id, $files);

                        if($i==$fileCount-1)
                        {
                            $this->echoOut(array('message' => $conversationService->getMessageData($message)));
                        }else{
                            $this->echoOut2(array('message' => $conversationService->getMessageData($message)));
                        }
                    } catch (InvalidArgumentException $e) {
                        $this->echoOut(
                            array(
                                "error" => $e->getMessage()
                            )
                        );
                    }
                }
            }
        }
    }

    public function newmessage($params)
    {
        if ( !OW::getUser()->isAuthenticated() )
        {
            $this->echoOut(array(
                "error" => "You need to sign in to send message."
            ));
        }
        
        $conversationService = MAILBOX_BOL_ConversationService::getInstance();
        
//        $userSendMessageIntervalOk = $conversationService->checkUserSendMessageInterval(OW::getUser()->getId());
//        if (!$userSendMessageIntervalOk)
//        {
//            $send_message_interval = (int)OW::getConfig()->getValue('mailbox', 'send_message_interval');
//            $this->echoOut(
//                array('error'=>OW::getLanguage()->text('mailbox', 'feedback_send_message_interval_exceed', array('send_message_interval'=>$send_message_interval)))
//            );
//        }
        
        if ( empty($_POST['conversationId']) || empty($_POST['opponentId']) || empty($_POST['uid']) || empty($_POST['newMessageText']) )
        {
            $this->echoOut(array(
                "error" => OW::getLanguage()->text('base', 'form_validate_common_error_message')
            ));
        }
        
        $conversationId = $_POST['conversationId'];
        $userId = OW::getUser()->getId();
        

        $checkResult = $conversationService->checkUser($userId, $_POST['opponentId']);

        if ( $checkResult['isSuspended'] )
        {
            $this->echoOut(array(
                "error" => $checkResult['suspendReasonMessage']
            ));
        }

        $conversation = $conversationService->getConversation($conversationId);
        try
        {
            $message = $conversationService->createMessage($conversation, $userId, $_POST['newMessageText']);

            if ( !empty($_FILES['attachment']["tmp_name"]) )
            {
                $attachmentService = BOL_AttachmentService::getInstance();
                $uid = $_POST['uid'];

                $maxUploadSize = OW::getConfig()->getValue('base', 'attch_file_max_size_mb');
                $validFileExtensions = json_decode(OW::getConfig()->getValue('base', 'attch_ext_list'), true);
                $dtoArr = $attachmentService->processUploadedFile('mailbox', $_FILES['attachment'], $uid, $validFileExtensions, $maxUploadSize);

                $files = $attachmentService->getFilesByBundleName('mailbox', $uid);

                if (!empty($files))
                {
                    $conversationService->addMessageAttachments($message->id, $files);
                }
            }
            
            $this->echoOut( array('message'=>$conversationService->getMessageData($message)) );
        }
        catch(InvalidArgumentException $e)
        {
            $this->echoOut(array(
                "error" => $e->getMessage()
            ));
        }
    }
}
