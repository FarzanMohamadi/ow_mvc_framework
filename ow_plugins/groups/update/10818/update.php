<?php
$languageService = Updater::getLanguageService();

$languageService->addOrUpdateValueByLanguageTag('fa-IR', 'groups', 'email_notification_comment', '<a href="{$userUrl}">{$userName}</a> یک نظر در گروه <a href="{$url}">{$title} </a>اضافه کرد.');
$languageService->addOrUpdateValueByLanguageTag('en', 'groups', 'email_notification_comment', '<a href="{$userUrl}">{$userName}</a> added a comment in <a href="{$url}">{$title}</a>');