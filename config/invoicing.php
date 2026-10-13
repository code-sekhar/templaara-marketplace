<?php

return ['disk' => env('INVOICE_PRIVATE_DISK', env('PRODUCT_ARTIFACT_DISK', 'product_private')), 'reconcile_limit' => 100];
