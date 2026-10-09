<?php
// The customer portal, for hosting it in the same folder as the CRM (account.php). On its own subdomain,
// point the subdomain at this folder and set its address under Settings → Customer portal instead.
define('CRM_CUSTOMER_PORTAL', true);
require dirname(__FILE__) . '/app/requirements.php';
require dirname(__FILE__) . '/app/index.php';
