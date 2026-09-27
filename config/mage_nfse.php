<?php

return [
    'homologacao' => 'http://sis-nfs-e.mage.rj.gov.br:8013/homologacao/webservices/NFEServices.jws',
    'producao' => 'https://nfs-e.mage.rj.gov.br/ver20240921/webservices/NFEServices.jws',
    'certificate' => env('MAGE_NFSE_CERTIFICATE'),
    'certificate_password' => env('MAGE_NFSE_CERTIFICATE_PASSWORD', ''),
];
