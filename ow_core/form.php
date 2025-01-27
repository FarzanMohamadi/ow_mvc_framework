<?php
/**
 * Base form class.
 * 
 * @package ow_core
 * @since 1.0
 */
class Form
{
    const METHOD_POST = 'post';
    const METHOD_GET = 'get';
    const ENCTYPE_APP_FORM_URLENCODED = 'application/x-www-form-urlencoded';
    const ENCTYPE_MULTYPART_FORMDATA = 'multipart/form-data';
    const BIND_SUCCESS = 'success';
    const BIND_SUBMIT = 'submit';
    const AJAX_DATA_TYPE_JSON = 'json';
    const AJAX_DATA_TYPE_SCRIPT = 'script';
    const AJAX_DATA_TYPE_XML = 'xml';
    const AJAX_DATA_TYPE_HTML = 'html';
    /* -------------------------------------------------------------------------------------------------------------- */
    const ELEMENT_CSRF_TOKEN = "csrf_token";
    const ELEMENT_FORM_NAME = "form_name";
    const SAFE_RENDERING = "safe_rendering";
    /**
     * Form element attributes (id, name, etc).
     * 
     * @var array
     */
    protected $attributes = array();

    /**
     * Form elements list.
     *
     * @var array
     */
    protected $elements = array();

    /**
     * Form submit elements list <Submit/Button>.
     * 
     * @var array
     */
    protected $submitElements = array();

    /**
     * Form ajax flag.
     *
     * @var boolean
     */
    protected $ajax;

    /**
     * @var boolean
     */
    protected $ajaxResetOnSuccess;

    /**
     *
     * @var string
     */
    protected $ajaxDataType;

    /**
     * @var array
     */
    protected $bindedFunctions;

    /**
     * @var string
     */
    protected $emptyElementsErrorMessage;

    private $CSRF_hash_needed = false;

    /**
     * Constructor.
     * 
     * @param string $name
     */
    public function __construct( $name )
    {
        $this->setId(UTIL_HtmlTag::generateAutoId('form'));
        $this->setMethod(self::METHOD_POST);
        $this->setAction('');
        $this->setAjaxResetOnSuccess(true);
        $this->setAjaxDataType(self::AJAX_DATA_TYPE_JSON);
        $this->bindedFunctions = array(self::BIND_SUBMIT => array(), self::BIND_SUCCESS => array());
        $this->setEmptyElementsErrorMessage(OW::getLanguage()->text('base', 'form_validate_common_error_message'));

        $formNameHidden = new HiddenField(self::ELEMENT_FORM_NAME);
        $formNameHidden->setValue($name);
        $this->addElement($formNameHidden);

        $formNameHidden = new CsrfHiddenField(self::ELEMENT_CSRF_TOKEN);
        $csrf=UTIL_Csrf::generateToken();
        $formNameHidden->setValue($csrf);
        $this->addElement($formNameHidden);

        $this->setName($name);

        $event = OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.before.form.creation'));
        if(isset($event->getData()['add_CSRF_hash'])){
            $this->CSRF_hash_needed = $event->getData()['add_CSRF_hash'];
            $CSRF_HashField = new CsrfHiddenField('csrf_hash');
            $CSRF_HashField->setValue('SHOULD_BE_FILLED_ON_RENDER');
            $this->addElement($CSRF_HashField);
        }
    }

    /**
     * @return string
     */
    public function getId()
    {
        return isset($this->attributes['id']) ? $this->attributes['id'] : null;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return isset($this->attributes['name']) ? $this->attributes['name'] : null;
    }

    /**
     * @return string
     */
    public function getEmptyElementsErrorMessage()
    {
        return $this->emptyElementsErrorMessage;
    }

    /**
     * @param string $message
     * @return Form
     */
    public function setEmptyElementsErrorMessage( $message )
    {
        $this->emptyElementsErrorMessage = $message;
        return $this;
    }

    /**
     * Sets form `id` attribute.
     * 
     * @param string $id
     * @return Form
     */
    public function setId( $id )
    {
        $this->attributes['id'] = trim($id);
        return $this;
    }

    /**
     * Sets form `name` attribute.
     *
     * @param string $name
     * @return Form
     * @throws InvalidArgumentException
     */
    public function setName( $name )
    {
        if ( !$name )
        {
            throw new InvalidArgumentException('Invalid form name!');
        }

        $this->getElement('form_name')->setValue($name);
        $this->attributes['name'] = trim($name);

        return $this;
    }

    /***
     * @param $attr
     * @param $val
     * @return $this
     */
    public function setAttribute( $attr, $val )
    {
        $this->attributes[$attr] = $val;

        return $this;
    }

    /**
     * Sets form `action` url attribute.
     *
     * @param string $action
     * @return Form
     */
    public function setAction( $action )
    {
        $this->attributes['action'] = trim($action);

        return $this;
    }

    /**
     * @return string
     */
    public function getAction()
    {
        return isset($this->attributes['action']) ? $this->attributes['action'] : null;
    }

    /**
     * Sets form `method` attribute.
     *
     * @param string $method
     * @return Form
     */
    public function setMethod( $method )
    {
        if ( !in_array(trim($method), array(self::METHOD_GET, self::METHOD_POST)) )
        {
            throw new InvalidArgumentException('Invalid form method type!');
        }

        $this->attributes['method'] = trim($method);

        return $this;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return isset($this->attributes['method']) ? $this->attributes['method'] : null;
    }

    /**
     * Sets form `enctype` attribute.
     *
     * @param string $enctype
     * @return Form
     */
    public function setEnctype( $enctype )
    {
        if ( !in_array(trim($enctype), array(self::ENCTYPE_APP_FORM_URLENCODED, self::ENCTYPE_MULTYPART_FORMDATA)) )
        {
            throw new InvalidArgumentException('Invalid form enctype!');
        }

        $this->attributes['enctype'] = trim($enctype);

        return $this;
    }

    /**
     * @return string
     */
    public function getEnctype()
    {
        return isset($this->attributes['enctype']) ? $this->attributes['enctype'] : null;
    }

    /**
     * @param bool $ajax
     * @return $this
     */
    public function setAjax( $ajax = true )
    {
        $this->ajax = (bool) $ajax;

        return $this;
    }

    /**
     * Checks if form is ajax.
     *
     * @return boolean
     */
    public function isAjax()
    {
        return $this->ajax;
    }

    /**
     * @return boolean
     */
    public function getAjaxResetOnSuccess()
    {
        return $this->ajaxResetOnSuccess;
    }

    /**
     * @param boolean $resetForm
     * @return Form
     */
    public function setAjaxResetOnSuccess( $resetForm )
    {
        $this->ajaxResetOnSuccess = (bool) $resetForm;
        return $this;
    }

    /**
     * @return string
     */
    public function getAjaxDataType()
    {
        return $this->ajaxDataType;
    }

    /**
     * @param string $ajaxDataType
     * @return Form
     */
    public function setAjaxDataType( $ajaxDataType )
    {
        $this->ajaxDataType = trim($ajaxDataType);
        return $this;
    }

    /**
     * @param string $eventType
     * @param callback $function
     */
    public function bindJsFunction( $eventType, $function )
    {
        $this->bindedFunctions[$eventType][] = $function;
    }

    /**
     * Adds form element.
     *
     * @param mixed
     * @return Form
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws InvalidArgumentException
     */
    public function addElement( $element )
    {
        if ( !$element instanceof FormElement )
        {
            throw new InvalidArgumentException('Provided element is not instance of FormElement class!');
        }

        if ( $element->getName() === null )
        {
            throw new LogicException('Form element with empty name was added!');
        }

        if ( array_key_exists($element->getName(), $this->elements) )
        {
            throw new LogicException('Duplicated form element name! Form element with name `' . $element->getName() . '` already exists!');
        }

        if ( $element instanceof Submit )
        {
            $this->submitElements[$element->getName()] = $element;
        }
        else
        {
            $this->elements[$element->getName()] = $element;
        }
        $event = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::DISTINGUISH_REQUIRED_FIELD, array('element' => $element)));
        if(isset($event->getData()['distinguishedRequiredLabels'])){
           $element->setLabel($event->getData()['distinguishedRequiredLabels']);
        }

        return $this;
    }

    /**
     * Returns form element by name.
     *
     * @param string $name
     * @return FormElement
     */
    public function getElement( $name )
    {
        return empty($this->elements[$name]) ? null : $this->elements[$name];
    }

    public function deleteElement( $elementName )
    {
        if ( empty($elementName) )
        {
            return;
        }

        if ( array_key_exists($elementName, $this->elements) )
        {
            unset($this->elements[$elementName]);
        }
    }

    /**
     * Returns all form elements.
     *
     * @return array
     */
    public function getElements()
    {
        return $this->elements;
    }

    /**
     * Returns form submit element by name.
     *
     * @param string $name
     * @return FormElement
     * @throws InvalidArgumentException
     */
    public function getSubmitElement( $name )
    {
        if ( !$name || !isset($this->submitElements[$name]) )
        {
            throw new InvalidArgumentException('Cant find element with name `' . $name . '`!');
        }

        return $this->submitElements[$name];
    }

    /**
     * Validates added form elements. 
     *
     * @param array $data
     * @return boolean
     * @throws InvalidArgumentException
     */
    public function isValid( $data )
    {
        $valid = true;

        if ( !is_array($data) )
        {
            throw new InvalidArgumentException('Array should be provided for validation!');
        }

        //checking csrf hash
        $params = $_POST;
        $params['actionUrl'] = $this->getAction();
        $event = new OW_Event('frmsecurityessentials.after.form.submission', $params);
        OW::getEventManager()->trigger($event);
        if(isset($event->getData()['not_allowed'])){
            $valid = false;
            if(OW::getRequest()->isAjax()){
                exit(json_encode(array('result' => false, 'message' => OW::getLanguage()->text("base", "invalid_csrf_token_error_message"))));
            }else {
                //TODO refactor - remove message adding from Form class
                OW::getFeedback()->error(OW::getLanguage()->text("base", "invalid_csrf_token_error_message"));
            }
        }

        if ( $this->getElement(self::ELEMENT_CSRF_TOKEN) != null 
            && ( !isset($data[self::ELEMENT_CSRF_TOKEN]) || !UTIL_Csrf::isTokenValid($data[self::ELEMENT_CSRF_TOKEN] )) 
        )
        {
            $valid = false;
            if(OW::getRequest()->isAjax()){
                exit(json_encode(array('result' => false, 'message' => OW::getLanguage()->text("base", "invalid_csrf_token_error_message"))));
            }else {
                //TODO refactor - remove message adding from Form class
                OW::getFeedback()->error(OW::getLanguage()->text("base", "invalid_csrf_token_error_message"));
            }
        }

        /* @var $element FormElement */
        foreach ( $this->elements as $element )
        {
            if ( ($element instanceof TextField || ($element instanceof Textarea && (!$element instanceof MobileWysiwygTextarea)) || $element instanceof TagsInputField)
                && !OW::getUser()->isAdmin()) {
                if ($element->getAttribute(self::SAFE_RENDERING) != true) {
                    $element->setValue((isset($data[$element->getName()]) ? UTIL_HtmlTag::stripTagsAndJs($data[$element->getName()]) : null));
                } else {
                    $element->setValue((isset($data[$element->getName()]) ? UTIL_HtmlTag::stripJs($data[$element->getName()]) : null));
                }
            }else{
                $element->setValue((isset($data[$element->getName()]) ? $data[$element->getName()] : null));
            }
            $eventData = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_BEFORE_VALIDATING_FIELD, array('field' => $element, 'data' => $data, 'files' => $_FILES)));
            if($eventData->getData() && isset($eventData->getData()['from']) && isset($eventData->getData()['to']))
            {
                $element->setValue(array('from' => $eventData->getData()['from'], 'to' =>$eventData->getData()['to'] ));
            }
            if($eventData->getData() && isset($eventData->getData()['validRequired']) && $eventData->getData()['validRequired'] == true){
               $element->setValue(true);
            }
            if ( !$element->isValid() )
            {
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Returns form element values.
     *
     * @return array
     */
    public function getValues()
    {
        $values = array();

        /* @var $element FormElement */
        foreach ( $this->elements as $element )
        {
            $values[$element->getName()] = $element->getValue();
        }

        return $values;
    }

    /**
     * Sets form element values.
     * 
     * @param array $values
     */
    public function setValues( array $values )
    {
        /* @var $element FormElement */
        foreach ( $this->elements as $element )
        {
            if ( isset($values[$element->getName()]) )
            {
                $element->setValue($values[$element->getName()]);
            }
        }
    }

    /**
     * Returns errors array for all form elements.
     *
     * @return array
     */
    public function getErrors()
    {
        $errors = array();

        /* @var $value FormElement */
        foreach ( $this->elements as $key => $value )
        {
            $errors[$key] = $value->getErrors();
        }

        return $errors;
    }

    /**
     * Resets all form elements values.
     *
     * @return Form
     */
    public function reset()
    {
        /* @var $element FormElement */
        foreach ( $this->elements as $element )
        {
            //TODO remove temp hardcode to avoid token reset
            if( $element->getName()  != self::ELEMENT_CSRF_TOKEN )
            {
                $element->setValue(null);
            }
        }

        return $this;
    }

    /**
     * Returns rendered HTML code of form object.
     *
     * @param string $formContent
     * @param array $params
     * @return string
     */
    public function render( $formContent, array $params = array() )
    {
        if($this->getMethod() == self::METHOD_GET){
            $this->deleteElement(self::ELEMENT_CSRF_TOKEN);
        }else{
            if($this->CSRF_hash_needed && OW::getPluginManager()->isPluginActive('frmsecurityessentials')) {
                $csrf_hash_el_val = FRMSECURITYESSENTIALS_BOL_Service::getInstance()->refresh_csrf_hash($this->elements, $this->getAction());
                if(isset($csrf_hash_el_val) && $csrf_hash_el_val!=""  && $csrf_hash_el_val!=null)
                    $this->elements['csrf_hash']->setValue($csrf_hash_el_val);
            }
        }

        $formElementJS = '';

        /* @var $element FormElement */
        foreach ( $this->elements as $element )
        {
            $formElementJS .= $element->getElementJs() . PHP_EOL;
            $formElementJS .= "form.addElement(formElement);" . PHP_EOL;
        }

        $formInitParams = array(
            'id' => $this->getId(),
            'name' => $this->getName(),
            'reset' => $this->getAjaxResetOnSuccess(),
            'ajax' => $this->isAjax(),
            'ajaxDataType' => $this->getAjaxDataType(),
            'validateErrorMessage' => $this->emptyElementsErrorMessage,
        );

        $jsString = " var form = new OwForm(" . json_encode($formInitParams) . ");window.owForms[form.name] = form;
			" . PHP_EOL . $formElementJS . "

			if ( form.form ) 
			{
    			$(form.form).bind( 'submit', {form:form},
    					function(e){
    						return e.data.form.submitForm();
    					}
    			);
                        }
                        
                        OW.trigger('base.onFormReady.' + form.name, [], form);
                        OW.trigger('base.onFormReady', [form]);
		";

        foreach ( $this->bindedFunctions as $bindType => $binds )
        {
            if ( empty($binds) )
            {
                continue;
            }

            foreach ( $binds as $function )
            {
                $jsString .= "form.bind('" . trim($bindType) . "', " . $function . ");";
            }
        }

        OW::getDocument()->addOnloadScript($jsString, 10);

        $hasConditions = false;
        $hiddenFieldString = '';

        /* @var $value FormElement */
        foreach ( $this->elements as $value )
        {
            if ( $value instanceof HiddenField )
            {
                $hiddenFieldString .= $value->renderInput() . PHP_EOL;
            }
            else {
                $condition = $value->getAttribute('condition');
                if (isset($condition)) {
                    $parts = explode('::', $condition);
                    OW::getDocument()->addOnloadScript('question_with_condition("'.$parts[0] . '","' . $parts[1] . '","' . $parts[2] . '");');
                    $hasConditions = true;
                }
            }
        }

        return UTIL_HtmlTag::generateTag('form', array_merge($this->attributes, $params), true,
                PHP_EOL . $hiddenFieldString . $formContent . PHP_EOL);
    }

}
