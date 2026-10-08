<?php
// The dealer portal, for hosting it in the same folder as the CRM (portal.php). On its own subdomain,
// point the subdomain at this folder and set its address under Settings → Dealer portal instead.
define('CRM_PORTAL', true);
require dirname(__FILE__) . '/app/requirements.php';
require dirname(__FILE__) . '/app/index.php';
