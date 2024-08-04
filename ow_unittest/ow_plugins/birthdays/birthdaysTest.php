<?php
/**
 * User: Issa Moradnejad
 * Date: 2016/09/13
 */

class birthdaysTest extends FRMTestUtilites
{
    private $TEST_USER1_NAME = "user1";
    private $TEST_USER2_NAME = "user2";
    private $TEST_PASSWORD = '12345';

    private $userService;
    private $user1,$user2;

    protected function setUp()
    {
        parent::setUp();
        $this->checkRequiredPlugins(array('friends', 'birthdays'));
        ensure_session_active();
        $this->userService = BOL_UserService::getInstance();
        $accountType = BOL_QuestionService::getInstance()->getDefaultAccountType()->name;

        //+1 day
        $datetime = new DateTime();
        //$datetime = new DateTime($datetime->format('Y-m-d'));
        $datetime->modify('+3 day');
        $datetime->modify('-30 year');
        $birthday = $datetime->format('Y/m/d');

        FRMSecurityProvider::createUser($this->TEST_USER1_NAME,"user1@gmail.com",$this->TEST_PASSWORD,"1969/3/21","1",$accountType,'c0de');
        FRMSecurityProvider::createUser($this->TEST_USER2_NAME,"user2@gmail.com",$this->TEST_PASSWORD,$birthday,"1",$accountType,'c0de');
        $this->user1 = BOL_UserService::getInstance()->findByUsername($this->TEST_USER1_NAME);
        $this->user2 = BOL_UserService::getInstance()->findByUsername($this->TEST_USER2_NAME);
        // set some info to users

        $friendsQuestionService = FRIENDS_BOL_Service::getInstance();
        $friendsQuestionService->request($this->user1->getId(),$this->user2->getId());
        $friendsQuestionService->accept($this->user2->getId(),$this->user1->getId());


    }

    public function testBirthdays()
    {
        //----SCENARIO 1 -
        // User1 and User2 are friends
        // User2's birthday is tomorrow
        // User1 can see his birthday on the dashboard

        $test_caption = "birthdaysTest-testBirthdays";
        //$this->echoText($test_caption);
        $this->webDriver->prepare();
        $this->webDriver->maximizeWindow();

        $this->url(OW_URL_HOME . "dashboard");
        $sessionId = $this->webDriver->getCookie(OW_Session::getInstance()->getName())['value'];
        $sessionId = str_replace('%2C', ',', $sessionId);
        //----------USER1
        $this->sign_in($this->user1->getUsername(),$this->TEST_PASSWORD,true,true,$sessionId);
        try {
            $this->url(OW_URL_HOME . "dashboard");
            $this->hide_element('demo-nav');
            $res = $this->checkIfXPathExists('//*[contains(@class,"dashboard-BIRTHDAYS_CMP_FriendBirthdaysWidget")]');
            self::assertTrue($res);
            $res = $this->checkIfXPathExists('//*[contains(@class,"dashboard-BIRTHDAYS_CMP_FriendBirthdaysWidget")]//div[contains(@class,"ow_avatar")]');
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