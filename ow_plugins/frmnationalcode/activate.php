<?php
$question = new BOL_Question();
$question->name = 'field_national_code';
$question->required = true;
$question->onJoin = true;
$question->onEdit = true;
$question->onSearch = false;
$question->onView = false;
$question->presentation = 'text';
$question->type = 'text';
$question->columnCount = 0;
$question->sectionName = 'f90cde5913235d172603cc4e7b9726e3';
$question->sortOrder = ( (int) BOL_QuestionService::getInstance()->findLastQuestionOrder($question->sectionName) ) + 1;
$question->custom = json_encode(array());
$question->removable = false;
$questionValues = false;
$name = OW::getLanguage()->text('frmnationalcode', 'field_national_code_label');
$description = OW::getLanguage()->text('frmnationalcode', 'field_national_code_description');
BOL_QuestionService::getInstance()->createQuestion($question, $name, $description, $questionValues, true);
BOL_QuestionService::getInstance()->addQuestionToAccountType('field_national_code', array('290365aadde35a97f11207ca7e4279cc'));