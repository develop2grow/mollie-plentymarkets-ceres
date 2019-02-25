<?php

use Mollie\Api\MollieApiClient;

try {
    $apiClient = new MollieApiClient();
    $apiClient->setApiKey(SdkRestApi::getParam('apiKey'));
    return $apiClient->methods->all(
        [
            'sequenceType'   => SdkRestApi::getParam('sequenceType'),
            'locale'         => SdkRestApi::getParam('locale'),
            'amount'         => SdkRestApi::getParam('amount'),
            'resource'       => SdkRestApi::getParam('resource'),
            'billingCountry' => SdkRestApi::getParam('billingCountry'),
        ]
    );
} catch (\Exception $e) {
    return ['error' => $e->getMessage()];
}
