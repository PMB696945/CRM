<?php
// Fallback for servers without mod_rewrite: send visitors to the app in public/.
header('Location: public/', true, 302);
