<?php
return [
    /////'gatewayBaseUrl' => 'https://dev.abdm.gov.in/api/hiecm',

    // HIP Setup
    'hipBaseUrl' => 'https://coedai.com/interface/modules/custom_modules/Oe-module-custom-skeleton/',
    'bridgeURL' => '/api/hiecm/gateway/v3/bridge',
    // HIP endpoints TODO
    'getPatientPath' => '/v3/patients',
    'patientDiscoverPath' => '/v3/patient-discover',
    'getPatientCareContextsPath' => '/v3/patient-care-contexts',
    'getHealthInformationPath' => '/v3/health-information',
    'shareProfilePath' => '/v3/profile/share',
    'requestOtpPath' => '/v3/request/otp',
    'verifyOtpPath' => '/v3/verify/otp',
////https://dev.abdm.gov.in/api/hiecm/v3/token/generate-token
    // HIP-Initiated Linking
    'generateLinkTokenPath' => '/v3/token/generate-token',
    'linkCareContextsPath' => '/hip/v3/link/carecontext',
    'linkContextNotifyPath' => '/hip/v3/link/context/notify',
    'deepLinkingSMSNotifyPath' => '/hip/v3/link/patient/links/sms/notify2',

    // User-Initiated Linking
    'onDiscoverPath' => '/user-initiated-linking/v3/patient/care-context/on-discover',
    'onInitLinkPath' => '/user-initiated-linking/v3/link/care-context/on-init',
    'onConfirmLinkPath' => '/user-initiated-linking/v3/link/care-context/on-confirm',

    // Session
    /////'createSessionPath' => '/gateway/v3/sessions',

    // Data Transfer and Consent
    'consentOnNotifyPath' => '/consent/v3/request/hip/on-notify',
    'healthInformationOnRequestPath' => '/data-flow/v3/health-information/hip/on-request',
	//https://dev.abdm.gov.in/api-hiu/data/notification
    'healthInformationPushNotificationPath' => '/data-flow/v3/health-information/notify',
    'consentInitPath' => '/consent/v3/request/init',
    'consentStatusPath' => '/consent/v3/request/status',
    'consentHiuOnNotifyPath' => '/consent/v3/request/hiu/on-notify',
    'fetchConsentPath' => '/consent/v3/fetch',
    'healthInformationConsentManagerPath' => '/data-flow/v3/health-information/request',

];