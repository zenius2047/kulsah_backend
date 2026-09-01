<?php

namespace App\Enums;

enum VideoUploadStatus: string
{
    case Initialized = 'initialized';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case UploadFailed = 'upload_failed';
}
