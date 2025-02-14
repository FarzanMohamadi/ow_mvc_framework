<?php
/**
 * Created by PhpStorm.
 * User: Seyed Ismail Mirvakili
 * Date: 2/26/18
 * Time: 1:10 PM
 */
class FRMQUESTIONS_MCMP_EditQuestion extends OW_MobileComponent
{
    /**
     * Constructor.
     *
     * @param $questionId
     */
    public function __construct( $questionId)
    {
        parent::__construct();

        $question = FRMQUESTIONS_BOL_Service::getInstance()->findQuestion($questionId);
        $form = new FRMQUESTIONS_CLASS_EditQuestionForm($question);
        $this->addForm($form);
        $this->setTemplate(OW::getPluginManager()->getPlugin('frmquestions')->getMobileCmpViewDir() . 'edit_question.html');
    }
}