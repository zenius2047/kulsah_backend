<?php

namespace App\Enums;

enum VideoProcessingStatus: string
{
    case Initialized = 'initialized';
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case ProcessingFailed = 'processing_failed';
}
