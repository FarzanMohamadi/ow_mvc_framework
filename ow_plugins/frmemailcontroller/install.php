<?php
$config = OW::getConfig();
if($config->configExists('frmemailcontroller', 'valid_email_services'))
{
    $config->deleteConfig('frmemailcontroller', 'valid_email_services');
}
if($config->configExists('frmemailcontroller', 'disable_frmemailcontroller'))
{
    $config->deleteConfig('frmemailcontroller', 'disable_frmemailcontroller');
}
if ( !$config->configExists('frmemailcontroller', 'valid_email_services'))
{
    $validEmailServices = array(
        'gmail.com', 'yahoo.com'
    );

    $config->addConfig('frmemailcontroller', 'valid_email_services',json_encode($validEmailServices));
}