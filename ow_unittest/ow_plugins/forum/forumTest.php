<?php
/**
 * User: Issa Moradnejad
 * Date: 2016/09/11
 */

class forumTest extends FRMTestUtilites
{
    private $TEST_USER1_NAME = "user1";
    private $TEST_USER2_NAME = "user2";
    private $TEST_PASSWORD = '12345';

    private $userService;
    private $user1,$user2;

    protected function setUp()
    {
        parent::setUp();
        $this->checkRequiredPlugins(array('forum'));
        ensure_session_active();
        $this->userService = BOL_UserService::getInstance();
        $accountType = BOL_QuestionService::getInstance()->getDefaultAccountType()->name;

        FRMSecurityProvider::createUser($this->TEST_USER1_NAME,"user1@gmail.com",$this->TEST_PASSWORD,"1969/3/21","1",$accountType,'c0de');
        FRMSecurityProvider::createUser($this->TEST_USER2_NAME,"user2@gmail.com",$this->TEST_PASSWORD,"1969/3/21","1",$accountType,'c0de');
        $this->user1 = BOL_UserService::getInstance()->findByUsername($this->TEST_USER1_NAME);
        $this->user2 = BOL_UserService::getInstance()->findByUsername($this->TEST_USER2_NAME);

    }

    public function testForum1()
    {
        //----SCENARIO 1 -
        // User1 can see forum.
        // User1 can create new topic.

        $test_caption = "forumTest-testForum1";
        //$this->echoText($test_caption);
        $this->webDriver->prepare();
        $this->webDriver->maximizeWindow();

        $this->url(OW_URL_HOME . "dashboard");
        $sessionId = $this->webDriver->getCookie(OW_Session::getInstance()->getName())['value'];
        $sessionId = str_replace('%2C', ',', $sessionId);
        //----------USER1
        $this->sign_in($this->user1->getUsername(),$this->TEST_PASSWORD,true,true,$sessionId);
        try {
            $this->url(OW_URL_HOME . "forum/1");
            $this->hide_element('demo-nav');
            $this->byClassName("btn_add_topic")->click();
            $this->hide_element('demo-nav');
            $res = $this->checkIfXPathExists('//input[@name="title"]');
            self::assertTrue($res);
            $res = $this->checkIfXPathExists('//textarea[@name="text"]');
            self::assertTrue($res);
            $res = $this->checkIfXPathExists('//input[@type="submit"]');
            self::assertTrue($res);
        } catch (Exception $ex) {
            $this->handleException($ex,$test_caption,true);
        }
    }


    public function tearDown()
    {
        if($this->isSkipped)
            return;

        //delete users
        FRMSecurityProvider::deleteUser($this->user1->getUsername());
        FRMSecurityProvider::deleteUser($this->user2->getUsername());
        parent::tearDown();
    }
}