<?php
// config.php

return [
    'clientId' => 'SBXID_009346',
    'clientSecret' => "81c1d6a7-5bcd-42db-a8c6-1e10d5d9face",
    'environment' => 'sbx',
    'gatewayBaseUrl' => 'https://dev.abdm.gov.in/api/hiecm',
    'createSessionPath' => '/gateway/v3/sessions',
	'useProxySettings' => false,
    'proxyHost' => '',
    'proxyPort' => 0,
    'connectionTimeout' => 3000,
    'responseTimeout' => 10,
	'callbackUrl' => 'https://coedai.com/interface/modules/custom_modules/oe-module-custom-skeleton/abdm_callback.php',   
    'hipId' => 'IN0910032981', 
	'Debug' => true,
	'tokenCacheFile' => __DIR__ . '/abdm_token_cache.json' 
];