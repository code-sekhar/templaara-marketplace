<?php

return ['attachment_disk' => env('SUPPORT_ATTACHMENT_DISK', env('PRODUCT_ARTIFACT_DISK', 'product_private')), 'attachment_max_kb' => (int) env('SUPPORT_ATTACHMENT_MAX_KB', 10240), 'attachments_max' => (int) env('SUPPORT_ATTACHMENTS_MAX', 5)];
