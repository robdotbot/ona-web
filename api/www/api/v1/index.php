<?php
// Front controller for the ONA REST API (v1). All code lives outside the
// document root in ../../../rest; this file must stay at global scope so ONA's
// config.inc.php can set its globals ($conf, $self, $onadb).
require dirname(__DIR__, 3) . '/rest/bootstrap.php';
