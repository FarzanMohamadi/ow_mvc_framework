<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
        "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xml:lang="fa-IR" lang="fa-IR" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="keywords" content="{$page_kw|default:$_var.page.keywords}" />
    <meta name="description" content="{$page_desc|default:$_var.page.description}" />
    <title><?php echo $_assign_vars['pageTitle']; ?></title>
    <link rel="StyleSheet" type="text/css" href="<?php echo $_assign_vars['pageStylesheetUrl']; ?>" />
</head>

<body>
	<div class="st_wrapper">
	    <div class="wrapper">
	        <div class="body_wrapper">
	            <!--<div class="body_top">
	            <h1><?php echo $_assign_vars['pageHeading']; ?></h1>
	            </div>-->
	            <div class="body"> 
	                <div class="content">
	                    <div class="clearfix">
	                        <div class="logo_container">
	                        </div>
	                    </div>
	                   <?php echo $_assign_vars['pageBody']; ?>
	                </div>
	                <div class="body_bottom">
	               <?php echo $_assign_vars['pageSteps']; ?>
	            	</div>
	
				    <div class="footer">
				        <div class="footer_item">
				            <div class="frm_logo"></div>
				            <p>
								قدرت‌گرفته از موتوشاب
				            </p>
				            <div class="footer2">
				                <div class="footer_item">
				                    <p>
				                        مبتنی بر موتور متن‌باز اکسوال
				                    </p>
				                </div>
				                <div class="footer_item">
				                    <div class="oxwall_logo"></div>
				                </div>
				            </div>
				        </div>
				    </div>
	           </div>
	        </div>
	    </div>
	</div>
</body>
</html>