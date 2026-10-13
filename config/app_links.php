<?php

return [
    'ios_app_ids' => json_decode(env('KULSAH_IOS_APP_IDS', '[]'), true, flags: JSON_THROW_ON_ERROR),
    'android_targets' => json_decode(env('KULSAH_ANDROID_APP_TARGETS', '[]'), true, flags: JSON_THROW_ON_ERROR),
];
