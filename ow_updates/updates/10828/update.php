<?php
$tblPrefix = OW_DB_PREFIX;

$dbo = Updater::getDbo();
$logger = Updater::getLogger();

try
{
    if(!defined('BACKUP_TABLES_USING_TRIGGER') || BACKUP_TABLES_USING_TRIGGER == true) {
        $dropTableDontNeedBackupQuery = 'DROP TABLE IF EXISTS ' . FRMSecurityProvider::getTableBackupName(OW_DB_PREFIX . 'base_login_cookie');
        $dbo->query($dropTableDontNeedBackupQuery);

        $dropRemoveTriggerOfTableDontNeedBackupQuery = 'DROP TRIGGER IF EXISTS ' . FRMSecurityProvider::$removeTriggerNameBackupTable . OW_DB_PREFIX . 'base_login_cookie';
        $dbo->query($dropRemoveTriggerOfTableDontNeedBackupQuery);

        $dropUpdateTriggerOfTableDontNeedBackupQuery = 'DROP TRIGGER IF EXISTS ' . FRMSecurityProvider::$updateTriggerNameBackupTable . OW_DB_PREFIX . 'base_login_cookie';
        $dbo->query($dropUpdateTriggerOfTableDontNeedBackupQuery);

        $Query = 'ALTER TABLE `' . FRMSecurityProvider::getTableBackupName(OW_DB_PREFIX . 'frmmobilesupport_device') . '` CHANGE COLUMN `type` `type` VARCHAR(30) NOT NULL DEFAULT \'1\' AFTER `time`;';
        $dbo->query($Query);

        $Query = 'ALTER TABLE `' . FRMSecurityProvider::getTableBackupName(OW_DB_PREFIX . 'frmmobilesupport_device') . '` CHANGE COLUMN `cookie` `cookie` VARCHAR(255) NOT NULL DEFAULT \'1\' AFTER `type`;';
        $dbo->query($Query);
    }
}
catch (Exception $e)
{
    $logger->addEntry(json_encode($e));
}