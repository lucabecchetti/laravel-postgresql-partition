<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Year partition database name prefix
    |--------------------------------------------------------------------------
    |
    | Resolution order for the DB name prefix: (1) non-empty argument to
    | YearConnection::connection(..., $schemaPrefix) or model $yearSchemaPrefix;
    | (2) this config value when a non-empty string; (3) package default qualisys_.
    |
    */

    'schema_prefix' => null,

];
