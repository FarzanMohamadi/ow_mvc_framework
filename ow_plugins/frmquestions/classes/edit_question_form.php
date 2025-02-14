<?php
/**
 * Created by PhpStorm.
 * User: Seyed Ismail Mirvakili
 * Date: 3/4/18
 * Time: 8:57 AM
 */
class FRMQUESTIONS_CLASS_EditQuestionForm extends Form
{
    const QUESTION_FIELD_NAME = 'question';
    const ALLOW_ADD_OPTION_FIELD_NAME = 'allowAddOptions';
    const ALLOW_MULTIPLE_ANSWERS_NAME = 'allowMultipleAnswers';
    const SAVE_FIELD_NAME = 'save';
    const FORM_NAME = 'edit_question_form';

    /**
     * FRMQUESTIONS_CLASS_CreateQuestionForm constructor.
     * @param FRMQUESTIONS_BOL_Question $question
     */
    public function __construct($question)
    {
        parent::__construct(self::FORM_NAME);
        $language = OW::getLanguage();

        $this->setAjax();
        $this->setAjaxResetOnSuccess(false);
        $this->setAction(OW::getRouter()->urlForRoute('frmquestion-edit'));
        $this->bindJsFunction(self::BIND_SUCCESS, 'function(data){question_map['.$question->getId().'].edit(data)}');

//        $field = new Textarea(self::QUESTION_FIELD_NAME);
//        $field->addAttribute('maxlength', 500);
//        $field->setRequired();
//        $field->setValue($question->text);
//        $field->setHasInvitation(true);
//        $field->setInvitation($language->text('frmquestions', 'question_add_text_inv'));
//        $field->addAttribute("inv", $language->text('frmquestions', 'question_add_text_inv'));
//        $this->addElement($field);

        $field = new HiddenField('question_id');
        $field->setValue($question->getId());
        $this->addElement($field);

//        $field = new HiddenField('attachment');
//        $this->addElement($field);

        $field = new Selectbox(self::ALLOW_ADD_OPTION_FIELD_NAME);
        $field->setLabel(OW::getLanguage()->text('frmquestions', 'question_add_allow_add_opt'));
        $options = array();
        $options[FRMQUESTIONS_BOL_Service::PRIVACY_EVERYBODY] = OW::getLanguage()->text("privacy", "privacy_everybody");
        $options[FRMQUESTIONS_BOL_Service::PRIVACY_ONLY_FOR_ME] = OW::getLanguage()->text("privacy", "privacy_only_for_me");
        if($question->context != "groups")
            $options[FRMQUESTIONS_BOL_Service::PRIVACY_FRIENDS_ONLY] = OW::getLanguage()->text("friends", "privacy_friends_only");
        $field->setHasInvitation(false);
        $field->setOptions($options);
        $field->addAttribute('class', 'statusPrivacy');
        $field->setValue($question->addOption);
        $this->addElement($field);

        $field = new Selectbox(self::ALLOW_MULTIPLE_ANSWERS_NAME);
        $field->setLabel(OW::getLanguage()->text('frmquestions', 'question_add_allow_multiple_answer'));
        $options = array();
        $options[FRMQUESTIONS_BOL_Service::MULTIPLE_ANSWER] = OW::getLanguage()->text("frmquestions", "multiple_answers");
        $options[FRMQUESTIONS_BOL_Service::ONE_ANSWER] = OW::getLanguage()->text("frmquestions", "one_answer");
        $field->setHasInvitation(false);
        $field->setOptions($options);
        $field->addAttribute('class', 'statusPrivacy');
        if ( $question->isMultiple )
            $field->setValue(FRMQUESTIONS_BOL_Service::MULTIPLE_ANSWER);
        else
            $field->setValue(FRMQUESTIONS_BOL_Service::ONE_ANSWER);
        $this->addElement($field);

//        $contextIdField = new CheckboxField(self::MULTIPLE_ID_FIELD_NAME);
//        $contextIdField->setLabel($language->text('frmquestions','question_allow_multiple'));
//        $contextIdField->setValue(true);
//        $this->addElement($contextIdField);

        $submit = new Submit(self::SAVE_FIELD_NAME);
        $submit->setValue($language->text('frmquestions', 'attachments_take_save_label'));
        $this->addElement($submit);

        if (!OW::getRequest()->isAjax()) {
            OW::getLanguage()->addKeyForJs('frmquestions', 'feedback_question_empty');
            OW::getLanguage()->addKeyForJs('frmquestions', 'feedback_question_min_length');
            OW::getLanguage()->addKeyForJs('frmquestions', 'feedback_question_max_length');
            OW::getLanguage()->addKeyForJs('frmquestions', 'feedback_question_two_apt_required');
            OW::getLanguage()->addKeyForJs('frmquestions', 'feedback_question_dublicate_option');
            OW::getLanguage()->addKeyForJs('frmquestions', 'feedback_option_max_length');
        }
    }
}