<?php
// Signable is no longer used: contracts are signed with the CRM's built-in e-signature.
// Kept so an old webhook address still answers cleanly.
http_response_code(410);
header('Content-Type: text/plain');
echo 'Gone';
