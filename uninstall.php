<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/crm-connect.php';

CrmConnect\Database\Schema::uninstall();
