<?php
$languageService = Updater::getLanguageService();

$languageService->addOrUpdateValueByLanguageTag('fa-IR', 'frmnewsfeedplus', 'forward_to_user', 'ارسال به کاربر');
$languageService->addOrUpdateValueByLanguageTag('en', 'frmnewsfeedplus', 'forward_to_user', 'forward to user');

$languageService->addOrUpdateValueByLanguageTag('fa-IR', 'frmnewsfeedplus', 'users_forward_success_message', 'مطلب برای {$count} کاربر ارسال شد');
$languageService->addOrUpdateValueByLanguageTag('en', 'frmnewsfeedplus', 'users_forward_success_message', 'newsfeed has been sent for {$count} user(s)');


