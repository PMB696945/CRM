<?php
// When the whole CRM folder sits inside the web root, send visitors to the app.
header('Location: public/', true, 302);
