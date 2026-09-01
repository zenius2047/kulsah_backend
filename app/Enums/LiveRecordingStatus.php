<?php

namespace App\Enums;

enum LiveRecordingStatus: string
{
    case REQUESTED = 'requested';
    case RECORDING = 'recording';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';
    case DELETED = 'deleted';
}
